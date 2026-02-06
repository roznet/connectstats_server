# Backfill System Design

## Overview

The backfill system allows retrieval of historical activity data from Garmin Health API for users who register with ConnectStats. This addresses the limitation that Garmin only pushes new activities via webhooks - historical data must be explicitly requested.

## Design Goals

1. **Automatic initial backfill**: 3 months of history after user registration
2. **User-initiated extended backfill**: Up to 2 years total, triggered from the app
3. **Cost-conscious**: Minimize storage for users who try the app but don't return
4. **Reliable**: Track backfill state to avoid duplicate requests and handle failures

## Workflow

### Initial Backfill (Server-Initiated)

```
┌─────────────────┐     ┌──────────────────┐     ┌─────────────────┐
│  User registers │────▶│  Server waits    │────▶│  Trigger 3-month│
│  (OAuth flow)   │     │  for first sync  │     │  backfill       │
└─────────────────┘     └──────────────────┘     └─────────────────┘
                                                          │
                        ┌──────────────────┐              │
                        │  Garmin sends    │◀─────────────┘
                        │  data via webhook│
                        └──────────────────┘
                                 │
                        ┌────────▼─────────┐
                        │  Normal webhook  │
                        │  processing      │
                        └──────────────────┘
```

**Trigger condition**: First successful `/api/connectstats/search` call after registration (indicates user is actively using the app).

### Extended Backfill (User-Initiated)

```
┌─────────────────┐     ┌──────────────────┐     ┌─────────────────┐
│  User taps      │────▶│  App calls       │────▶│  Server requests│
│  "Load More"    │     │  backfill API    │     │  next 3-month   │
└─────────────────┘     └──────────────────┘     │  window         │
                                                 └─────────────────┘
```

**Constraints**:
- Maximum 2 years total backfill (8 × 3-month windows)
- Only one pending backfill request per user at a time
- Minimum 1 hour between backfill requests (rate limiting)

## Database Schema

### New Table: `backfills`

```sql
CREATE TABLE backfills (
    backfill_id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY,
    cs_user_id BIGINT(20) UNSIGNED NOT NULL,
    token_id BIGINT(20) UNSIGNED NOT NULL,

    -- Time window requested (epoch seconds)
    window_start BIGINT(20) UNSIGNED NOT NULL,
    window_end BIGINT(20) UNSIGNED NOT NULL,

    -- Garmin job tracking
    job_id VARCHAR(128),

    -- Status: pending, completed, failed, expired
    status VARCHAR(32) DEFAULT 'pending',
    error_message TEXT,

    -- Timestamps
    requested_ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
    completed_ts DATETIME,

    -- Tracking
    activities_received INT UNSIGNED DEFAULT 0,
    files_received INT UNSIGNED DEFAULT 0,

    INDEX idx_user_status (cs_user_id, status),
    INDEX idx_token (token_id),
    INDEX idx_job (job_id)
);
```

### Modified Table: `users`

Add field to track backfill eligibility:

```sql
ALTER TABLE users ADD COLUMN initial_backfill_done TINYINT(1) DEFAULT 0;
ALTER TABLE users ADD COLUMN backfill_eligible_ts DATETIME;
```

## API Endpoints

### 1. Check Backfill Status

**Endpoint**: `GET /api/connectstats/backfill_status`

**Authentication**: OAuth 1.0 header (existing)

**Parameters**:
- `token_id`: User's token ID

**Response**:
```json
{
    "initial_backfill_done": true,
    "total_months_backfilled": 3,
    "max_months_allowed": 24,
    "can_request_more": true,
    "pending_request": null,
    "oldest_activity_date": "2024-01-15",
    "backfill_history": [
        {
            "window_start": 1704067200,
            "window_end": 1711929600,
            "status": "completed",
            "activities_received": 45
        }
    ]
}
```

### 2. Request Extended Backfill

**Endpoint**: `POST /api/connectstats/backfill_request`

**Authentication**: OAuth 1.0 header (existing)

**Parameters**:
- `token_id`: User's token ID
- `months`: Number of additional months to backfill (default: 3, max: 3)

**Response** (success):
```json
{
    "status": "requested",
    "backfill_id": 123,
    "window_start": 1696118400,
    "window_end": 1704067200,
    "message": "Backfill requested. Activities will appear shortly."
}
```

**Response** (error):
```json
{
    "error": "backfill_pending",
    "message": "A backfill request is already in progress.",
    "pending_since": "2024-01-20T10:30:00Z"
}
```

