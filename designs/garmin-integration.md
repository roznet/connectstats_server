# Garmin Integration

## Overview

The server integrates with the [Garmin Health API](https://developer.garmin.com/health-api/overview/) to:
1. Receive activity data via webhooks
2. Download FIT files from Garmin's servers
3. Validate user OAuth tokens

## Webhook Configuration

Register these endpoints with Garmin Health API:

| Garmin Endpoint Type | Server URL |
|---------------------|------------|
| Activities | `https://{baseurl}/api/garmin/activities` |
| Manually Updated Activities | `https://{baseurl}/api/garmin/activities` |
| Activity Files | `https://{baseurl}/api/garmin/file` |
| Deregistration | `https://{baseurl}/api/garmin/deregistration` |

## Activity Webhook

### Trigger
Garmin calls this endpoint when:
- User completes a new activity
- Activity is manually updated (edited times, deleted, etc.)

### Payload Structure

```json
{
  "activities": [
    {
      "userId": "garmin_user_id",
      "userAccessToken": "oauth_access_token",
      "summaryId": "unique_activity_id",
      "activityId": 12345678,
      "activityType": "RUNNING",
      "startTimeInSeconds": 1609459200,
      "startTimeOffsetInSeconds": -28800,
      "durationInSeconds": 3600,
      "distanceInMeters": 10000.5,
      "activeKilocalories": 500,
      "averageHeartRateInBeatsPerMinute": 145,
      "maxHeartRateInBeatsPerMinute": 175,
      "averageSpeedInMetersPerSecond": 2.78,
      "maxSpeedInMetersPerSecond": 4.5,
      "steps": 8500,
      "parentSummaryId": null
    }
  ]
}
```

### Processing Flow

```
POST /api/garmin/activities
         │
         ▼
┌─────────────────────┐
│ Save to             │
│ cache_activities    │
└─────────────────────┘
         │
         ▼
┌─────────────────────┐
│ Queue task:         │
│ runactivities.php   │
└─────────────────────┘
         │
         ▼ (async)
┌─────────────────────┐
│ Parse JSON          │
│ Insert/update       │
│ activities table    │
└─────────────────────┘
         │
         ▼
┌─────────────────────┐
│ Link to fitfiles    │
│ if available        │
└─────────────────────┘
```

### Key Processing Logic

Location: `api/shared.php` - `process()` method

1. **Parse activities array** from JSON
2. **Lookup user** by `userAccessToken`
3. **For each activity**:
   - Check if `summaryId` already exists
   - Insert new or update existing record
   - Store full JSON for future reference
   - Link to FIT file if `startTimeInSeconds` matches

## Activity Files Webhook

### Trigger
Garmin calls this when a FIT file is ready for download.

### Payload Structure

```json
{
  "activityFiles": [
    {
      "userId": "garmin_user_id",
      "userAccessToken": "oauth_access_token",
      "summaryId": "activity_summary_id",
      "fileType": "FIT",
      "callbackURL": "https://healthapi.garmin.com/wellness-api/rest/backoffice/file/..."
    }
  ]
}
```

### Processing Flow

```
POST /api/garmin/file
         │
         ▼
┌─────────────────────┐
│ Save to             │
│ cache_fitfiles      │
└─────────────────────┘
         │
         ▼
┌─────────────────────┐
│ Queue task:         │
│ runfitfiles.php     │
└─────────────────────┘
         │
         ▼ (async)
┌─────────────────────┐
│ Parse JSON          │
│ Insert fitfiles     │
│ record              │
└─────────────────────┘
         │
         ▼
┌─────────────────────┐
│ Queue task:         │
│ runcallback.php     │
└─────────────────────┘
         │
         ▼ (async)
┌─────────────────────┐
│ Download FIT via    │
│ callbackURL         │
│ (OAuth signed)      │
└─────────────────────┘
         │
         ▼
┌─────────────────────┐
│ Store to S3 or      │
│ assets table        │
└─────────────────────┘
         │
         ▼
┌─────────────────────┐
│ Queue task:         │
│ runfitextract.php   │
└─────────────────────┘
         │
         ▼ (async)
┌─────────────────────┐
│ Extract session     │
│ data, query weather │
└─────────────────────┘
```

### Callback URL Download

The `callbackURL` requires OAuth 1.0 authentication:

```php
function file_callback_one($row) {
    $url = $row['callbackURL'];
    $token = $row['userAccessToken'];
    $secret = $this->get_token_secret($token);

    // Make OAuth-signed request
    $data = $this->get_url_data($url, $token, $secret);

    // Store FIT file
    $this->store_asset($row['file_id'], 'fitfiles', $data);
}
```

## Deregistration Webhook

### Trigger
User revokes app access in Garmin Connect.

### Payload Structure

```json
{
  "deregistrations": [
    {
      "userId": "garmin_user_id",
      "userAccessToken": "oauth_access_token"
    }
  ]
}
```

### Processing
- Marks tokens as inactive
- Does NOT delete user data (preserved for historical reference)

## User ID Validation

When registering a user, the server validates the OAuth token with Garmin:

```php
$url = $this->config['url_user_id'];
// https://healthapi.garmin.com/wellness-api/rest/user/id

$response = $this->get_url_data($url, $userAccessToken, $userAccessTokenSecret);
$userId = json_decode($response)->userId;
```

## Configuration Requirements

```php
$api_config = array(
    // Garmin Health API credentials
    'consumerKey' => 'your_garmin_consumer_key',
    'consumerSecret' => 'your_garmin_consumer_secret',

    // Garmin API endpoint for user validation
    'url_user_id' => 'https://healthapi.garmin.com/wellness-api/rest/user/id',
);
```

## Activity Types

Common `activityType` values from Garmin:

| Type | Description |
|------|-------------|
| RUNNING | Running/jogging |
| CYCLING | Cycling |
| SWIMMING | Swimming |
| WALKING | Walking |
| HIKING | Hiking |
| FITNESS_EQUIPMENT | Gym equipment |
| MULTI_SPORT | Triathlon, etc. |
| OTHER | Uncategorized |

## Multi-Sport Activities

For activities like triathlons:
- Parent activity has null `parentSummaryId`
- Child activities reference parent via `parentSummaryId`
- Server links via `parent_activity_id` field

## Data Retention

The server implements configurable retention:

```php
// Ignore activities older than this
'ignore_activities_months_threshold' => 12,

// Don't re-extract FIT data more often than this
'ignore_fitextract_hours_threshold' => 120,
```

## Error Handling

Failed webhook processing:
1. Original JSON saved in `cache_*` table
2. Error recorded in `error_*` table
3. Can be manually reprocessed:
   ```bash
   php runactivities.php {cache_id}
   ```

## Testing Without Garmin

For development, you can:
1. Create test entries directly in cache tables
2. Run processing scripts manually
3. Use `setup/test.sh` for basic validation

See `setup/README.md` for testing procedures.
