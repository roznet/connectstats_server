# Droplet Migration

> Move ConnectStats off the Ubuntu 20.04 / PHP 7.4 droplet onto a new droplet running Docker (app + MySQL), after pruning old data. This is the tracking doc: update the checklists and the progress log as work lands so a new session can pick up from here.

## Why

- The `connectstats.app` droplet runs Ubuntu 20.04 + PHP 7.4 + MySQL 8.0, past standard support and patched only through Ubuntu Pro ESM (an emergency PHP RCE fix in Oct 2026 depended on it).
- Its OpenSSH 8.2 can't do post-quantum key exchange, so modern clients warn on every connection.
- Garmin retires OAuth 1.0 on **31 Dec 2026** ([oauth2-migration](oauth2-migration.md)). That work should be built and validated once, on the final stack.

Code-level PHP 8 findings and fixes are in [php-upgrade](php-upgrade.md). That doc assumed PHP from Ubuntu packages; **Docker supersedes its "Server build" section**, the rest still applies.

## Decisions

| Decision | Choice | Notes |
|---|---|---|
| New droplet vs in-place upgrade | **New droplet** | Side-by-side testing, DNS cutover, old box stays as rollback |
| Consolidate onto main droplet (`flyfun.aero`)? | **No** | Main droplet: root disk 82% full (12 GB free), memory peaks 82%, load spikes to 22. ConnectStats DB alone doesn't fit |
| Packaging | **Docker Compose**: `app` (php-apache), `worker` (same image, queue + cron jobs), `db` (MySQL 8.4) | PHP version pinned in the Dockerfile, independent of the OS. Same compose runs locally for tests. Security fixes come from rebuilding images, not `apt`, so this needs a routine |
| Droplet size | **2 vCPU / 4 GB** (same CPU/RAM as today), smallest root disk that fits | Root disk only holds OS, Docker images and logs; data is on the Volume. See measurements below |
| Data storage | **DigitalOcean Volume** mounted at `/mnt/connectstats_data` (same pattern as the main droplet's MySQL) | MySQL datadir, bug report uploads, `s3_cache_local`, `state`. Resizable online and cheap per GB, unlike the root disk (which needs a droplet resize). A future move is detach/attach. Not covered by droplet backups: needs its own backup (Phase 3) |
| Database sync | **Replication**: old MySQL 8.0 as source → new 8.4 as replica, seeded from one dump | No prod downtime to seed; cutover is seconds instead of a dump/restore freeze, so Garmin webhooks aren't lost. Fallback: dump/restore during a freeze if replication fails. See Phase 4 |
| Region | _open_ | Today nyc1; main droplet is lon1. **Decide before creating the Volume**: a Volume only attaches to droplets in its own region. Replication works across regions either way |
| Retention for active users | _open_ | 85% of DB is the full history (since 2019) of active users. Pruning it means a reinstalled app can't re-download old activities |
| Deregister dormant users with Garmin | _open_ | Garmin keeps pushing activities for users idle >1 year; the monthly cleanup deletes them again. Deregistering stops the inflow and leaves ~3.5k users to move to OAuth 2.0 instead of ~32k |
| Prune empty weather rows | _open_ | ~470k of 1.84M weather rows are placeholders or errors (see Phase 1) |

## Current State (measured 2026-11-01)

**Droplet** `connectstats.app`: s-2vcpu-4gb-120gb-intel, nyc1. Apache 2.4 + mod_php 7.4, MySQL 8.0.46.

| Resource | Usage |
|---|---|
| CPU | avg ~4%, short peaks ~60% (cron jobs) |
| Memory | avg 1.4 GB, peak 1.7 GB (30 days) |
| Load | peak 1.9 on 2 vCPU |
| Network | < 0.5 Mbps |
| Disk | 40 GB of 116 GB |

**Data to move**

| Item | Size | Notes |
|---|---|---|
| `garmin` DB | 13.1 GB, 22.7M rows | `activities` 3.6 GB, `weather` 3.6 GB, `fitsession` 1.9 GB, `fitfiles` 1.1 GB, `assets` 0.9 GB, cache tables ~0.9 GB |
| `queue` DB | 264 MB | Mostly old `tasks` rows |
| `garmin_dev` / `connectstats` DBs | 56 MB / 8 MB | |
| `/var/www/bugs` | 3.9 GB | Bug report uploads |
| FIT files | in S3 (prod bucket) | Not moved |
| Not worth moving | `/var/www/log` 3.1 GB, `/var/www/tmp` 0.7 GB, `/var/log` 4.4 GB | |

**Who owns the data** (after the first inactive-user cleanup, 2026-11-01)

| Owner | Users | activities | weather | fitsession |
|---|---|---|---|---|
| Used app < 90 days | 2,672 | 2.83M | 1.64M | 2.20M |
| Idle 90–180 days | 836 | 503k | 202k | 274k |
| Idle > 180 days / never | 28.6k | ~1k (arrived after cleanup) | — | — |
| NULL `cs_user_id` / user row gone | — | 34k | — | — |

## Phases

### Phase 0: Bring the current droplet up to master

- [x] Commit missing `api/lib/Auth.php` (`0420f5c`). Dev at `/var/www/html/dev` was failing without it
- [x] Pull dev on the droplet
- [x] Reset `prod` branch to master (force push, 2026-11-01) and `git reset --hard origin/prod` in `/var/www/html/prod`. Old prod head was `4efc6ea` (same phpliteadmin fix as master's `30b6cb5`)
- [x] Commit the cleanup fixes (`6fb2e20`) and deploy to dev (2026-11-01, dry runs OK on the dev DB)
- [x] Deploy to prod (2026-11-01, `prod` fast-forwarded to `1114cb0`; tests and dry runs same as before the update)
- [x] Update the cron wrapper on the droplet (2026-11-01); `--dry-run` confirms `--include-orphans` now reaches the PHP script. Also applied pending server hardening: `session.upload_progress` off in Apache's PHP (verified inside Apache), Apache logs kept 90 days
- [ ] Remove stale GitHub entries from the droplet's `~/.ssh/known_hosts` (see Gotchas)

### Phase 1: Prune data before the move

**Cleanup bugs found and fixed (deployed to dev and prod 2026-11-01):**

1. The droplet's cron wrapper `/var/www/bin/cleanup_inactive_users.zsh` built options in a string; zsh passed it as one argument, so `--include-orphans` was silently ignored. Now an array.
2. `cleanup_inactive_users.php` read S3 paths from `assets_s3.s3path`, a column that doesn't exist (and `assets_s3` is empty). The query failed silently, so S3 files and `assets` rows were never deleted. Now reads `assets.path` (`s3:assets/users/{cs_user_id}/fit/{file_id}.fit`) through the new `GarminProcess::delete_from_s3_bucket()`, and deletes the `assets` rows.
3. Both cleanup scripts created their own `S3` client without v4 signing or region; AWS rejects v2 (`Please use AWS4-HMAC-SHA256`). Now use `GarminProcess::s3()`. The monthly `cleanup_orphan_s3.php` failed on every call for 74 minutes on 2026-11-01.
4. "Space reclaimed" read MySQL's cached `information_schema` sizes (24 h expiry), so it always showed 0 MB saved. Now sets `information_schema_stats_expiry = 0`.
5. Runtime 1.7 h: each run re-processed all 28.6k inactive users, and `fitfiles`, `weather`, `fitsession` have **no index on `cs_user_id`**, so each batch was 3 full table scans. Now only inactive users that still have data are processed (dry run: 70 s, 1,046 users).
6. New `--orphan-assets [--limit=N]` deletes `assets` rows (and S3 files) whose `fitfiles` row is gone: **4.74M of 7.3M** rows.
7. `assets_s3` doesn't exist on prod (it's in `ensure_schema` but was never created there); the cleanup no longer touches it.

**Pruning tasks:**

- [ ] Deploy fixes, run `cleanup_inactive_users.php --days=180 --include-orphans` (34k NULL-user rows)
- [ ] Orphan assets: run `--orphan-assets --limit=...` in chunks. S3 deletes are one HTTP call per object, too slow for 4.7M objects (~days). Either run DB-only with `--skip-s3` and handle S3 by prefix (below), or add S3 multi-object delete (`POST ?delete`, 1000 keys per call) to `S3.php`
- [x] Measure the S3 bucket (2026-11-01, from CloudWatch S3 metrics). Prod bucket: **872.5 GB, 7.32M objects**, all Standard storage, ~119 KB per file; was 775 GB / 6.72M a year earlier. That's about one object per `assets` row, so the 4.74M orphaned assets are ~65% of the bucket (~560 GB). Dev bucket: 346 MB, 2.6k objects
- [x] Bulk delete: added `S3::deleteObjects()` (S3 `DeleteObjects`, 1,000 keys per request, local addition to the vendored class) and `GarminProcess::delete_many_from_s3_bucket()`. Both cleanup scripts use it. Rows whose file fails to delete are kept; an S3 request failure stops the run
- [x] `cleanup_orphan_s3.php`: bulk deletes plus `--max-seconds` (default 3 h; a full pass of ~29k user listings is ~75 min)
- [x] `cleanup_inactive_users.php --skip-inactive --orphan-assets --limit=N` runs only the backlog. Tested 2026-11-01 on prod: 5 oldest orphans (two users inactive since 2023 or earlier, no fitfiles left) deleted from S3 and `assets`, verified gone
- [ ] Run the backlog: `cleanup_inactive_users.php --skip-inactive --orphan-assets --limit=4800000 --skip-optimize`, then `OPTIMIZE TABLE assets` (~4.7k S3 requests)
- [ ] Weather (pending decision): delete Visual Crossing `"info":"No data available"` placeholders (~450k since Apr 2023) and error rows (~15k Dark Sky errors, ~3k `403`, ~1k `[]`). Dark Sky rows (587k, until 2023-04-01) are real data but large (~2.5 KB each)
- [ ] Weather placeholders are 40–45% of new rows and each costs a Visual Crossing query: check whether fetching right after upload is too early
- [ ] Trim `queue.tasks` (1.6M rows), review `usage_summary` (137 MB), `notifications` (104 MB)
- [ ] Decide retention and Garmin deregistration (see Decisions)
- [ ] Consider adding `cs_user_id` indexes to `fitfiles`, `weather`, `fitsession` (schema v9) on the new stack
- [ ] Investigate `file.php` failures: 170–460 requests a day get `status=1` in the `usage` table (2,421 of ~26.7k in the week to 2026-11-01, predating the cleanup deploy). Likely files that no longer exist

### Phase 2: Code on PHP 8

See [php-upgrade](php-upgrade.md) tasks P0/P1. Key item: `mysqli_report(MYSQLI_REPORT_OFF)` in `sql_helper.php`, otherwise Garmin webhooks return 500 on PHP 8.1+.

- [ ] P0 fixes, run `setup/test.py` on PHP 8.x locally (in the Docker image once Phase 3 exists)
- [ ] P1 deprecations (dynamic properties, `strftime`, vendored `S3.php` / `phpFITFileAnalysis.php`)

### Phase 3: Docker

- [ ] `Dockerfile`: `php:8.x-apache` + `mysqli`, `curl`, `zip`, `mbstring`; `a2enmod rewrite`; `AllowOverride All` (the `.htaccess` maps `/foo` → `foo.php`). Keep mod_php so `apache_request_headers()['Authorization']` keeps working (php-fpm needs `CGIPassAuth On`)
- [ ] `docker-compose.yml`: `app`, `worker` (queue workers started via `exec('php ...')`, plus cron jobs), `db` (MySQL 8.4)
- [ ] DigitalOcean Volume (region decided first), mounted at `/mnt/connectstats_data` via `/etc/fstab` with `nofail`. Layout: `mysql/` (datadir), `bugs/`, `s3_cache_local/`, `state/`. Start at ~50 GB and grow when needed
- [ ] Compose takes the path from `.env` (`DATA_DIR=/mnt/connectstats_data`, defaulting to `./data` locally) instead of hardcoding it, so the same file runs on a Mac
- [ ] Bind mounts: `${DATA_DIR}/mysql` → `/var/lib/mysql`, `${DATA_DIR}/bugs` → bug report uploads, etc. Logs and tmp stay on the root disk. Code and config come from git, secrets from `.env`
- [ ] MySQL settings in git (compose `command:` or a mounted `my.cnf`), never `SET PERSIST`: persisted values live only in the datadir and silently revert to defaults if it's rebuilt. Start with `innodb_buffer_pool_size` ~1.5–2 GB (stock 128 MB; DB is ~13 GB, box has 4 GB), a `server_id` other than 1, `log_bin` on
- [ ] App MySQL user created by an init script with `caching_sha2_password` (8.4 disables `mysql_native_password` by default; PHP 8 `mysqli` supports it). Users and grants are not in the dump
- [ ] Backups: droplet backups skip Volumes. Nightly `mysqldump --single-transaction` copied off the box, plus Volume snapshots (scheduled, or before risky changes)
- [ ] Cron: port the droplet's cron jobs and `/var/www/bin/*.zsh` wrappers to run inside `worker` or as `docker compose exec`
- [ ] TLS: Caddy in front (as on the main droplet) or certbot; today it's acme.sh in brice's crontab
- [ ] Image update routine (rebuild/pull for PHP and MySQL security fixes)
- [ ] Same compose locally for the test suite

### Phase 4: Data migration

- [ ] Inventory: `config.php` (dev + prod), bugreport config, crontabs, Apache vhost, `/var/www/bin`, `/var/www/state`, APNs and S3 credentials, Visual Crossing key
**Database: seed once, then replicate. Prod keeps running throughout.**

Old box checked 2026-11-01: `log_bin=1`, `binlog_format=ROW`, binlogs kept 30 days, `server_id=1`, GTID off (so file/position replication). All tables InnoDB except one MyISAM table in `connectstats`. The app's MySQL user has no `RELOAD` / `REPLICATION` privileges: the seed dump and the replication user need MySQL root on the old box.

- [ ] Prune first (Phase 1) so the seed is smaller
- [ ] On the old box (as MySQL root): create a replication user (`REPLICATION SLAVE` only, `caching_sha2_password`, limited to the new box's IP)
- [ ] Seed: `mysqldump --single-transaction --source-data=2 --routines --triggers --hex-blob --databases garmin queue connectstats garmin_dev`, compressed and streamed to the new box's Volume. `--single-transaction` reads a consistent InnoDB snapshot without blocking writes; `--source-data` takes a global read lock only for the instant it records the binlog position (it waits for running queries, so avoid cron windows). The MyISAM table isn't in the snapshot, but it's tiny and replication brings it up to date
- [ ] Restore into MySQL 8.4 and time it (this is the fallback's freeze length). Check charset (`SET NAMES 'utf8'` → utf8mb3 warnings) and collations
- [ ] Start replication from the binlog position in the dump header (`CHANGE REPLICATION SOURCE TO ... SOURCE_LOG_FILE, SOURCE_LOG_POS, SOURCE_SSL=1`, or over an SSH tunnel). Open 3306 on the old box only to the new box's IP. Must start within the 30-day binlog retention
- [ ] Keep the replica `super_read_only` until cutover. Watch `SHOW REPLICA STATUS` (`Seconds_Behind_Source`, errors)
- [ ] If replication breaks (e.g. 8.0 → 8.4 incompatibility), re-seed or fall back to dump/restore during a freeze

**Files**

- [ ] rsync `/var/www/bugs` to the Volume now, again just before cutover (only the delta)
- [ ] FIT files stay in S3, nothing to move

### Phase 5: Parallel test of the new droplet

- [ ] Bring it up under a test hostname. **Don't write to the replica**: test against a separate restored copy (another schema on the new MySQL, or a second `db` container from the same seed dump), or accept re-seeding the replica afterwards
- [ ] `setup/test.py all` (non-destructive) against it; `full` against a dev DB
- [ ] Real device: login, sync, FIT download, notifications, bug report upload
- [ ] Manual checks not covered by tests: APNs, S3 upload, weather fetch, `sync`/`backup` system-auth endpoints, maintenance and cleanup jobs

### Phase 6: Cutover

- [ ] Lower DNS TTL a day ahead
- [ ] Stop the queue and cron jobs on the old box; final rsync of `/var/www/bugs`
- [ ] Old Apache reverse-proxies `/prod/api/` to the new box (from here, no writes reach the old DB)
- [ ] Wait for the replica to catch up (`Seconds_Behind_Source = 0`, executed position = old box's `SHOW BINARY LOG STATUS`), then `STOP REPLICA; RESET REPLICA ALL`, turn off `super_read_only`, start queue and cron on the new box
- [ ] Switch DNS. The proxy keeps covering clients and Garmin until it propagates
- [ ] Keep the old droplet at least two weeks; rollback = reverse replication (new → old) or replay rows received on the new box, then DNS back

## Releasing and Testing Prod

- `prod` is a pointer to a commit on master, never committed to directly. Release with `git push origin master:prod` (no `--force`, so a non-fast-forward fails), then `git pull --ff-only` in `/var/www/html/prod`.
- Tests run on the droplet (they read token secrets from the DB, which only accepts local connections). The droplet has Python 3.8 and the test code uses 3.9 syntax, so run from a scratch copy: copy `setup/` to `~/tmp/cstest/`, symlink `~/tmp/cstest/api` to the checkout's `api/`, prepend `from __future__ import annotations` to `test.py` and `connectstats_test/*.py`, then `python3 test.py all --base-url https://connectstats.app/prod`.
- Expected on prod: smoke 7/7, schema 41/43. The two failures are expected: no `dev` marker table (that's the `reset_schema()` safety lock) and no `assets_s3` table.
- Then dry-run both cleanup scripts, check Apache's error log, and do a refresh from a real device (check the `usage` table for its requests).

## Gotchas

- **Droplet git pulls fail intermittently** with "Host key verification failed": `~/.ssh/known_hosts` has GitHub's pre-2023 RSA key (`SHA256:nThbg6kX…`) under some GitHub IPs (lines 1, 2, 5). With `CheckHostIP yes`, ssh prompts when DNS returns one of those IPs and there's no tty. Workaround: `GIT_SSH_COMMAND="ssh -o CheckHostIP=no" git fetch`. Fix: remove those entries.
- **Read-only prod queries from a Mac**: `ssh <droplet> 'cd /var/www/html/prod/api && php' <<'EOF' ... include 'config.php'; $m = new mysqli(...)` keeps credentials off the command line.
- **Test scripts against prod without deploying**: copy `/var/www/html/prod/api` to `~/tmp/...`, overlay changed files with scp, run with `--dry-run`.
- **zsh**: the droplet scripts are zsh; unquoted `$var` is not word-split (bug 1 above).
- `information_schema` table sizes are cached for 24 h; use `SET SESSION information_schema_stats_expiry = 0` for fresh numbers.
- `reset_schema()` only runs where a `dev` table exists. Prod has none (checked 2026-11-01).
- The app's MySQL user can't run `SHOW BINARY LOGS`, take a `--source-data` dump or create users; use MySQL root on the droplet for replication work.

## Where Things Are

| What | Where |
|---|---|
| Server code | this repo; prod checkout `/var/www/html/prod` (branch `prod`), dev `/var/www/html/dev` (master) |
| Cron logs | `/var/www/log/cron/` on the droplet |
| Droplet metrics | `droplet-metrics` skill (`--droplet connectstats`) |

## Progress Log

- **2026-10-22**: PHP 8 evaluation written up ([php-upgrade](php-upgrade.md), branch `claude/php-upgrade-plan`)
- **2026-10-25**: Committed missing `Auth.php`, dev droplet fixed
- **2026-11-01**: Prod reset to master. Sizing done; decided new droplet + Docker. First monthly inactive cleanup ran (deleted ~400k activities). Found and fixed cleanup bugs 1–7 (working tree, dry-run verified against prod DB, not deployed). S3 measured (872.5 GB, 7.32M objects). Added S3 bulk delete; tested on 5 orphaned files. Cleanup fixes deployed to dev and prod. Inactive users with data went from 1,046 to 3,016 within an hour of the cleanup: Garmin keeps pushing for dormant users. Real-device refresh on prod OK (new activity, FIT file and weather served). Decided: data on a DigitalOcean Volume, database moved by replication (seed dump, no prod downtime); old box's binlog settings checked and suitable
