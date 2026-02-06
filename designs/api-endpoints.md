# API Endpoints

## Overview

The server exposes three categories of endpoints:
1. **ConnectStats App API** - Authenticated endpoints for the iOS app
2. **Garmin Webhooks** - Receivers for Garmin Health API callbacks
3. **Status/Admin** - Health checks and management endpoints

All endpoints use URL rewriting: `/api/connectstats/search` → `/api/connectstats/search.php`

## ConnectStats App API

### User Registration

**Endpoint:** `GET /api/connectstats/user_register`

Registers a user with their Garmin OAuth tokens. Called after the app completes OAuth flow with Garmin.

**Parameters:**
| Name | Type | Required | Description |
|------|------|----------|-------------|
| userAccessToken | string | Yes | Garmin OAuth access token |
| userAccessTokenSecret | string | Yes | Garmin OAuth access token secret |

**Response:**
```json
{
  "token_id": 456,
  "cs_user_id": 789,
  "userId": "garmin_user_id_string",
  "userAccessToken": "abc123..."
}
```

**Notes:**
- Validates token with Garmin API to get userId
- Creates/updates user in database
- Returns token_id for subsequent authenticated calls

---

### Validate User

**Endpoint:** `GET /api/connectstats/validateuser`

Validates a token and returns user information.

**Parameters:**
| Name | Type | Required | Description |
|------|------|----------|-------------|
| token_id | int | Yes | Token ID from registration |

**Headers:**
```
Authorization: OAuth oauth_consumer_key="...", oauth_token="...",
               oauth_signature="...", oauth_timestamp="...",
               oauth_nonce="...", oauth_version="1.0"
```

**Response:**
```json
{
  "token_id": 456,
  "cs_user_id": 789,
  "userId": "garmin_user_id",
  "status": "valid"
}
```

---

### Search Activities

**Endpoint:** `GET /api/connectstats/search`

Returns a paginated list of activities for the authenticated user.

**Parameters:**
| Name | Type | Required | Description |
|------|------|----------|-------------|
| token_id | int | Yes | Token ID |
| start | int | No | Pagination offset (default: 0) |
| limit | int | No | Results per page (default: 50) |
| activity_id | int | No | Filter to specific activity |
| start_ts | int | No | Filter activities after timestamp |
| end_ts | int | No | Filter activities before timestamp |

**Headers:** OAuth 1.0 Authorization header required

**Response:**
```json
{
  "activityList": [
    {
      "cs_activity_id": 123,
      "activityId": "garmin_activity_id",
      "userId": "garmin_user_id",
      "summaryId": "unique_summary_id",
      "startTimeInSeconds": 1234567890,
      "durationInSeconds": 3600,
      "distanceInMeters": 10000,
      "activityType": "RUNNING",
      "file_id": 456
    }
  ],
  "paging": {
    "start": 0,
    "limit": 50,
    "total": 250
  }
}
```

---

### Download FIT File

**Endpoint:** `GET /api/connectstats/file`

Downloads the FIT file for a specific activity.

**Parameters:**
| Name | Type | Required | Description |
|------|------|----------|-------------|
| token_id | int | Yes | Token ID |
| activity_id | int | Yes | Activity ID to get file for |

**Headers:** OAuth 1.0 Authorization header required

**Response:**
- Content-Type: `application/octet-stream`
- Body: Raw FIT file binary data

---

### Get JSON Data

**Endpoint:** `GET /api/connectstats/json`

Retrieves JSON data (weather or FIT session info) for an activity.

**Parameters:**
| Name | Type | Required | Description |
|------|------|----------|-------------|
| token_id | int | Yes | Token ID |
| activity_id | int | Yes | Activity ID |
| table | string | Yes | Data type: "weather" or "fitsession" |

**Headers:** OAuth 1.0 Authorization header required

**Response (weather):**
```json
{
  "weather": {
    "visualCrossing": {
      "location": {
        "latitude": 37.7749,
        "longitude": -122.4194,
        "tz": "America/Los_Angeles",
        "values": [{
          "temp": 18.5,
          "wspd": 12.3,
          "conditions": "Clear"
        }]
      }
    }
  }
}
```

**Response (fitsession):**
```json
{
  "fitsession": {
    "sport": "running",
    "total_elapsed_time": 3600,
    "total_distance": 10000,
    "avg_heart_rate": 145
  }
}
```

---

## Garmin Webhook Receivers

These endpoints receive POST requests from Garmin Health API. They are not authenticated via OAuth but receive data pushed by Garmin.

### Activities Callback

**Endpoint:** `POST /api/garmin/activities`

