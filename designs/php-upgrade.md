# PHP 8.5 / Ubuntu 26.04 Upgrade

> Move production from Ubuntu 20.04 + PHP 7.4 (Ubuntu Pro ESM) to a new droplet on Ubuntu 26.04 LTS + PHP 8.5 + MySQL 8.4.

## Intent

October 2026's PHP2Shell RCEs (CVE-2026-109618, CVE-2026-109619) were patched on prod through Ubuntu Pro ESM packages for php7.4. That fixed the emergency, but it leaves prod on a PHP branch that is end of life upstream and depends on paid legacy support for every future fix. The goal is a stack where routine `apt upgrade` delivers security fixes for years, and where the code runs without relying on deprecated behaviour.

## Target Stack

| Component | Today | Target | Why |
|---|---|---|---|
| OS | Ubuntu 20.04 + Pro Legacy | **Ubuntu 26.04 LTS** | Standard support to 2031. Not 26.10: interim releases only get 9 months. |
| PHP | 7.4 (ESM) | **PHP 8.5 from Ubuntu archive** | Upstream security support to end 2029; Ubuntu keeps patching it beyond that. Avoid the ondrej PPA: distro packages are what Ubuntu Pro covers, which is what saved us this time. |
| MySQL | (check: likely 8.0 on 20.04) | **MySQL 8.4 LTS** (distro `mysql-server`) | Default on 26.04. |
| Web | Apache + mod_php (assumed) | **Apache + mod_php** (`libapache2-mod-php`) | Keeps `.htaccess` rewrites and `apache_request_headers()['Authorization']` behaving as today. If php-fpm is chosen instead, `CGIPassAuth On` is required or every authenticated call returns 401. |

## Evaluation Results

Prod code (`origin/prod` at `4efc6ea`) was run with the master test suite (`setup/test.py`) on PHP 8.3.6 + MySQL 8.0.46, with a mock Garmin `url_user_id` endpoint. PHP 8.5 could not be installed in the test environment, so 8.4/8.5 are covered by static analysis only (PHPCompatibility, `testVersion 8.4-`).

| Run | Result |
|---|---|
| Prod code, unmodified | **50/56 pass.** Garmin webhooks `api/garmin/activities` and `api/garmin/file` return **500**; queue processing fatals. |
| Prod code + `mysqli_report(MYSQLI_REPORT_OFF)` | **57/57 full + all smoke tests pass.** |
| `php -l` on 8.3 | No syntax errors. |
| PHPCompatibility 8.4+ | No removed features. Deprecations only (below). |

### Root cause of the failures

PHP 8.1 changed mysqli's default error mode to throw `mysqli_sql_exception`. The whole codebase assumes failed queries return `false` and reports via `sql_helper->lasterror` (e.g. `shared.php:142-162` records failures in `error_*` tables). On 8.x any failed query becomes an uncaught fatal and a 500 to Garmin. Restoring the old mode in `sql_helper` makes behaviour identical to 7.4.

### Remaining deprecations (log noise today, errors in PHP 9)

- ~30 dynamic properties on `GarminProcess`, `Paging`, `StatusCollector`, `Queue`, `garmin_sql`/`queue_sql` (via `sql_helper`), plus `BugReport` (static scan).
- `strftime()` in `shared.php` (3), `sql_helper.php` (2), `bugreport/bugreport.php` (2).
- Vendored libs: `S3.php` (`openssl_free_key`, `curl_close`, `finfo_close`, `__` method names, `trim()` default change in 8.6), `phpFITFileAnalysis.php` (`FILTER_SANITIZE_STRING`).

### Latent bugs that become fatal on PHP 8

- `sql_helper.php:71` calls `mysqli_error()` with no argument (fatal `ArgumentCountError`). The branch is also dead: `new mysqli` never returns falsy. Use `$this->connection->connect_error`.
- `shared.php:952` `sprintf( '%s > %s 2>&1 &', $command )` has two placeholders and one argument: `ArgumentCountError` on PHP 8. Only reached when `use_queue` is false (currently always true).
- `api/connectstats/search.php:55` reads `$_Get` (typo): undefined variable warning, branch never taken.

