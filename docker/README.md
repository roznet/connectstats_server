# Docker stack

Three containers, defined in `docker-compose.yml` at the repo root:

| Service | Image | Role |
|---|---|---|
| `app` | `docker/Dockerfile` (`php:8.5-apache`, mod_php) | Serves the API at `/prod/...`. Never starts queue workers (`queue_autostart => false`) |
| `worker` | same image | Owns the queue: runs `queuectl.php start` every `QUEUE_CHECK_INTERVAL` seconds and scheduled jobs via `supercronic` (`CRONTAB`). Graceful stop on `docker compose stop worker` |
| `db` | `docker/mysql/Dockerfile` (MySQL 8.4) | Settings in `docker/mysql/connectstats.cnf`; first-run init creates the databases and the app user from `.env` |

Code lives at `/var/www/html/prod` and runtime dirs at `/var/www/{log,tmp,bugs,state,data}`, the same paths as the old droplet, so `task_cwd` values in replicated `queue.tasks` rows keep working.

## Configuration

Nothing secret is in the image. Compose takes three host paths from `.env`:

- `CONFIG_DIR`: `config.php`, `config_bugreport.php` and the APNs key, mounted read-only at `/etc/connectstats`. PHP finds them through `include_path` (`docker/php/connectstats.ini`), which lists `/etc/connectstats` first.
- `DATA_DIR`: persistent data (`mysql/`, `bugs/`, `state/`, plus `assets/` in dev only). On the droplet, the DigitalOcean Volume.
- `LOG_DIR`: per-task logs and cron logs (about 35k files a day in prod, 15-day cleanup in `docker/worker/crontab`).

tmp, including the S3 read cache (`s3_cache_local` → `/var/www/tmp/s3_cache`), is a named volume and safe to lose.

### Users and permissions

Every process that writes data runs as UID/GID 2000 (`APP_UID`/`APP_GID`): the image remaps `www-data`, and MySQL runs with `user: 2000:2000`. Only Apache's master process is root, so it can bind port 80. On the droplets, 2000 is the host user/group `dockerapp`, and brice is in that group. Apache and the worker use `umask 0002`, so files land group-writable and brice can manage them without sudo. The droplet layout is in `designs/droplet-migration.md` (Phase 3).

Config options added for this setup (see `api/config.sample.php`): `queue_autostart`, `queue_count`, `sandbox`.

## Local development

```bash
cp .env.sample .env          # also enables docker-compose.dev.yml
docker compose up -d --wait  # http://127.0.0.1:8080/prod/api/status/api
docker compose run --rm tests full   # destructive, local DB only
docker compose run --rm tests all    # schema + smoke
```

The dev overlay:
- bind-mounts the repo, so PHP edits are live (edits to `docker/worker/entrypoint.sh` need `docker compose build`)
- uses `docker/dev/config.php`: dummy secrets, local disk instead of S3, `sandbox` on
- serves a mock of Garmin's user-id endpoint (`docker/dev/mock`)
- exposes MySQL on `127.0.0.1:3307` (`connectstats` / `devpassword`)
- adds the `tests` service (image target `test`, with Python and the test dependencies)

Reset the local database: `docker compose down && rm -rf data/mysql`.

## Gotchas

- The `php:apache` base image sets `STOPSIGNAL SIGWINCH`; the worker overrides it with `stop_signal: SIGTERM` so its shutdown handler runs.
- Queue workers are tracked by PID (`ps ax`), so `queuectl.php` must run in the same container as the workers. Never run it with `docker compose exec app`.
- On Docker Desktop, a bind-mounted MySQL init script is reported as executable and fails to run, which is why init files are baked into the `db` image.
- Bind-mounting a single file into a bind-mounted checkout leaves an empty placeholder file on the host. That's why config comes in as a directory at `/etc/connectstats`.