## Server Implementation

### 1. Trigger Initial Backfill

Location: `api/shared.php` - modify `search_activities()` or create wrapper

```php
function maybe_trigger_initial_backfill($token_id) {
    // Check if initial backfill already done
    $user = $this->sql->query_first_row(
        "SELECT u.cs_user_id, u.initial_backfill_done, t.token_id
         FROM users u
         JOIN tokens t ON u.cs_user_id = t.cs_user_id
         WHERE t.token_id = $token_id"
    );

    if ($user && !$user['initial_backfill_done']) {
        $this->request_backfill($token_id, 3); // 3 months
        $this->sql->execute_query(
            "UPDATE users SET initial_backfill_done = 1
             WHERE cs_user_id = {$user['cs_user_id']}"
        );
    }
}
```

### 2. Request Backfill from Garmin

Location: `api/lib/Backfill.php` (new file)

```php
class Backfill {
    private $sql;
    private $auth;
    private $api_config;

    const SECONDS_PER_MONTH = 2592000; // 30 days
    const MAX_MONTHS = 24;
    const WINDOW_MONTHS = 3;

    function request_backfill($token_id, $months = 3) {
        // Calculate time window
        $end_time = $this->get_earliest_backfill_end($token_id);
        $start_time = $end_time - ($months * self::SECONDS_PER_MONTH);

        // Check limits
        if (!$this->can_request_backfill($token_id, $start_time)) {
            return ['error' => 'limit_reached'];
        }

        // Get user token
        $token = $this->sql->query_first_row(
            "SELECT * FROM tokens WHERE token_id = $token_id"
        );

        // Build Garmin backfill URL
        $url = sprintf(
            'https://healthapi.garmin.com/wellness-api/rest/backfill/activities?summaryStartTimeInSeconds=%d&summaryEndTimeInSeconds=%d',
            $start_time,
            $end_time
        );

        // Make OAuth-signed request to Garmin
        $response = $this->auth->get_url_data(
            $url,
            $token['userAccessToken'],
            $token['userAccessTokenSecret']
        );

        // Parse job ID from response
        $result = json_decode($response, true);
        $job_id = $result['jobId'] ?? null;

        // Record backfill request
        $this->sql->insert_or_update('backfills', [
            'cs_user_id' => $token['cs_user_id'],
            'token_id' => $token_id,
            'window_start' => $start_time,
            'window_end' => $end_time,
            'job_id' => $job_id,
            'status' => 'pending'
        ]);

        return [
            'status' => 'requested',
            'backfill_id' => $this->sql->insert_id(),
            'window_start' => $start_time,
            'window_end' => $end_time
        ];
    }

    function get_earliest_backfill_end($token_id) {
        // Find the earliest window we've already backfilled
        $row = $this->sql->query_first_row(
            "SELECT MIN(window_start) as earliest
             FROM backfills
             WHERE token_id = $token_id AND status = 'completed'"
        );

        if ($row && $row['earliest']) {
            return $row['earliest'];
        }

        // No backfill yet - start from now
        return time();
    }

    function can_request_backfill($token_id, $proposed_start) {
        // Check max 2 years limit
        $two_years_ago = time() - (self::MAX_MONTHS * self::SECONDS_PER_MONTH);
        if ($proposed_start < $two_years_ago) {
            return false;
        }

        // Check no pending request
        $pending = $this->sql->query_first_row(
            "SELECT backfill_id FROM backfills
             WHERE token_id = $token_id AND status = 'pending'"
        );
        if ($pending) {
            return false;
        }

        // Check rate limit (1 hour between requests)
        $recent = $this->sql->query_first_row(
            "SELECT backfill_id FROM backfills
             WHERE token_id = $token_id
             AND requested_ts > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
        );
        if ($recent) {
            return false;
        }

        return true;
    }
}
```

### 3. Track Backfill Completion

When activities arrive via webhook, check if they're from a backfill and update tracking.

Location: `api/shared.php` - modify `process()` for activities

```php
function update_backfill_tracking($token_id, $activity_start_time) {
    // Find matching pending backfill
    $backfill = $this->sql->query_first_row(
        "SELECT backfill_id, window_start, window_end
         FROM backfills
         WHERE token_id = $token_id
         AND status = 'pending'
         AND $activity_start_time BETWEEN window_start AND window_end"
    );

    if ($backfill) {
        $this->sql->execute_query(
            "UPDATE backfills
             SET activities_received = activities_received + 1
             WHERE backfill_id = {$backfill['backfill_id']}"
        );
    }
}
```

