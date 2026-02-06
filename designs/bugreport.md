# Bug Reporting System

> In-app bug report submission, storage, and admin review for ConnectStats iOS app.

## Intent

Lets users submit bug reports directly from the iOS app. The app uploads a zip file containing logs and databases, then the user fills in a description and optional email. An admin dashboard lets you browse, view parsed logs, reply to users, and clean up old data.

This is a self-contained subsystem in `bugreport/` with its own database config and `BugReport` class (defined twice: once for submission in `bugreport.php`, once for admin viewing in `list.php`).

## Architecture

### Endpoints

| File | Role | Access |
|------|------|--------|
| `new.php` | Submission form + processing | Public (from iOS app) |
| `status.php` | JSON status check (enabled/disabled, min version) | Public (app polls this) |
| `list.php` | Admin dashboard for browsing/viewing reports | Admin |
| `export.php` | Download zip or JSON for a report by `id` | Admin |
| `clean.php` | Delete orphaned files (no description/email) | CLI only |

### Core Class: `BugReport` (bugreport.php)

Handles the submission side:
- Constructor auto-creates `gc_bugreports`, `gc_app_status`, and `gc_minimum_version` tables if missing
- Loads app status (disabled state, minimum version) from DB
- `process()` dispatches between `save_bugreport()` (file upload) and `update_bugreport()` (form submit)
- `status()` returns JSON for the app to check before submitting
- `send_email_if_necesssary()` emails the report to a configured address via PHP `mail()`

### Admin Class: `BugReport` (list.php)

Separate class with the same name for the admin view:
- `run($getargs)` dispatches based on query params (`id`, `errors`, `commonid`, `all`, `needreply`, `nonblank`)
- `show_one_for_id()` opens the zip, lists files, parses `bugreport.log` with color-coded rendering
- `summary_with_errors()` scans all zips for `E ERR` lines and displays them inline
- `redirect_reply()` marks a report as replied and opens a `mailto:` link

## Submission Flow

Two-stage process driven by the iOS app:

1. **Stage 1 - File upload**: App POSTs to `new.php` with `$_FILES['file']` (zip) plus metadata fields (`version`, `platformString`, `systemName`, `systemVersion`, `applicationName`, `commonid`). Server saves zip to `bugs/YYYY/MM/bugreport_YYYYMMDD_<id>.zip` and creates a DB row.

2. **Stage 2 - Description form**: Server renders an HTML form with the `id`. User enters description + optional email, submits. Server updates the DB row, then optionally sends an email notification.

### Gatekeeping (checked before showing the form)

- **Minimum version**: `gc_minimum_version` table stores required app/system versions. Outdated apps get a "please update" message.
- **Disabled state**: `gc_app_status` table with enum `ok | disabled | message`. When disabled, form is replaced with a notice.

The `status.php` endpoint exposes this as JSON: `{"status": 1}` (ok) or `{"status": 0, "message": "..."}`.

## Data Model

### `gc_bugreports`

| Column | Type | Purpose |
|--------|------|---------|
| `id` | INT | Primary key (manually assigned as max+1) |
| `filename` | VARCHAR(256) | Relative path to zip in `bugs/` directory |
| `platformString` | VARCHAR(256) | Device model (e.g., "iPhone14,2") |
| `applicationName` | VARCHAR(256) | App name (default: "ConnectStats") |
| `systemName` | VARCHAR(256) | OS name |
| `systemVersion` | VARCHAR(256) | OS version |
| `description` | TEXT | User's bug description (filled in stage 2) |
| `version` | VARCHAR(256) | App version |
| `email` | VARCHAR(256) | Optional contact email |
| `commonid` | VARCHAR(256) | Groups related reports from same user/session |
| `filesize` | INT | Zip file size |
| `updatetime` | DATETIME | When the report was created |
| `replied` | DATETIME | When admin replied (set by `redirect_reply`) |

### `gc_app_status`

State enum (`ok`, `disabled`, `message`) with a text message. Latest row wins.

### `gc_minimum_version`

`app_minimum_version` and `system_minimum_version` as VARCHAR, plus a text message. Latest row wins.

## Log Parsing (list.php)

The admin view parses `bugreport.log` from inside the zip. Each line is matched against:
```
YYYY-MM-DD HH:MM:SS.sss pid LEVEL:filename:line:method; message
```

Parsed lines get:
- Color coding: info (black), warn (green), err (red bold), web (blue), start (bold)
- GitHub source links: `https://github.com/roznet/connectstats/blob/master/ConnectStats/src/<file>#L<line>`
- Duplicate collapsing: consecutive identical lines become `[REPEATED N]`
- Compact/full toggle: compact omits pid, shows shorter source references

The admin can also extract `.db` files from the zip and open them in the bundled `phpliteadmin/`.

## Key Choices

- **Two-stage submission**: The app uploads the file first, then the user fills in text. This keeps the file upload separate from user input and lets the server assign an ID before the form.
- **Manual ID assignment**: `id = max(id) + 1` rather than auto-increment. The `commonid` field links related reports.
- **Separate BugReport classes**: The submission and admin classes are in different files with different responsibilities. They share the same class name but are never loaded together.
- **CLI-only cleanup**: `clean.php` guards against web access and only runs from command line.

## Gotchas

- The two `BugReport` classes have the same name — never include both `bugreport.php` and `list.php` in the same request.
- `list.php:show_one_for_id()` uses `$_GET['id']` directly in a SQL query (line 178) without parameterization.
- `export.php` also uses `$_GET['id']` in a raw SQL query (line 46).
- File paths in `saved_file_name()` use `strftime` which is deprecated in PHP 8.1+.
- The email notification method is named `send_email_if_necesssary` (typo in the original).

## References

- Code: `bugreport/` directory
- Config sample: `bugreport/config_bugreport.sample.php`
- Bundled SQLite viewer: `bugreport/phpliteadmin/`
- Related: [api-endpoints](api-endpoints.md), [database-schema](database-schema.md)
