# Testing

> Python test suite for validating the ConnectStats server: schema integrity, API behavior, and full integration.

## Intent

Provide confidence when making code changes. Two modes address different scenarios:
- **Non-destructive** (`schema`, `smoke`, `all`): safe to run against a production-like DB — verifies the existing database and API still work after code changes
- **Destructive** (`full`): resets everything and rebuilds from scratch — CI-like validation that the entire pipeline works end-to-end

## Running Tests

```bash
# Activate the venv
source ~/.venv/dev/bin/activate

# From the setup/ directory:
python3 test.py schema          # DB schema validation (non-destructive)
python3 test.py smoke           # API endpoint checks (non-destructive)
python3 test.py all             # schema + smoke (non-destructive)
python3 test.py full            # Reset DB, rebuild, validate (DESTRUCTIVE)

# Options
python3 test.py full -v                        # Verbose: see each test + PHP output
python3 test.py smoke --base-url http://host   # Custom server URL
python3 test.py schema --config /path/config   # Custom config.php path
```

Exit codes: `0` = all pass, `1` = failures, `2` = config/setup error.

## Configuration

### The config.php Requirement

Both the **test runner** (Python) and the **server** (PHP) parse the same `config.php`. The test runner defaults to `<project_root>/api/config.php`. The server uses whichever `config.php` is in its deployed directory.

**Critical**: the `config.php` used by the test runner and the one used by the server **must point to the same database**. If they diverge, tests will validate the wrong data.

Typical setup for local development:
- Server code deployed at `~/Developer/web/dev/` (via `git pull`)
- Test runner runs from source at `<repo>/setup/`
- Both need an `api/config.php` with matching DB credentials

Required config keys: `consumerKey`, `consumerSecret`, `serviceKey`, `serviceKeySecret`, `db_host`, `db_username`, `db_password`, `database`, `db_queue`.

### Server Code vs Local Code

If the web server serves directly from the source repo (same directory), there's no sync issue — code changes take effect immediately and `config.php` is shared.

The complexity only arises when the server runs from a **separate deployed directory** (e.g., `~/Developer/web/dev/` synced via `git pull`). In that case, after changing PHP files:

1. Commit changes in the source repo
2. `git pull` in the deployed directory
3. Then run tests

If you skip step 2, the server still runs old code and tests may fail for the wrong reasons. The `full` test is especially sensitive — it calls `reset` and `user_register` via HTTP (server code) but runs `runactivities.php` and `runfitfiles.php` via subprocess (local code). Both must be in sync. Similarly, `config.php` must exist in both locations and point to the same database.

### The `full` Test and PHP Subprocesses

The `full` test runs `runactivities.php` and `runfitfiles.php` directly via `php` subprocess from `<project_root>/api/garmin/`. These scripts do `include('config.php')` with a relative path, so `config.php` must exist at `<project_root>/api/config.php` for the subprocess to find it. (This is only relevant when running tests from a different directory than the server.)

## Architecture

```
setup/
  test.py                  # Entry point (argparse, mode dispatch)
  connectstats_test/
    config.py              # Config dataclass, PHP config parser, DB context managers
    client.py              # ConnectStatsClient: OAuth-signed HTTP, JSON parsing
    runner.py              # TestSuite: result tracking, colored ANSI output
    test_schema.py         # Non-destructive DB checks
    test_smoke.py          # Non-destructive API checks
    test_full.py           # Destructive integration test
```

No external test framework — lightweight custom runner matching project simplicity. `query.py` remains the standalone CLI tool (unchanged).

## What Each Mode Tests

### `schema` — DB Structure (44 tests)

Read-only checks against the database, no HTTP requests:
- `dev` marker table exists (safety check for `reset_schema`)
- All 15 `ensure_schema` tables exist
- Schema version >= 8
- Key columns on critical tables (`activities.parent_activity_id`, `tokens.userAccessTokenSecret`, `fitfiles.callbackURL`, etc.)
- AUTO_INCREMENT on primary keys that need it (9 tables)
- Queue DB: `tasks`, `queues`, `schema` tables exist, version >= 2

### `smoke` — API Endpoints (7 tests)

Requires at least one user with token_id 1 and 2 in the DB:
- Unauthenticated request → 401
- Wrong token (token 2 for token 1's data) → 401
- Authenticated search → valid JSON with `activityList`
- JSON endpoint (`table=fitsession`) → valid JSON
- `validateuser` → JSON with `token_id`
- File with invalid `activity_id` → graceful error (not 500)

### `full` — End-to-End Integration (57 tests)

Destructive — resets the database, then:
1. **Reset**: `GET /api/connectstats/reset` with system auth (requires `serviceKey`)
2. **Register**: 2 test users via `/api/connectstats/user_register`
3. **Upload**: POST `sample-backfill-activities.json` (24 activities) and `sample-file-local.json` to Garmin webhook endpoints
4. **Process**: Wait for queue worker (polls `processed_ts`), fall back to running `runactivities.php` / `runfitfiles.php` directly
5. **Validate**: Fetch via `/api/connectstats/search`, verify activity count (24), all summaryIds present, parent/child multi-sport links
6. **Auth**: Wrong token still rejected after rebuild
7. **Schema**: Runs all schema checks to confirm tables are correct after rebuild

## Key Design Choices

### Queue-Aware Processing

The webhook POST triggers queue tasks that may be processed by a running queue worker before the test gets to subprocess execution. The test polls `cache_activities.processed_ts` / `cache_fitfiles.processed_ts` (up to 10s) and only runs PHP scripts for unprocessed caches. This avoids duplicate-entry errors.

### Tolerant JSON Parsing

PHP can append HTML error output after valid JSON (e.g., deprecation warnings). The client uses `json.JSONDecoder.raw_decode()` as a fallback to extract the JSON prefix, preventing false test failures from non-fatal PHP warnings.

### OAuth Signing

`ConnectStatsClient` replicates the OAuth 1.0 HMAC-SHA1 signing from `query.py`. It reads tokens from the DB (cached per session) and signs requests identically to the iOS app. System calls use `serviceKey`/`serviceKeySecret` instead.

### Sample Data

- `sample-backfill-activities.json`: 24 activities including a multi-sport (parent `4018666911` with 3 children). Timestamp `1557209607` is patched to current time.
- `sample-file-local.json`: FIT file notification pointing to a local callback URL.

## Gotchas

- **Deploy before testing**: server code and local code must match, especially for `full` mode
- **`config.php` in source repo**: needed for PHP subprocess calls in `full` mode — not committed to git, must be created manually (copy from `config.sample.php`)
- **Queue worker interaction**: if a queue worker is running, it may process caches before the test does — this is handled but can affect timing
- **`dev` table required**: `reset_schema()` refuses to drop tables without the `dev` marker table — create it manually on first setup (`CREATE TABLE dev (id INT)`)
- **Venv required**: always use `~/.venv/dev/bin/activate` — needs `mysql-connector-python` and `urllib3`

## References

- Entry point: `setup/test.py`
- Test package: `setup/connectstats_test/`
- Sample data: `setup/sample-backfill-activities.json`, `setup/sample-file-local.json`
- Schema definitions: `api/shared.php` (`ensure_schema`, `reset_schema`)
- Queue schema: `api/queue.php` (`ensure_schema`)
- Related: [database-schema](./database-schema.md), [api-endpoints](./api-endpoints.md), [authentication](./authentication.md)