### 4. Backfill Completion Detection

Garmin doesn't send a "backfill complete" signal. Options:

**Option A**: Mark complete after timeout (e.g., 1 hour with no new activities in window)
```php
// Cron job: check for stale pending backfills
function check_backfill_completion() {
    $this->sql->execute_query(
        "UPDATE backfills
         SET status = 'completed', completed_ts = NOW()
         WHERE status = 'pending'
         AND requested_ts < DATE_SUB(NOW(), INTERVAL 1 HOUR)
         AND activities_received > 0"
    );
}
```

**Option B**: Mark complete when user requests next window (assumes previous is done)

## iOS App Changes

### 1. Settings Screen Addition

Add "Activity History" section:

```
┌─────────────────────────────────────┐
│ Activity History                    │
├─────────────────────────────────────┤
│ Oldest activity: Jan 15, 2024       │
│ History loaded: 3 months            │
│                                     │
│ [Load More History]                 │
│ (Up to 2 years available)           │
└─────────────────────────────────────┘
```

### 2. API Calls

```objc
// Check backfill status
- (void)checkBackfillStatus {
    GCConnectStatsRequest *req = [GCConnectStatsRequest
        requestWithPath:@"backfill_status"
        parameters:@{@"token_id": self.tokenId}];
    // ...
}

// Request more history
- (void)requestMoreHistory {
    GCConnectStatsRequest *req = [GCConnectStatsRequest
        requestWithPath:@"backfill_request"
        method:@"POST"
        parameters:@{@"token_id": self.tokenId, @"months": @3}];
    // ...
}
```

## Error Handling

### Garmin API Errors

| Error | Action |
|-------|--------|
| 401 Unauthorized | Mark token invalid, user needs to re-auth |
| 429 Rate Limited | Retry after delay, mark backfill as pending |
| 500 Server Error | Retry with exponential backoff |
| Timeout | Mark as failed, allow retry |

### Edge Cases

1. **User deregisters during backfill**: Cancel pending backfill, mark as failed
2. **Token expires during backfill**: Mark as failed, backfill resumes after re-auth
3. **Duplicate backfill request**: Return existing pending request info
4. **No activities in window**: Mark as completed with 0 activities

## Configuration

Add to `config.php`:

```php
// Backfill settings
'backfill_initial_months' => 3,
'backfill_max_months' => 24,
'backfill_window_months' => 3,
'backfill_rate_limit_hours' => 1,
'backfill_completion_timeout_hours' => 1,

// Garmin backfill endpoints
'url_backfill_activities' => 'https://healthapi.garmin.com/wellness-api/rest/backfill/activities',
'url_backfill_activityDetails' => 'https://healthapi.garmin.com/wellness-api/rest/backfill/activityDetails',
```

## Implementation Phases

### Phase 1: Database & Core Logic
1. Add `backfills` table to schema
2. Create `Backfill.php` class
3. Implement `request_backfill()` function
4. Add backfill status endpoint

### Phase 2: Automatic Initial Backfill
1. Hook into first search call
2. Trigger 3-month backfill
3. Track completion

### Phase 3: User-Initiated Backfill
1. Add `backfill_request` endpoint
2. Implement rate limiting and validation
3. iOS app UI for requesting more history

### Phase 4: Monitoring & Cleanup
1. Add cron job for completion detection
2. Add admin endpoint to view backfill stats
3. Cleanup old failed backfill records

## Testing

### Unit Tests
- `can_request_backfill()` with various states
- Time window calculations
- Rate limiting logic

### Integration Tests
- Full backfill request flow (mock Garmin API)
- Webhook processing with backfill tracking
- Completion detection

### Manual Testing
1. Register new user → verify 3-month backfill triggered
2. Wait for activities to arrive
3. Request extended backfill from app
4. Verify rate limiting works
5. Test error scenarios (invalid token, etc.)

## Monitoring

Track these metrics:
- Backfill requests per day
- Average activities per backfill
- Failure rate
- Storage impact per user

## Storage Considerations

Estimated storage per user for 2 years of data:
- Activities: ~730 records × 2KB = ~1.5MB
- FIT files: ~730 files × 100KB = ~73MB (S3)
- Weather data: ~730 records × 1KB = ~730KB

**Total**: ~75MB per active user for full 2-year backfill

For 1000 users with full backfill: ~75GB (mostly in S3)
