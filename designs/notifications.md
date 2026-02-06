# Notifications

## Overview

The server supports push notifications to alert users when new activities are synced. This uses Apple Push Notification Service (APNS) for iOS devices.

## Architecture

```
┌─────────────┐     ┌─────────────┐     ┌─────────────┐     ┌─────────────┐
│   Garmin    │────▶│   Server    │────▶│    APNS     │────▶│ iOS Device  │
│   Webhook   │     │             │     │             │     │             │
└─────────────┘     └─────────────┘     └─────────────┘     └─────────────┘
```

## Database Schema

### notifications_devices

Stores registered devices:
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

Tracks sent notifications:

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

Links notifications to activities:

```sql
CREATE TABLE notifications_activities (
    activity_id BIGINT(20) UNSIGNED PRIMARY KEY,
    notification_id BIGINT(20) UNSIGNED,
    ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
    create_ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP
);
```

## API Endpoints

### Register Device

**Endpoint:** `POST /api/notifications/register`

**Parameters:**
| Name | Type | Required | Description |
|------|------|----------|-------------|
| token_id | int | Yes | User's token ID |
| device_token | string | Yes | APNS device token (hex string) |
| enabled | int | No | 1 to enable, 0 to disable |

**Response:**
```json
{
  "status": "registered",
  "device_id": 123
}
```

### Trigger Push (CLI Command)

**Command:** `php api/notifications/push.php`

Triggers a push notification for a specific user. This is a command-line script, not a web-accessible API endpoint.

**Parameters (command line arguments):**
| Name | Type | Required | Description |
|------|------|----------|-------------|
| cs_user_id | int | Yes | User to notify |

## Device Registration Flow

```
┌─────────────────┐
│  iOS App        │
│  Launches       │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  Request APNS   │
│  Permission     │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  Receive Device │
│  Token from iOS │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  POST to        │
│  /register      │
└────────┬────────┘
         │
         ▼
┌─────────────────┐
│  Server stores  │
│  device_token   │
└─────────────────┘
```

## Notification Flow

```
Activity received from Garmin
            │
            ▼
┌─────────────────────┐
│ Process activity    │
│ (runactivities.php) │
└─────────────────────┘
            │
            ▼
┌─────────────────────┐
│ Check if user has   │
│ enabled devices     │
└─────────────────────┘
            │
            ▼
┌─────────────────────┐
│ Queue notification  │
│ task (e.g.          │
│ activity.php)       │
└─────────────────────┘
            │
            ▼ (async)
┌─────────────────────┐
│ Generate APNS JWT   │
│ token               │
└─────────────────────┘
            │
            ▼
┌─────────────────────┐
│ POST to APNS        │
│ endpoint            │
└─────────────────────┘
            │
            ▼
┌─────────────────────┐
│ Record result in    │
│ notifications table │
└─────────────────────┘
```

## APNS Integration

### Configuration

```php
$api_config = array(
    // APNS authentication
    'apn_key_id' => 'ABC123DEFG',           // Key ID from Apple
    'apn_team_id' => 'TEAMID1234',          // Team ID from Apple
    'apn_bundle_id' => 'net.ro-z.connectstats',  // App bundle ID
    'apn_key_file' => '/path/to/AuthKey.p8', // Private key file

    // APNS endpoint
    'apn_url' => 'https://api.push.apple.com',  // Production
    // 'apn_url' => 'https://api.sandbox.push.apple.com',  // Development
);
```

### JWT Token Generation

APNS uses JWT for authentication:

```php
function generate_apns_jwt() {
    $header = array(
        'alg' => 'ES256',
        'kid' => $this->config['apn_key_id']
    );

    $claims = array(
        'iss' => $this->config['apn_team_id'],
        'iat' => time()
    );

    $header_encoded = base64_encode(json_encode($header)); // Using base64 as implemented
    $claims_encoded = base64_encode(json_encode($claims)); // Using base64 as implemented

    $signature = $this->sign_es256(
        "$header_encoded.$claims_encoded",
        $this->config['apn_key_file']
    );

    return "$header_encoded.$claims_encoded.$signature";
}
```

### Sending Push Notification

```php
function notification_push($device_token, $message) {
    $jwt = $this->generate_apns_jwt();

    $payload = array(
        'aps' => array(
            'alert' => array(
                'title' => 'ConnectStats',
                'body' => $message
            ),
            'badge' => 1,
            'sound' => 'default'
        )
    );

    $url = $this->config['apn_url'] . "/3/device/$device_token";

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_HTTP_VERSION, CURL_HTTP_VERSION_2_0);
    curl_setopt($ch, CURLOPT_HTTPHEADER, array(
        "authorization: bearer $jwt",
        "apns-topic: " . $this->config['apn_bundle_id'],
        "apns-push-type: alert"
    ));
    curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($payload));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = curl_exec($ch);
    $http_code = curl_getinfo($ch, CURLINFO_HTTP_CODE);

    return $http_code === 200;
}
```