## Tasks

### Code (P0: required before cutover)

1. `sql_helper.php`: call `mysqli_report(MYSQLI_REPORT_OFF)` before `new mysqli`; replace `mysqli_error()` with `connect_error` check.
2. `shared.php:952`: pass `$logfile` to `sprintf`.
3. `search.php:55`: `$_Get` → `$_GET`.
4. Remove the `error_reporting(E_ALL & ~E_DEPRECATED)` lines on master once the P1 items land; rely on `display_errors=Off` + `log_errors=On` in php.ini instead.

### Code (P1: same release if possible)

5. Declare properties on all classes listed above (or `#[\AllowDynamicProperties]` as a stopgap).
6. Replace `strftime()` with `date()` (all formats used map 1:1).
7. Patch or update the vendored `S3.php` and `phpFITFileAnalysis.php`.
8. Run the test suite with `error_reporting=E_ALL` and fail on any new deprecation.

### Server build

9. Droplet on 26.04 LTS: `apache2 libapache2-mod-php php-mysql php-curl php-xml php-mbstring php-zip mysql-server zsh certbot`. (`setup/maintenance.sh` is zsh.)
10. Enable `mod_rewrite`, `AllowOverride All` for the API directory (`.htaccess` maps `/foo` → `foo.php`).
11. php.ini: `display_errors=Off`, `log_errors=On`, check `disable_functions` does not block `exec` (the queue starts workers via `exec('php ...')`), `post_max_size`/`upload_max_filesize` large enough for bug report zips.
12. Make sure the web user can run `php` CLI and write `api/*/tmp`, `api/*/log` and the bug report data directory.
13. Recreate cron jobs and any queue supervision from the current box (`crontab -l` for root and www-data).
14. Unattended security upgrades on; Ubuntu Pro attached (free for personal use, adds Livepatch).
15. TLS via certbot.

### Data and cutover

16. Inventory the old box: `config.php` (dev and prod), MySQL databases (main + queue + bugreport), any local asset storage if `save_to_s3_bucket` is `localhost:`, bug report files, crontabs, Apache vhosts.
17. Rehearse: `mysqldump --single-transaction --routines` → restore into MySQL 8.4 on the new droplet. Check charset/collation (code issues `SET NAMES 'utf8'`; 8.4 maps that to utf8mb3 with a deprecation warning) and that the app user authenticates with `caching_sha2_password`.
18. Run `setup/test.py all` (non-destructive) against the restored copy, then `full` on a separate dev database.
19. Point the app at the new box under a test hostname and exercise login, sync, FIT download, notifications and bug report upload from a real device.
20. Cutover: lower DNS TTL a day ahead. Stop the queue on the old box, final dump/restore, switch DNS. Because Garmin keeps posting webhooks to the old IP until DNS propagates, make the old Apache reverse-proxy `/api/` to the new box during the transition so no callbacks land in the old database.
21. Rollback: keep the old droplet intact for at least two weeks; rollback is DNS back + replay of any `cache_*` rows received on the new box.

## Not Covered by Automated Tests

Verify manually on the new droplet: push notifications (`api/notifications`, APNs over HTTP/2 + ES256 JWT), S3 upload/backup (`S3.php` signature v4), weather fetch, `api/connectstats/sync` and `api/garmin/backup` (system auth), `runmaintenance.php` and cleanup scripts, `bugreport/` upload and `list.php` admin.

## Sequencing With Other Open Work

- **SQL injection in `authenticate_header()`** (`shared.php:1092`, `oauth_token` header value interpolated into SQL). Not addressed by the PHP patch; hotfix on prod independently of this upgrade.
- **Master is not deployable**: it includes `lib/Auth.php`, which was never committed. Fix (commit or revert) before branching upgrade work from master, otherwise base the upgrade branch on `prod`.
- **Garmin OAuth 1.0 retirement (Dec 31, 2026)**, see [oauth2-migration](oauth2-migration.md). Build and deploy that work on the new droplet rather than the 20.04 box, so it is validated once on the final stack.
