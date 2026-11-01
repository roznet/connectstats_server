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
| Droplet size | **2 vCPU / 4 GB, ~80 GB disk** (same CPU/RAM as today) | See measurements below |
| Region | _open_ | Today nyc1; main droplet is lon1 |
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
- [ ] Deploy to prod, then update the cron wrapper on the droplet (the wrapper fix makes `--include-orphans` take effect, so deploy it after the PHP fixes)
- [ ] Remove stale GitHub entries from the droplet's `~/.ssh/known_hosts` (see Gotchas)

### Phase 1: Prune data before the move

**Cleanup bugs found and fixed (in working tree, not yet deployed):**

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

### Phase 2: Code on PHP 8

See [php-upgrade](php-upgrade.md) tasks P0/P1. Key item: `mysqli_report(MYSQLI_REPORT_OFF)` in `sql_helper.php`, otherwise Garmin webhooks return 500 on PHP 8.1+. Branch `claude/php-upgrade-plan` holds that doc (now also in master's working tree).

- [ ] P0 fixes, run `setup/test.py` on PHP 8.x locally (in the Docker image once Phase 3 exists)
- [ ] P1 deprecations (dynamic properties, `strftime`, vendored `S3.php` / `phpFITFileAnalysis.php`)

### Phase 3: Docker

- [ ] `Dockerfile`: `php:8.x-apache` + `mysqli`, `curl`, `zip`, `mbstring`; `a2enmod rewrite`; `AllowOverride All` (the `.htaccess` maps `/foo` → `foo.php`). Keep mod_php so `apache_request_headers()['Authorization']` keeps working (php-fpm needs `CGIPassAuth On`)
- [ ] `docker-compose.yml`: `app`, `worker` (queue workers started via `exec('php ...')`, plus cron jobs), `db` (MySQL 8.4, data on a DigitalOcean Volume so a future move is detach/attach)
- [ ] Volumes: `/var/www/bugs`, logs, tmp, `s3_cache_local`, config files
- [ ] Cron: port the droplet's cron jobs and `/var/www/bin/*.zsh` wrappers to run inside `worker` or as `docker compose exec`
- [ ] TLS: Caddy in front (as on the main droplet) or certbot; today it's acme.sh in brice's crontab
- [ ] Image update routine (rebuild/pull for PHP and MySQL security fixes)
- [ ] Same compose locally for the test suite

### Phase 4: Data migration

- [ ] Inventory: `config.php` (dev + prod), bugreport config, crontabs, Apache vhost, `/var/www/bin`, `/var/www/state`, APNs and S3 credentials, Visual Crossing key
- [ ] Rehearse `mysqldump --single-transaction --routines` of `garmin`, `queue`, `connectstats`, `garmin_dev` → restore into MySQL 8.4. Time it (sets the cutover freeze). Check charset (`SET NAMES 'utf8'` → utf8mb3 warnings) and `caching_sha2_password`
- [ ] rsync `/var/www/bugs`
- [ ] Final sync plan: stop queue on old box, final dump/restore (or dump only rows newer than the rehearsal)

### Phase 5: Parallel test of the new droplet

- [ ] Bring it up under a test hostname with a restored copy of prod data
- [ ] `setup/test.py all` (non-destructive) against it; `full` against a dev DB
- [ ] Real device: login, sync, FIT download, notifications, bug report upload
- [ ] Manual checks not covered by tests: APNs, S3 upload, weather fetch, `sync`/`backup` system-auth endpoints, maintenance and cleanup jobs

### Phase 6: Cutover

- [ ] Lower DNS TTL a day ahead
- [ ] Stop queue on old box, final data sync, switch DNS
- [ ] Old Apache reverse-proxies `/prod/api/` to the new box until DNS propagates, so Garmin webhooks don't land in the old DB
- [ ] Keep the old droplet at least two weeks; rollback = DNS back + replay rows received on the new box

## Gotchas

- **Droplet git pulls fail intermittently** with "Host key verification failed": `~/.ssh/known_hosts` has GitHub's pre-2023 RSA key (`SHA256:nThbg6kX…`) under some GitHub IPs (lines 1, 2, 5). With `CheckHostIP yes`, ssh prompts when DNS returns one of those IPs and there's no tty. Workaround: `GIT_SSH_COMMAND="ssh -o CheckHostIP=no" git fetch`. Fix: remove those entries.
- **Read-only prod queries from a Mac**: `ssh <droplet> 'cd /var/www/html/prod/api && php' <<'EOF' ... include 'config.php'; $m = new mysqli(...)` keeps credentials off the command line.
- **Test scripts against prod without deploying**: copy `/var/www/html/prod/api` to `~/tmp/...`, overlay changed files with scp, run with `--dry-run`.
- **zsh**: the droplet scripts are zsh; unquoted `$var` is not word-split (bug 1 above).
- `information_schema` table sizes are cached for 24 h; use `SET SESSION information_schema_stats_expiry = 0` for fresh numbers.
- `reset_schema()` only runs where a `dev` table exists. Prod has none (checked 2026-11-01).

## Where Things Are

| What | Where |
|---|---|
| Server code | this repo; prod checkout `/var/www/html/prod` (branch `prod`), dev `/var/www/html/dev` (master) |
| Cron logs | `/var/www/log/cron/` on the droplet |
| Droplet metrics | `droplet-metrics` skill (`--droplet connectstats`) |

## Progress Log

- **2026-10-22**: PHP 8 evaluation written up ([php-upgrade](php-upgrade.md), branch `claude/php-upgrade-plan`)
- **2026-10-25**: Committed missing `Auth.php`, dev droplet fixed
- **2026-11-01**: Prod reset to master. Sizing done; decided new droplet + Docker. First monthly inactive cleanup ran (deleted ~400k activities). Found and fixed cleanup bugs 1–7 (working tree, dry-run verified against prod DB, not deployed). S3 measured (872.5 GB, 7.32M objects). Added S3 bulk delete; tested on 5 orphaned files
