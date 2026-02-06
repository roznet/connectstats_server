# Database Schema

## Overview

The server uses MySQL with automatic schema management. Tables are created/altered automatically when the API is first accessed. Schema version is tracked in the `schema` table.

**Current Schema Version:** 8 (for main database tables) / 2 (for queue database tables)

## Core Tables

### schema
Tracks the current schema version for migrations.

```sql
CREATE TABLE schema (
    ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    version BIGINT(20) UNSIGNED
);
```

### users

Stores internal user IDs mapped to Garmin userIds.

```sql
CREATE TABLE users (
    cs_user_id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    userId VARCHAR(128),           -- Garmin userId
    ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### tokens

Stores OAuth tokens for Garmin API access.

```sql
CREATE TABLE tokens (
    token_id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cs_user_id BIGINT(20) UNSIGNED,
    userAccessToken VARCHAR(128),
    userId VARCHAR(128),
    userAccessTokenSecret VARCHAR(128),
    ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### activities

Stores activity summaries received from Garmin.

```sql
CREATE TABLE activities (
    activity_id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cs_user_id BIGINT(20) UNSIGNED,
    file_id BIGINT(20) UNSIGNED,   -- Link to FIT file
    ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    json TEXT,                     -- Full Garmin JSON payload (or partial)
    startTimeInSeconds BIGINT(20) UNSIGNED,
    userId VARCHAR(128),
    userAccessToken VARCHAR(128),
    summaryId VARCHAR(128),        -- Garmin's unique ID
    created_ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    parent_activity_id BIGINT(20) UNSIGNED -- For multi-sport activities
);
```

### fitfiles

Stores FIT file metadata.

```sql
CREATE TABLE fitfiles (
    file_id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    activity_id BIGINT(20) UNSIGNED,
    asset_id BIGINT(20) UNSIGNED,
    cs_user_id BIGINT(20) UNSIGNED,
    fileType VARCHAR(16),
    ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    userId VARCHAR(128),
    userAccessToken VARCHAR(128),
    callbackURL TEXT,              -- Garmin download URL
    startTimeInSeconds BIGINT(20) UNSIGNED,
    summaryId VARCHAR(128),
    created_ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### assets

Stores binary file data (when not using S3/local filesystem).

```sql
CREATE TABLE assets (
    asset_id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    file_id BIGINT(20) UNSIGNED,
    ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    tablename VARCHAR(128),
    filename VARCHAR(32),
    path VARCHAR(128),
    data MEDIUMBLOB,               -- Binary FIT file data
    created_ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### assets_s3

Stores references to files stored externally (S3 or local filesystem).
NOTE: The current code's schema definition for `assets_s3` is identical to `assets`, including a `MEDIUMBLOB` data field. This contradicts the intention of storing only references and the name `_s3`. The `path` field is used to store the S3 path or local path.

```sql
CREATE TABLE assets_s3 (
    asset_id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    file_id BIGINT(20) UNSIGNED,
    ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    tablename VARCHAR(128),
    filename VARCHAR(32),
    path VARCHAR(128),             -- S3 object path (e.g., s3:assets/users/...) or local filesystem path
    data MEDIUMBLOB,               -- (NOTE: This field's presence here contradicts the "references" purpose. It's identical to 'assets' table in code.)
    created_ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

### fitsession

Stores extracted FIT session data.

```sql
CREATE TABLE fitsession (
    file_id BIGINT(20) UNSIGNED PRIMARY KEY,
    cs_user_id BIGINT(20) UNSIGNED,
    ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    json TEXT                      -- Extracted session JSON
);
```

### weather

Stores weather data for activities.

```sql
CREATE TABLE weather (
    file_id BIGINT(20) UNSIGNED PRIMARY KEY,
    cs_user_id BIGINT(20) UNSIGNED,
    ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    json TEXT                      -- Weather API response (JSON string)
);
```

## Cache Tables

Temporary storage for incoming webhooks before processing.

### cache_activities

```sql
CREATE TABLE cache_activities (
    cache_id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    started_ts DATETIME,
    processed_ts DATETIME,
    json MEDIUMTEXT                -- Raw Garmin webhook JSON
);
```

### cache_activities_map

Links cache entries to processed activities.

```sql
CREATE TABLE cache_activities_map (
    activity_id BIGINT(20) UNSIGNED PRIMARY KEY,
    cache_id BIGINT(20) UNSIGNED,
    ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

### cache_fitfiles

Same structure as cache_activities for FIT file webhooks.

```sql
CREATE TABLE cache_fitfiles (
    cache_id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    started_ts DATETIME,
    processed_ts DATETIME,
    json MEDIUMTEXT
);
```

### cache_fitfiles_map

Links cache entries to processed FIT files.

```sql
CREATE TABLE cache_fitfiles_map (
    file_id BIGINT(20) UNSIGNED PRIMARY KEY,
    cache_id BIGINT(20) UNSIGNED,
    ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

## Usage Tracking Tables

### usage

Logs all API calls.

```sql
CREATE TABLE `usage` (
    usage_id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    cs_user_id BIGINT(20) UNSIGNED,
    status INT UNSIGNED,           -- HTTP status or internal status code
    REQUEST_URI VARCHAR(256),      -- Full request URI
    SCRIPT_NAME VARCHAR(256)       -- Script executed
);
```

### users_usage

Tracks user engagement metrics.

```sql
CREATE TABLE users_usage (
    cs_user_id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    days BIGINT(20) UNSIGNED,      -- Number of active days
    last_ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    first_ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

## Notification Tables

### notifications_devices

Registered devices for push notifications.
NOTE: This table uses `device_token` as primary key.

```sql
CREATE TABLE notifications_devices (
    device_token VARCHAR(128) PRIMARY KEY,
    cs_user_id BIGINT(20) UNSIGNED,
    enabled INT,                   -- 1=active, 0=disabled
    push_type INT,                 -- Type of push notification
    ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    create_ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    INDEX (cs_user_id)
);
```

### notifications

Tracks sent notifications.

```sql
CREATE TABLE notifications (
    notification_id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    device_token VARCHAR(128),
    cs_user_id BIGINT(20) UNSIGNED,
    status INT,                    -- HTTP status from APNS or internal status
    apnid VARCHAR(128),            -- APNS response ID
    ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    create_ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    received_ts TIMESTAMP          -- Timestamp when APNS confirmed receipt
);
```

### notifications_activities

Links notifications to activities.

```sql
CREATE TABLE notifications_activities (
    activity_id BIGINT(20) UNSIGNED PRIMARY KEY,
    notification_id BIGINT(20) UNSIGNED,
    ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    create_ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

## Queue Tables (Separate Database)

Schema for tables managed by `api/queue.php`.

### tasks

Background job queue.

```sql
CREATE TABLE tasks (
    task_id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    queue_id BIGINT(20) UNSIGNED DEFAULT NULL, -- Processor ID (for locking)
    task_command VARCHAR(512),     -- Command to execute (e.g., 'php runactivities.php 123')
    task_cwd VARCHAR(128),         -- Current working directory for task execution
    exec_status INT UNSIGNED,      -- Exit status of the executed command
    ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    created_ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    started_ts DATETIME,
    finished_ts DATETIME,
    not_before_ts DATETIME         -- Delay execution until this timestamp
);
```

### queues

Tracks active queue processors (formerly `queue_processor`).

```sql
CREATE TABLE queues (
    queue_id BIGINT(20) AUTO_INCREMENT PRIMARY KEY,
    queue_index BIGINT(20) UNSIGNED DEFAULT NULL, -- Index of the queue process (0 to N-1)
    queue_pid BIGINT(20) UNSIGNED DEFAULT NULL,   -- OS Process ID
    created_ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    heartbeat_ts DATETIME,         -- Last time processor checked in
    status VARCHAR(16),            -- e.g., 'running', 'stop', 'dead:timeout'
    ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
);
```

## Error Tables

Dynamic error tables created per feature:

```sql
-- Created automatically: error_activities, error_fitfiles, etc.
CREATE TABLE error_{table} (
    error_id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cache_id BIGINT(20) UNSIGNED,
    json MEDIUMTEXT,               -- Original payload that caused error
    message TEXT,                  -- Human-readable error message
    user_agent TEXT,               -- User agent of the request (if applicable)
    remote_addr TEXT,              -- IP address of the client (if applicable)
    ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

## Schema Management

Schema is managed by `GarminProcess::ensure_schema()` and `Queue::ensure_schema()`:

```php
// Checks schema version and applies migrations
$this->ensure_schema();

// Creates or alters tables as needed
$this->sql->create_or_alter('table_name', array(
    'field_name' => 'FIELD_TYPE',
    // ...
));
```

## Entity Relationships

```
users (1) ─────────────── (N) tokens
  │
  └──────────────────────── (N) activities ─── (1) fitfiles
                                    │              │
                                    │              ├── (1) assets / assets_s3 (NOTE: assets_s3 schema identical to assets in code)
                                    │              ├── (1) fitsession
                                    │              └── (1) weather
                                    │
                                    └────────────── (N) cache_activities_map
```

## Useful Queries

See `setup/README.md` for administrative queries:

- User activity counts
- Daily usage statistics
- Task execution times
- Activity/weather joins
