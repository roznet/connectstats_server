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
| Data storage | **DigitalOcean Volume** mounted at `/mnt/connectstats_data` (same pattern as the main droplet's MySQL) | Config, MySQL datadir, bug report uploads, logs, `state` (layout in Phase 3). Resizable online and cheap per GB, unlike the root disk (which needs a droplet resize). A future move is detach/attach. Not covered by droplet backups: needs its own backup (Phase 3) |
| Database sync | **Replication**: old MySQL 8.0 as source → new 8.4 as replica, seeded from one dump | No prod downtime to seed; cutover is seconds instead of a dump/restore freeze, so Garmin webhooks aren't lost. Fallback: dump/restore during a freeze if replication fails. See Phase 4 |
| Region | **lon1** (2026-11-01) | Same as the main droplet and the S3 bucket (London). Old box is nyc1; replication works across regions |
| Dev instance (`/dev`) on the new box | **No, for now** (2026-11-01) | Test on the Mac with the local compose stack; add a second stack later if needed |
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
| `queue` DB | 264 MB | `tasks`: rolling 45 days, ~35k tasks a day |
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
- [x] Run the backlog (2026-11-01, 14:19–16:06 UTC, ~43k/min, no errors): 4,729,484 `assets` rows and 4,729,484 S3 files deleted. `assets` 7.32M → 2.58M rows, 0 orphans left; spot checks show S3 and `assets` agree
- [x] `OPTIMIZE TABLE assets` (2026-11-01, 14 s): 995 MB → 378 MB
- [ ] Check CloudWatch on 2026-11-02/03: expect ~2.6M objects and ~310 GB
- [x] Pre-replication cleanup (2026-11-01, 19:10–19:35 UTC, run from a scratch copy of prod code plus `31a0c2c`; prod not released): `garmin` **12.06 GB → 8.97 GB**, `queue` 264 MB → 38 MB
  - Weather: 764,810 empty/error/Dark Sky-only rows deleted, Dark Sky removed from 381,409 rows (`cleanup_weather.php --drop-darksky`): 3,637 MB → 1,473 MB
  - Raw Garmin cache kept 14 days (~1.32M rows deleted), push notification log and `error_*` kept 90 days (~1.5M rows deleted), finished queue tasks kept 7 days (1.38M deleted)
  - Inactive users (180 days) with `--include-orphans`: 44k activities, 29k fitfiles, 2.8k assets and S3 files
  - New code on master keeps these small: weather rows without data aren't stored, retention in `runmaintenance.php` and `queue.php`. The old box isn't getting it (short-lived)
- [ ] Weather (pending decision): delete Visual Crossing `"info":"No data available"` placeholders (~450k since Apr 2023) and error rows (~15k Dark Sky errors, ~3k `403`, ~1k `[]`). Dark Sky rows (587k, until 2023-04-01) are real data but large (~2.5 KB each)
- [ ] Weather placeholders are 40–45% of new rows and each costs a Visual Crossing query: check whether fetching right after upload is too early
- [ ] Decide retention and Garmin deregistration (see Decisions)

**Full table inventory (2026-11-01) and what else can go.** Pruning is nice to have, not required: with replication the seed size only changes how long the seed takes, and the Volume can grow. Don't `OPTIMIZE` on the old box just for the move: dump/restore rebuilds every table compactly anyway.

| Table(s) | Size | Retention today | Proposal |
|---|---|---|---|
| `activities`, `weather`, `fitsession`, `fitfiles` | 3.6 + 3.6 + 1.9 + 1.1 GB | Inactive-user cleanup only | The real lever is the retention decision. Activities by start year: ~170k before 2020, then 355k (2020) rising to 581k (2026). Keeping e.g. 2023 onward drops ~40% of the big tables (~4 GB) |
| `assets` | 937 MB, 7.0M rows | — | Orphan backlog (above) removes ~4.7M rows |
| `cache_activities`, `cache_fitfiles` (+ `_map`) | 631 + 289 + 47 MB | 45 days (`runmaintenance.php`) | Raw webhook payloads, only needed for reprocessing. Cut to ~7 days just before the seed (~800 MB) |
| `error_activities`, `error_assets`, `error_fitfiles`, `error_users` | 64 + 22 + 13 + 4 MB | Never pruned (since 2020) | Keep 90 days; add to `runmaintenance.php` |
| `notifications` (+ `notifications_activities`) | 104 + 24 MB, 904k rows | Never pruned (since 2021) | Push log: keep 90 days. Keep `notifications_devices` |
| `usage_summary` | 137 MB, 1.6M rows | Never pruned (since 2019) | Delete rows for users that no longer exist; consider monthly roll-up for years before last |
| `queue.tasks` | 264 MB, 1.6M rows | 45 days (`clean_tasks_and_queues`), ~35k tasks a day | Fine as is (not old rows). Optionally 14 days |
| `garmin_dev` | 56 MB (36 MB is `assets_old`) | — | Don't migrate it: recreate the dev DB on the new box from test data |
| `/var/www/bugs` + `connectstats.gc_bugreports` | 3.9 GB files, 74k rows | `bugreport/clean.php` daily | Check what `clean.php` keeps; prune old uploads before the rsync |
| `/var/www/log` | 3.1 GB | 15 days (cleanup cron) | Not moved. Mostly one log file per queue task (~35k a day) |

- [ ] Add 90-day pruning of `error_*` and `notifications` to `runmaintenance.php`
- [ ] Clean `usage_summary` rows of deleted users
- [ ] Shorten cache retention before the seed
- [ ] Review bug report retention
- [ ] Consider adding `cs_user_id` indexes to `fitfiles`, `weather`, `fitsession` (schema v9) on the new stack
- [ ] Investigate `file.php` failures: 170–460 requests a day get `status=1` in the `usage` table (2,421 of ~26.7k in the week to 2026-11-01, predating the cleanup deploy). Likely files that no longer exist

### Phase 2: Code on PHP 8

See [php-upgrade](php-upgrade.md) tasks P0/P1. Key item: `mysqli_report(MYSQLI_REPORT_OFF)` in `sql_helper.php`, otherwise Garmin webhooks return 500 on PHP 8.1+.

- [x] P0 fixes (`mysqli_report`, `connect_error`, `sprintf` arg, `$_GET` typo). `setup/test.py full` 55/55 and `all` 51/51 on PHP 8.5.12 + MySQL 8.4.13 in the local compose stack (2026-11-01)
- [ ] P1 deprecations (dynamic properties, `strftime`, vendored `S3.php` / `phpFITFileAnalysis.php`)
- [x] PHP 7.4 → 8.5 behaviour review (2026-11-01):
  - PHPCompatibility 10 (testVersion 8.5) on `api/`, `bugreport/`, `setup/`: no errors; only the known deprecations above plus no-op `curl_close` / `finfo_close` / `openssl_free_key`
  - Targeted checks for silent behaviour changes: `count()` on null/false (safe: `query_as_array()` always returns an array, `query_next()` guards failed queries), string/number `==` (all numeric), removed functions, `${}` interpolation, 8.4 implicit nullable params, 8.5 casts: none
  - Runtime on PHP 8.5 in the local stack: `test.py full` 55/55 with no warnings in app, worker or task logs; maintenance and cleanup scripts run against the test DB, weather cleanup checked on 7 synthetic rows
  - **Bug found and fixed** (`752c89f`): the `log()` helpers passed messages to `vsprintf()` even without arguments, and the SQL helper logs every query that way. A `%` in a message (LIKE pattern, JSON in an INSERT, curl error) is a warning on 7.4 but a fatal `ValueError` on 8. Same for a `printf()` of an uploaded file name in `bugreport/list.php`
  - Remaining: the full deprecation sweep with the test overlay (`docker-compose.test.yml`), and paths the tests don't cover (APNs, weather, S3 writes, bug report upload, backfill): Phase 5

### Phase 3: Docker

Local stack built 2026-11-01, see [docker/README.md](../docker/README.md). Config comes from `CONFIG_DIR` mounted at `/etc/connectstats` (first in PHP's `include_path`), data from `DATA_DIR`, logs from `LOG_DIR`.

- [x] `docker/Dockerfile`: `php:8.5-apache` with mod_php (keeps `apache_request_headers()['Authorization']`), `mysqli`, `zip`, rewrite/headers/remoteip, `AllowOverride All`, PHP CLI for the queue, zsh/jq/curl/procps, pinned `supercronic`. Target `test` adds Python for `setup/test.py`
- [x] `docker-compose.yml`: `app`, `worker`, `db` (`docker/mysql/Dockerfile`: MySQL 8.4 with settings and init baked in). `docker-compose.dev.yml` adds live code, a Garmin user-id mock and the `tests` service
- [x] **Droplet created 2026-11-01**: `connectstats-lon1` (lon1, Premium AMD 2 vCPU / 4 GB / 80 GB, Ubuntu 26.04.1 LTS), test hostname `new.connectstats.app`. Keys-only SSH, root login off, ufw 22/80/443, unattended upgrades, Docker 29.9 + Compose v5.6 with log caps (5 × 50 MB). Config managed like the other droplets: private repo host dir, `syncfiles push` (details in the private ops note). Stack running in `sandbox` mode with empty databases; Caddy certificate issued
- [x] DigitalOcean Volume `connectstats-data`, 50 GB ext4, mounted at `/mnt/connectstats_data` by DigitalOcean's systemd mount unit (by UUID, `nofail`). Sized from measurements: ~35 GB expected (DB ~13.5 GB, bugs 3.9 GB, logs ~3 GB, dump/backup staging, binlogs ~0.3 GB/day uncompressed on the old box)
- [x] Host user/group `dockerapp` 2000:2000 (`nologin`), brice in the group (same convention as the main droplet). All containers run as 2000 (`www-data` is remapped in the image, MySQL runs with `user: 2000:2000`) with `umask 0002`, so everything on the Volume is `dockerapp`-owned and group-writable
- [x] Volume layout (all `dockerapp:dockerapp`, created 2026-11-01; plus `caddy/` for certificates):

  | Path | Mounted at | Mode | Notes |
  |---|---|---|---|
  | `config/` | `/etc/connectstats` (ro) | `2770`, files `660` | `config.php`, `config_bugreport.php`, the APNs `.p8` key (set `apn_keyfile` to `/etc/connectstats/<key>.p8`), `compose.env` |
  | `mysql/` | `/var/lib/mysql` | `750` | Never edited by hand: `docker compose exec db mysql` |
  | `bugs/` | `/var/www/bugs` | `2775` | Bug report uploads (3.9 GB) |
  | `log/` | `/var/www/log` | `2775` | Per-task logs (~35k files a day) and `cron/`; on the Volume so logs can't fill the root disk |
  | `state/` | `/var/www/state` | `2775` | Cron stamps (48 KB) |
  | `backups/` | (host only) | `2770` | Nightly dump staging before the off-box copy |

  Not on the Volume: tmp and the S3 read cache (named Docker volume, safe to lose; config `tmp` → `/var/www/tmp`, `s3_cache_local` → `/var/www/tmp/s3_cache`), Docker images, the git checkout with the compose files (its `.env` is a symlink to `config/compose.env`). So a rebuild is: new droplet, attach Volume, clone, symlink `.env`, `docker compose up -d`
- [x] Setgid on the Volume dirs so files brice creates also land in group `dockerapp`
- [x] Compose takes the path from `.env` (`DATA_DIR=/mnt/connectstats_data`, defaulting to `./data` locally) instead of hardcoding it, so the same file runs on a Mac
- [x] Bind mounts from `DATA_DIR` / `LOG_DIR` / `CONFIG_DIR` as in the table above; tmp is a named volume. Code comes from the image, config and secrets from `CONFIG_DIR`
- [x] MySQL settings in git (`docker/mysql/connectstats.cnf`; buffer pool from `INNODB_BUFFER_POOL_SIZE` in `.env`), never `SET PERSIST`: persisted values live only in the datadir and silently revert to defaults if it's rebuilt. Start with `innodb_buffer_pool_size` ~1.5–2 GB (stock 128 MB; DB is ~13 GB, box has 4 GB), a `server_id` other than 1, `log_bin` on
- [x] App MySQL user created by an init script with `caching_sha2_password` (8.4 disables `mysql_native_password` by default; PHP 8 `mysqli` supports it). Users and grants are not in the dump
- [x] Backups: droplet backups skip Volumes. Nightly `mysqldump --single-transaction` copied off the box, Volume snapshots only manually before risky changes (done in Phase 6, see there)

**Queue and jobs.** How it works today (see [queue-system](queue-system.md)):

- The queue starts itself: every `GarminProcess::exec()` adds a task, then (at most every 5 s) runs `queuectl.php start` in the background. That spawns any missing `nohup php runqueue.php N &` workers (`queue_count`, default 5). No cron entry starts the queue, so **the workers are children of whichever Apache process handled the request**.
- Liveness is checked with `ps ax` against `queues.queue_pid`, so `queuectl` only sees workers in its own PID namespace (container).
- Workers pick tasks with `MOD(task_id, queue_count) = index` and `finished_ts IS NULL`, so a task killed mid-run is picked up again by the restarted worker (at-least-once; the scripts need to be idempotent).
- Each task row stores an absolute `task_cwd` (e.g. `/var/www/html/prod/api/garmin`) and writes a log to `log/YYYYMMDD/task_N.log`.
- Cron (`/etc/cron.d`): daily tmp/log/bug cleanup, weekly maintenance, monthly inactive-user and orphan-S3 cleanup, weekly report (Python), twice-daily status check (`curl`, `jq`, `setup/query.py`).

Plan:

- [x] Add a config flag (`queue_autostart => false`) so web requests only add tasks and never spawn workers. On the new box, `app` (Apache) serves requests and `worker` owns all workers
- [x] `worker` container: same image, entrypoint runs `queuectl.php start` every minute (supervisor loop) plus `supercronic` with a crontab kept in git. `queuectl` and the workers share a PID namespace, so liveness checks work. Deploying `app` no longer kills running tasks; `docker compose stop worker` cleanly stops all processing (needed at cutover)
- [x] Keep the same paths in the image (`/var/www/html/prod/api`, `/var/www/log`, …) so `task_cwd` values in rows replicated from the old box still resolve
- [ ] Logs: `/var/www/log` on the root disk with the 15-day cleanup, or reduce per-task logging (35k files a day)
- [x] Worker image has `zsh`, `curl`, `jq` and `python3` (venv at `/opt/venv` with `mysql-connector-python`, `urllib3` for `setup/query.py`)
- [x] Port `/var/www/bin/*.zsh` into the deployment's crontab (`CRONTAB`): done in Phase 6
- [x] Alerts without local mail (done in Phase 6, healthchecks.io per job; weekly summary dropped): the container has no MTA, so `weekly_report.py` (`/usr/bin/mail`) and `check_status` (cron `MAILTO`) can't email. Follow flyfun: healthchecks.io pings per job (success, and failure with the log tail) for alerting, and Resend's HTTP API (as weatherbrief does) if the weekly summary email is kept
- [x] Same `www-data` uid in `app` and `worker` so both can write the bind-mounted dirs
- [x] S3 from the container (2026-11-01): v4-signed `getObject` accepted by AWS from PHP 8.5 (prod `getObject` also checked on the old box). Fixed `S3.php`'s by-reference curl write callback (PHP 8 warning on every request). Full put/delete check with prod credentials in Phase 5
- [x] **`S3::getBucket()` (listing) failed with `SignatureDoesNotMatch` on PHP 7.4 and 8.5**: `__getSignatureV4` built the canonical query string unsorted, so every multi-parameter request (`prefix` + `max-keys`) was signed wrong. Fixed 2026-11-01 (sorted keys, RFC 3986 encoding); listing + get verified on 7.4 and 8.5. `cleanup_orphan_s3.php` lists each inactive user's prefix, so its monthly runs had been failing every listing until this reaches prod
- [x] Bug report notification used PHP `mail()` only (`email_bug_to`), and the container has no MTA. `bugreport.php` now sends through the Resend HTTP API when `resend_api_key` is set (with `email_from` on a Resend-verified domain), `mail()` otherwise (2026-11-01)
- [x] Resend for the new box: `RESEND_API_KEY` / `RESEND_FROM` / `RESEND_REPLY_TO` in the compose `.env`, `connectstats.app` verified in Resend (DKIM TXT `resend._domainkey` plus CNAMEs `send` and `rsend`; the old box's `mail._domainkey` stays). Test email sent from the new box through `BugReport::send_email` (2026-11-01)
- [x] `sandbox` config flag: skips push notifications, weather queries, S3 writes and deletes (checked in the worker container 2026-11-01)
- [x] Graceful `docker compose stop worker`: workers finish the current task and end `dead:stopped` (~2 s). Needs `stop_signal: SIGTERM` because the base image sets `STOPSIGNAL SIGWINCH`
- [x] TLS: Caddy 2.10 in `docker-compose.prod.yml`, certificates on the Volume (old box: acme.sh in a user crontab)
- [ ] Image update routine (rebuild/pull for PHP and MySQL security fixes)
- [x] Same compose locally for the test suite (`docker compose run --rm tests full`)

### Phase 4: Data migration

- [ ] Inventory: `config.php` (dev + prod), bugreport config, crontabs, Apache vhost, `/var/www/bin`, `/var/www/state`, APNs and S3 credentials, Visual Crossing key
**Database: seed once, then replicate. Prod keeps running throughout.**

Old box checked 2026-11-01: `log_bin=1`, `binlog_format=ROW`, binlogs kept 30 days, `server_id=1`, GTID off (so file/position replication). All tables InnoDB except one MyISAM table in `connectstats`. The app's MySQL user has no `RELOAD` / `REPLICATION` privileges: the seed dump and the replication user need MySQL root on the old box.

**Done 2026-11-01** (scripts and exact commands in the private ops repo):

- [x] Prune first (Phase 1): `garmin` went from 13.1 GB to 8.8 GB before the seed
- [x] On the old box (as MySQL root): `repl@127.0.0.1` (`REPLICATION SLAVE` only) and `migrate@localhost` (dump: `RELOAD`, `REPLICATION CLIENT`, `SELECT`/`SHOW VIEW`/`LOCK TABLES` on the three databases). **Replication passwords are limited to 32 characters** (error 3056 on `CHANGE REPLICATION SOURCE`)
- [x] Seed: `mysqldump --single-transaction --source-data=2 --quick --hex-blob --no-tablespaces --set-gtid-purged=OFF --databases garmin queue connectstats` (no routines/triggers/events/views exist; `garmin_dev` not migrated), `gzip -1` on the old box, streamed over SSH from the new box: 1.7 GB in 145 s. Prod unaffected
- [x] Restore into MySQL 8.4 with `sql_log_bin=0` after stopping `app`/`worker` and dropping the empty databases: 567 s (≈ the fallback's freeze length). `garmin` 7.9 GB after rebuild. No charset or collation errors
- [x] Replication from the dump's coordinates through an SSH tunnel (`docker-compose.migration.yml`: autossh container to the old box's `127.0.0.1:3306`, so its MySQL stays bound to localhost and 3306 stays closed). Caught up 20:10:51 UTC, 0 s behind, `Replicate_Do_DB: garmin,queue,connectstats`
- [x] Replica `super_read_only` (`docker/mysql/replica.cnf`); `app` and `worker` stay stopped until cutover (the test hostname returns an error meanwhile)
- [x] Verified: for 22 tables with an integer PK, `COUNT(*) WHERE pk <= old max` identical on both boxes; `gc_bugreports` (MyISAM) identical too
- [ ] Watch `SHOW REPLICA STATUS` periodically until cutover; if replication breaks, re-seed (≈ 15 min) or fall back to dump/restore during a freeze
- [ ] Gotchas hit: a script with `pipefail` + `set -e` exits silently on `zcat | head` (SIGPIPE); `docker compose exec -T` in a `while read` loop swallows the loop's stdin (use `< /dev/null`)

**Files**

- [ ] rsync `/var/www/bugs` to the Volume now, again just before cutover (only the delta)
- [ ] FIT files stay in S3, nothing to move

### Phase 5: Parallel test of the new droplet

**Test stack (2026-11-01):** `docker-compose.test.yml` adds `db-test` (second MySQL, restored from the seed dump in 559 s), `app-test` and `worker-test` (sandbox, `queue_autostart` off), Caddy `UPSTREAM=app-test:80` on the test hostname. The real `db` stays a read-only replica; `app`/`worker` stay stopped. An `auto_prepend_file` error handler (test overlay only) logs every PHP warning/notice/deprecation, including those hidden by the code's own `error_reporting()`. Memory: replica buffer pool shrunk online to 768 MB for the duration, plus 2 GB swap on the host.

- [x] Bring it up under the test hostname, separate copy, replica untouched
- [x] `sandbox` on (no APNs, no weather, no S3 writes/deletes; verified in the logs: `SANDBOX: skipping weather query`)
- [x] **Replay of real Garmin notifications** (everything prod received after the snapshot, read from the replica, POSTed to the test webhooks as Garmin does; scripts in the private repo): 343 activity + 263 file notifications, all HTTP 200, 0 failed queue tasks
  - Activities: 319 of 337 identical to prod. All differing ones had several notifications (`activities` then `manuallyUpdatedActivities`): the replay compresses minutes into seconds and 5 workers run in parallel, so the last version can differ. Every single-notification activity is identical
  - FIT files: 48 downloaded through Garmin's original callback URLs (others skipped: inactive users, as on prod), **48 of 48 sessions identical to prod**
- [x] **Found and fixed by the replay: PHP 8 fatal in the FIT parser** (`d2b9766`): Connect IQ developer fields without data (CORE body temperature) → `count(null)` TypeError in `phpFITFileAnalysis.php`; the parent task still logged Success, so only the session comparison showed it. Plus `runfitextract.php` now catches `Throwable`, and developer fields without units no longer warn once per record (`c2f55cc`, ~3k warnings per file). Re-extraction of all 60 downloaded files: 0 warnings, 0 notices, 0 fatals
- [x] Remaining PHP messages: deprecations only (dynamic properties, `strftime`), all hidden by today's settings: the P1 list
- [x] **Found and fixed: bug report admin pages were public on the new box** (`7f60e3a`). `bugreport/list` and `export` (reporters' emails) were protected on the old box by an untracked `bugreport/.htaccess` (Basic auth), which the image never had. Served without auth on the test hostname for ~8 min (20:39–20:47 UTC); Caddy's log shows no request from anyone but our own checks. Now in the Apache vhost, password file `htpasswd` from `CONFIG_DIR`, fails closed. Lesson: diff the old box's untracked/ignored files against the repo before cutover (done: only that `.htaccess`)
- [x] `setup/test.py all`: smoke 7/7, schema 41/43 (the 2 expected: no `dev` marker, no `assets_s3`); status endpoints 200; bug report `new` 200, `list`/`export` 401
- [x] Known pre-existing bug: `connectstats/sync` looks up `summaryId` with `%d`, so string ids (`…-file`) never match (affects the secondary-server backup path only)
- [x] Known pre-existing data issue: emoji in activity names are stored with their JSON escapes stripped (`ud83d…` instead of `\ud83d…`) on prod
- [x] **Found and fixed by the simulator: every signed request got 401 behind Caddy** (`de53e0a`). `Auth::get_request_url()` rebuilt the URL from `REQUEST_SCHEME` (`http` in the container) while the app signs the `https` URL. It now honours `X-Forwarded-Proto` (http/https only; it only selects which URL the signature is checked against). Same fix for the garmin-health file URLs in `shared.php`. `test.py smoke` over https through Caddy: 7/7 (it had passed before only because it ran over plain http inside Docker)
- [x] Simulator against the test hostname (2026-11-01): login, stats, activity list, FIT downloads (read from the prod S3 bucket), bug report upload (zip on the Volume), Resend notification, protected list page. Found: Resend rejects the whole email when the reporter's address is invalid (`b@y`), which `mail()` never did; reply-to now only set when the address validates (`0c3829a`)
- [x] Server checks on PHP 8.5 (2026-11-01):
  - S3 with prod credentials on the dev bucket: put, get (content matches), list, bulk delete, object gone
  - Visual Crossing: one query for a recent activity returned exactly the JSON prod stored for it (0.29 s)
  - `runmaintenance.php` (25 s), `cleanup_inactive_users.php --dry-run` (~3 min), `cleanup_orphan_s3.php --dry-run`, `queuectl.php clean` on the test copy: all exit 0, no warnings/notices/fatals
  - Garmin deregistration webhook: HTTP 200, token secret cleared; unsigned system call refused (401)
  - **Found and fixed:** the three weather functions called `curl_error($ch)` with no `$ch` on a failed request: a TypeError on PHP 8 that would abort FIT extraction (`3b76cfb`)
  - **`api/garmin/backup` returns 500 in Docker**: it shells out to `mysqldump` on localhost, which the image doesn't have. Only the archived secondary-server setup used it (and `connectstats/sync`); no call to either in the old box's logs since 2026-10-18. Left unsupported
- [ ] Not covered: push notifications (sandbox; would need a one-off send to the owner's device)
- [ ] Before cutover: remove `docker-compose.test.yml` from `COMPOSE_FILE` and `UPSTREAM`, `docker compose rm -sf db-test app-test worker-test`, optionally delete `test/` on the Volume

### Phase 6: Cutover

State on 2026-11-01 evening: new box replicating (0 s behind) with `app`/`worker` stopped; Phase 5 test stack (`db-test`, `app-test`, `worker-test`) running and served on the test hostname; replica buffer pool temporarily 768 MB (set online; `compose.env` still says 1536M). Exact commands, scripts and host details: private ops note.

**Prerequisites (before the cutover day)**

- [x] **Backups on the new box** (2026-11-01): pulled nightly by the owner's home backup host, same pattern as the main droplet. `mysqldump --single-transaction` of `garmin`, `queue`, `connectstats` via `docker compose exec db`, gzipped on the box and streamed over SSH (1.35 GB, ~4 min). A dump is rejected unless it is valid gzip, contains all three databases, has mysqldump's completion marker and isn't >20% smaller than the previous one. Off-box copy keeps 30 days plus every 1st-of-month dump; bug report uploads mirrored append-only. healthchecks.io per job. First dump verified on the off-box storage. **No scheduled Volume snapshots** (DigitalOcean bills ~$5/month for 4, and the dump, bug mirror and repo config already cover a rebuild): manual snapshot before risky changes only
  - [ ] Owner: install the scheduled job on the backup host
- [x] **Cron on the new box** (2026-11-01): the old jobs ported into the deployment crontab (`CRONTAB`, supercronic in `worker`), same UTC times: daily cleanup (queue tasks, logs, tmp, `usage.php`, bug `clean.php`), weekly `runmaintenance.php` (no more weekly `queuectl stop`), monthly S3 and inactive-user cleanups, twice-daily status check. Each line runs through `cronjob <slug>` (per-run log in `log/cron/`, healthchecks.io check `connectstats-<slug>`: start, success, or exit code + log tail; PHP fatals count as failures). Weekly report email dropped. `connectstats-check-status` (status endpoint + signed search through the public URL) run once for real: OK
  - [ ] Owner: create the other four checks in healthchecks with their cron schedules (listed in the deployment crontab), and pause `connectstats-check-status` until the cutover (the worker is stopped until then)
- [x] **Static site content** (2026-11-01): kept in the private repo with the box's config, mounted read-only into Caddy (`SITE_DIR`, default `DATA_DIR/site`). The Caddyfile sends `/prod/*` to `app` and everything else to that site: its `*.caddy` snippets plus `www/`; without one those paths are 404 (local stack). The old PHP pages were only redirects (`/`, `privacy/`, `support/` → blog) and an `app-ios/` fallback page that echoed its URL: now Caddy `redir` rules and a static page. `apple-app-site-association` (root and `.well-known/`) served unchanged as `application/json`. Dropped: `etc/` (debug pages, incl. a `phpinfo`), awstats, phpmyadmin, `/dev`. Old access log (2 days): real traffic outside `/prod` is `/`, the AASA (Apple's CDN), favicon, privacy/support, and **a Garmin dev app still pushing to `/dev/api/garmin/*`**: answered with an empty 200 until its webhooks are removed in Garmin's portal
  - [x] Deployed 2026-11-01 and every path checked over HTTPS on the test hostname (redirects, AASA at both paths as `application/json`, favicons, `app-ios` fallback, `/dev` sink, 404 elsewhere, `/prod` still proxied)
- [x] **TLS for `connectstats.app`: obtained at the DNS switch, not before** (decided 2026-11-01). The old box serves no port 80 (firewalled; its acme.sh uses the DigitalOcean DNS API), so an HTTP-01 relay would mean opening it. Instead `connectstats.app` is added to `SITE_ADDRESSES` right after the A record changes (not earlier: failed validations would back off and count against rate limits). The 5-minute TTL hides the gap: clients still on the old box are proxied
- [ ] **DNS TTL** is 3600 s on the DigitalOcean nameservers: lower it to 300 a day ahead, on **both the A and the AAAA record**. The new box has no IPv6 address: at cutover, delete the AAAA record when switching the A record, or IPv6 clients keep reaching the old box
- [ ] Optional: release master to the old box's prod first (S3 signing fix, Resend support, PHP 8 fixes are all PHP 7.4-safe), following "Releasing and Testing Prod"
- [ ] Remove the test stack: `docker-compose.test.yml` and `UPSTREAM`/`TEST_*` out of `compose.env`, `docker compose rm -sf db-test app-test worker-test`, delete `test/` on the Volume. Restart `db` so the buffer pool is back to 1536M (replication resumes by itself)
- [ ] Final config: `'sandbox' => false` in the new box's `config.php` (keep `queue_autostart => false`). `SITE_ADDRESSES` stays the test hostname until the DNS switch

**Cutover (short window, queue paused rather than stopped for clients)**

- [ ] Manual DigitalOcean snapshot of the new box's Volume (delete it once the cutover has settled)
- [ ] **Put the production Garmin endpoints on hold** (Garmin's endpoint configuration tool; Garmin queues notifications while on hold and delivers them on release, used before for server changes). No webhook arrives during the switch, so none can land on the wrong box
- [ ] Start `app` on the new box (not `worker`): it can't write yet (replica is read-only), so do the proxy switch and promotion back to back
- [ ] Old Apache reverse-proxies `/prod/` (API **and** bugreport) to the new box first: with the queue self-starting, any request the old box still handles would restart its workers. It proxies over HTTPS to the **test hostname** (the only certificate the new box has before DNS moves). **OAuth signatures** are checked against a URL rebuilt from `Host`, so Caddy sets `Host: connectstats.app` for `/prod/` requests coming from the old box's address only (deployment snippet imported into the `/prod/*` handle). Config prepared and syntax-checked the day before, enabled with `a2enconf` at this step. Static content stays on the old box until DNS moves
- [ ] On the old box: `queuectl.php stop`, wait for running tasks to finish, disable its cron jobs; final rsync of `/var/www/bugs` to the Volume. Unfinished tasks stay in `tasks` and replicate over
- [ ] Wait for the replica to catch up (`Seconds_Behind_Source = 0`, executed position = old box's binlog position), then `STOP REPLICA; RESET REPLICA ALL`, `SET GLOBAL super_read_only = OFF, read_only = OFF`, mark the replicated `queues` rows as stopped (their PIDs belong to the old box: else `queuectl` tries `kill -9` on them, harmless but noisy), `docker compose up -d worker`; the unfinished tasks get picked up
- [ ] Verify right away: `test.py smoke --base-url https://connectstats.app/prod` (through the old box's proxy), a webhook arriving and processed on the new box (`cache_activities.processed_ts`), the app from a device
- [ ] Switch DNS to the new box: A record to the new IP, **delete the AAAA** (new box is IPv4-only). Then add `connectstats.app` to `SITE_ADDRESSES`, recreate Caddy (`--no-deps`), check the certificate is issued. The proxy keeps covering clients until DNS propagates
- [ ] Once `https://connectstats.app/prod/api/status/api` answers from the new box with a valid certificate: **release the Garmin hold** and watch the backlog arrive and get processed (`cache_activities`, queue)
- [ ] After DNS settled: remove `docker-compose.migration.yml` from `COMPOSE_FILE` (drops `replica.cnf`: no more read-only/replication filters) and `SOURCE_SSH*`, restart `db`; stop the tunnel. Drop the `repl`/`migrate` MySQL users on the old box and `~/.migration`
- [ ] Keep the old droplet at least two weeks; rollback = reverse replication (new → old, the new box keeps binlogs 14 days) or replay rows received on the new box, then DNS back. Then destroy it (snapshot first)

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
- **2026-11-01**: Prod reset to master. Sizing done; decided new droplet + Docker. First monthly inactive cleanup ran (deleted ~400k activities). Found and fixed cleanup bugs 1–7 (working tree, dry-run verified against prod DB, not deployed). S3 measured (872.5 GB, 7.32M objects). Added S3 bulk delete; tested on 5 orphaned files. Cleanup fixes deployed to dev and prod. Inactive users with data went from 1,046 to 3,016 within an hour of the cleanup: Garmin keeps pushing for dormant users. Real-device refresh on prod OK (new activity, FIT file and weather served). Orphaned-assets backlog cleared: 4.73M rows and S3 files deleted. Decided: data on a DigitalOcean Volume, database moved by replication (seed dump, no prod downtime); old box's binlog settings checked and suitable. Full table inventory and pruning proposals added; queue/job design for Docker (separate `worker`, no self-start from web requests)
