# Architecture

## Overview

ConnectStats Server is a PHP/MySQL backend that serves as an integration layer between the ConnectStats iOS app and the Garmin Health API. The server processes webhook callbacks from Garmin, stores activity data and FIT files, and provides authenticated APIs for the mobile app.

## System Components

### 1. API Layer (`/api/`)

Entry points for all HTTP requests:

```
api/
├── connectstats/          # App-facing endpoints
│   ├── user_register.php  # Register user with OAuth tokens
│   ├── validateuser.php   # Validate user token
│   ├── search.php         # Query activities list
│   ├── file.php           # Download FIT files
│   ├── json.php           # Get weather/session JSON
│   └── sync.php           # Maintenance/backup script (system authenticated)
├── garmin/                # Garmin webhook receivers and internal queue processors
│   ├── activities.php     # Activity callback (webhook receiver)
│   ├── file.php           # FIT file callback (webhook receiver)
│   ├── deregistration.php # User deregistration (webhook receiver)
│   ├── runactivities.php  # Internal script: processes activity queue
│   ├── runfitfiles.php    # Internal script: processes FIT file queue
│   ├── runcallback.php    # Internal script: downloads file from callback URL
│   └── runfitextract.php  # Internal script: extracts FIT session data
├── notifications/         # Push notifications
│   ├── register.php       # Register device for notifications
│   ├── activity.php       # Internal script: pushes notifications for new activities
│   └── push.php           # Internal script: pushes notifications to a user's devices (CLI)
├── status/                # Health checks
│   ├── api.php            # API health check
│   ├── app.php            # App configuration status
│   ├── usage.php          # Usage statistics
│   └── cache.php          # Cache status health check
└── queue/                 # Queue management
```

### 2. Core Classes

#### GarminProcess (`shared.php`)
The main business logic class (~2800 lines):
- User registration and validation
- Activity caching and processing
- FIT file download and extraction
- OAuth 1.0 signature generation/verification
- Weather data queries
- Push notifications
- Database schema management

#### sql_helper (`sql_helper.php`)
Database abstraction layer:
- Connection management
- Query execution with error handling
- Schema creation/alteration
- Insert/update operations

#### Queue (`queue.php`)
Background job management:
- Task creation and scheduling
- Task execution and status tracking
- Processor heartbeat monitoring

#### S3 (`S3.php`)
Amazon S3 integration:
- File upload/download
- Bucket management

### 3. Data Flow

#### Incoming Activity (Garmin → Server)

```
1. Garmin Health API POST → /api/garmin/activities
2. Server saves JSON to cache_activities table
3. Server queues task: "php runactivities.php {cache_id}"
4. Background (via queue processor): runactivities.php processes cache entry
5. Background: Inserts/updates activities table
6. Background: Links to existing FIT files if available
```

#### Incoming FIT File (Garmin → Server)

```
1. Garmin Health API POST → /api/garmin/file
2. Server saves JSON to cache_fitfiles table
3. Server queues task: "php runfitfiles.php {cache_id}"
4. Background (via queue processor): runfitfiles.php processes, queues callback task
5. Background (via queue processor): runcallback.php downloads file via callbackURL
6. Background: Stores in S3 or database
7. Background (via queue processor): runfitextract.php extracts session data
```

#### App Requesting Activities (App → Server)

```
1. App sends GET /api/connectstats/search
   - Authorization header with OAuth 1.0 signature
   - token_id parameter
2. Server verifies signature against stored secret
3. Server queries activities for user's cs_user_id
4. Server returns JSON activity list
```

## Request Processing Pattern

Every API endpoint follows this pattern:

```php
include_once('../shared.php');

$process = new GarminProcess();

if (isset($_GET['token_id'])) {
    $token_id = intval($_GET['token_id']);

    // Verify OAuth signature
    $process->authenticate_header($token_id);

    // Create paging object for query params
    $paging = new Paging($_GET, $token_id, $process->sql);

    // Execute business logic
    $process->query_activities($paging);

    // Log API usage
    $process->record_usage($paging);
}
```

## Background Processing

The queue system decouples webhook reception from processing:

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│   Webhook   │────▶│    Cache    │────▶│    Queue    │
│   Receiver  │     │    Table    │     │    Task     │
└─────────────┘     └─────────────┘     └─────────────┘
                                               │
                                               ▼
                                        ┌─────────────┐
                                        │  runqueue   │
                                        │   (cron)    │
                                        └─────────────┘
                                               │
                                               ▼
                                        ┌─────────────┐
                                        │  Process &  │
                                        │   Store     │
                                        └─────────────┘
```

## Configuration

Configuration in `api/config.php`:

```php
$api_config = array(
    // Database
    'database' => 'connectstats_db',
    'db_host' => 'localhost',
    'db_username' => 'user',
    'db_password' => 'pass',
    'db_queue' => 'queue_db',  // Separate DB for queue

    // Garmin API
    'consumerKey' => 'garmin_key',
    'consumerSecret' => 'garmin_secret',

    // Internal auth
    'serviceKey' => 'internal_key',
    'serviceKeySecret' => 'internal_secret',

    // Storage
    'save_to_s3_bucket' => 'bucket-name',
    's3_access_key' => 'aws_key',
    's3_secret_key' => 'aws_secret',

    // Weather (optional)
    'visualCrossingKey' => 'weather_key',
);
```

## Deployment

- Apache with `.htaccess` URL rewriting
- PHP with MySQLi, cURL, OpenSSL extensions
- MySQL/MariaDB database
- Cron job for queue processing
- Optional: S3 bucket for file storage

## Multi-Environment Support

The server supports multiple instances for dev/prod:

```
/var/www/html/
├── dev/
│   └── config.php  → dev database, dev Garmin keys
└── prod/
    └── config.php  → prod database, prod Garmin keys
```