Receives activity summaries when a user completes an activity.

**Request Body (from Garmin):**
```json
{
  "activities": [
    {
      "userId": "garmin_user_id",
      "userAccessToken": "token...",
      "summaryId": "unique_id",
      "activityId": 12345,
      "startTimeInSeconds": 1234567890,
      "durationInSeconds": 3600,
      "activityType": "RUNNING"
    }
  ]
}
```

**Processing:**
1. Saves raw JSON to `cache_activities`
2. Queues task: `php runactivities.php {cache_id}`
3. Returns 200 OK

---

### File Callback

**Endpoint:** `POST /api/garmin/file`

Notifies server that a FIT file is ready for download.

**Request Body (from Garmin):**
```json
{
  "activityFiles": [
    {
      "userId": "garmin_user_id",
      "userAccessToken": "token...",
      "summaryId": "unique_id",
      "fileType": "FIT",
      "callbackURL": "https://healthapi.garmin.com/..."
    }
  ]
}
```

**Processing:**
1. Saves raw JSON to `cache_fitfiles`
2. Queues task: `php runfitfiles.php {cache_id}`
3. Returns 200 OK

---

### Deregistration Callback

**Endpoint:** `POST /api/garmin/deregistration`

Called when a user revokes app access.

**Request Body:**
```json
{
  "deregistrations": [
    {
      "userId": "garmin_user_id",
      "userAccessToken": "token..."
    }
  ]
}
```

**Processing:**
- Marks user tokens as deregistered
- Does NOT delete user data

---

## Status Endpoints

### API Status

**Endpoint:** `GET /api/status/api`

Health check for the API.

**Response:**
```json
{
  "status": 1,
  "version": "1.0",
  "database": "connected"
}
```

### App Status

**Endpoint:** `GET /api/status/app`

Returns app configuration status.

**Response:**
```json
{
  "status": 1,
  "redirect": "https://alternate-server.com/api"  // Optional
}
```

### Usage Statistics

**Endpoint:** `GET /api/status/usage`

Returns usage statistics (requires auth).

**Response:**
```json
{
  "total_users": 1000,
  "active_today": 150,
  "activities_today": 500
}
```

---

### Cache Status

**Endpoint:** `GET /api/status/cache`

Returns status of the cache processing, checking for old activities, fitfiles, usage, and slow cache processing times.

**Parameters:**
| Name | Type | Required | Description |
|------|------|----------|-------------|
| verbose | int | No | 1 for detailed progress |
| max_seconds | int | No | Max processing time for a task (default: 10) |
| n | int | No | Number of recent entries to check (default: 10, max: 100) |
| threshold | int | No | Time in seconds for "old" data (default: 3600s) |
| detail | int | No | 1 for detailed report instead of counts |

**Response:**
```json
{
  "old_activities": 0,
  "old_fitfiles": 0,
  "old_usage": 0,
  "slow_cache_activities": 0,
  "slow_cache_fitfiles": 0,
  "status": 1,
  "checked": {
    "total_checked": 10,
    "max_time": 10,
    "threshold": "2024-01-01 12:00:00"
  }
}
```
---

## Notification Endpoints

### Register Device

**Endpoint:** `POST /api/notifications/register`

Registers a device for push notifications.

**Parameters:**
| Name | Type | Required | Description |
|------|------|----------|-------------|
| token_id | int | Yes | User token ID |
| device_token | string | Yes | APNS device token |
| enabled | int | No | 1=enabled, 0=disabled |

**Response:**
```json
{
  "status": "registered",
  "device_id": 123
}
```

---

## Authentication

All ConnectStats App API endpoints require OAuth 1.0 authentication.

**Header Format:**
```
Authorization: OAuth
  oauth_consumer_key="your_consumer_key",
  oauth_token="user_access_token",
  oauth_signature_method="HMAC-SHA1",
  oauth_signature="base64_encoded_signature",
  oauth_timestamp="1234567890",
  oauth_nonce="random_string",
  oauth_version="1.0"
```

**Signature Base String:**
```
GET&https%3A%2F%2Fserver.com%2Fapi%2Fconnectstats%2Fsearch&
oauth_consumer_key%3Dkey%26oauth_nonce%3Dnonce%26...
```

See [authentication.md](authentication.md) for implementation details.

---

## Error Responses

All endpoints return errors in this format:

```json
{
  "error": "error_code",
  "message": "Human readable description"
}
```

**HTTP Status Codes:**
- 200: Success
- 400: Bad request (missing parameters)
- 401: Authentication failed
- 404: Resource not found
- 500: Server error