## Implementation Details

Location: `api/shared.php` and `api/notifications/`

### Register Device

```php
function notification_register($cs_user_id, $params) {
    $device_token = $params['device_token'];
    $enabled = isset($params['enabled']) ? intval($params['enabled']) : 1;
    $push_type = isset($params['push_type']) ? intval($params['push_type']) : 0; // Added push_type

    // Check if device already registered
    $existing = $this->sql->query_first_row(
        "SELECT device_token FROM notifications_devices
         WHERE cs_user_id = $cs_user_id AND device_token = '$device_token'"
    );

    if ($existing) {
        // Update existing
        $this->sql->execute_query(
            "UPDATE notifications_devices
             SET enabled = $enabled, push_type = $push_type, ts = NOW()
             WHERE device_token = '{$existing['device_token']}'" // Use device_token as PK
        );
    } else {
        // Insert new
        $this->sql->insert_or_update('notifications_devices', array(
            'cs_user_id' => $cs_user_id,
            'device_token' => $device_token,
            'enabled' => $enabled,
            'push_type' => $push_type
        ), array());
    }
    return $device_token; // Return device_token as PK
}
```

### Push to User

```php
function notification_push_to_user($cs_user_id, $message = 'New activity synced') {
    // Get all enabled devices for user
    $devices = $this->sql->query_as_array(
        "SELECT device_token, push_type
         FROM notifications_devices
         WHERE cs_user_id = $cs_user_id AND enabled = 1"
    );

    foreach ($devices as $device) {
        $msg_payload = array(
            'aps' => array('alert' => $message, 'badge' => 1) // Simple alert payload
        );
        // Custom payload based on push_type from DB
        if ($device['push_type'] == 1) { // Example: Silent background update
            $msg_payload = [ "aps" => [ "content-available" => 1 ] ];
        } else if ($device['push_type'] == 2) { // Example: Alert with sound
             $msg_payload = [ "aps" => [  "alert" => $message, "badge" => 1, "sound" => "default" ] ];
        }

        $success = $this->notification_push($device['device_token'], $msg_payload); // Pass full payload

        // Record result
        $this->sql->insert_or_update('notifications', array(
            'cs_user_id' => $cs_user_id,
            'device_token' => $device['device_token'],
            'status' => $success ? 200 : 0, // Using HTTP status code or 0 for failure
            'ts' => date('Y-m-d H:i:s')
        ), array());
    }
}
```

## Triggering Notifications

After processing an activity:

```php
// In GarminProcess::process (called by runactivities.php), after successful processing
// Notification is triggered by queuing a background script (api/notifications/activity.php)
// which then calls notification_push_for_activity().
if ($this->config_has('apn_url')) {
    // A background task is queued, not a direct call.
    // e.g., $this->exec_notification_cmd($table, $notification_ids);
}
```

## Error Handling

### Invalid Device Token

APNS returns 410 Gone for invalid tokens.
NOTE: The current implementation **does not explicitly handle** HTTP 410 responses from APNS to disable or remove invalid device tokens from the database. This is a missing feature that should be implemented for proper maintenance.

### Rate Limiting

APNS may return 429 Too Many Requests.
NOTE: The current implementation **does not explicitly implement** exponential backoff or queueing mechanisms for retrying notifications after rate limiting.

## Testing

### Development Environment

Use sandbox APNS endpoint:
```php
'apn_url' => 'https://api.sandbox.push.apple.com',
```

### Manual Test (CLI)

```bash
php api/notifications/push.php {cs_user_id}
# Example: php api/notifications/push.php 123
```

## Security Considerations

1. **Device token validation**: Verify token format before storing
2. **User association**: Only send to devices owned by the user
3. **Rate limiting**: Prevent notification spam
4. **Token refresh**: Handle token changes when app reinstalled

## Notification Payload Customization

For richer notifications:

```php
$payload = array(
    'aps' => array(
        'alert' => array(
            'title' => 'New Activity',
            'subtitle' => 'Running',
            'body' => '10km in 45:30'
        ),
        'badge' => $unread_count,
        'sound' => 'default',
        'category' => 'ACTIVITY_CATEGORY'
    ),
    // Custom data for app
    'activity_id' => $activity_id,
    'activity_type' => 'RUNNING'
);
```
