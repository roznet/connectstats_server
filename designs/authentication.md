# Authentication

## Overview

The server uses OAuth 1.0 for authentication, matching the Garmin Health API's authentication scheme. This allows the server to:
1. Verify requests from the ConnectStats app
2. Make authenticated requests to Garmin's API
3. Verify system-to-system calls for maintenance tasks

## OAuth 1.0 Flow

### Initial Registration

```
┌──────────────┐     ┌──────────────┐     ┌──────────────┐
│ ConnectStats │     │   Server     │     │  Garmin API  │
│     App      │     │              │     │              │
└──────┬───────┘     └──────┬───────┘     └──────┬───────┘
       │                    │                    │
       │  OAuth flow with Garmin                 │
       │◀────────────────────────────────────────▶
       │                    │                    │
       │  Receives tokens   │                    │
       │  userAccessToken   │                    │
       │  userAccessSecret  │                    │
       │                    │                    │
       │  user_register     │                    │
       │  (tokens)          │                    │
       │───────────────────▶│                    │
       │                    │                    │
       │                    │  Validate token    │
       │                    │  (get userId)      │
       │                    │───────────────────▶│
       │                    │◀───────────────────│
       │                    │                    │
       │  token_id          │                    │
       │◀───────────────────│                    │
       │                    │                    │
```

### Subsequent Requests

```
┌──────────────┐     ┌──────────────┐
│ ConnectStats │     │   Server     │
│     App      │     │              │
└──────┬───────┘     └──────┬───────┘
       │                    │
       │  API Request       │
       │  + OAuth Header    │
       │───────────────────▶│
       │                    │
       │                    │  Verify signature
       │                    │  using stored secret
       │                    │
       │  Response          │
       │◀───────────────────│
```

## OAuth Header Format

The Authorization header must include:

```
Authorization: OAuth
  oauth_consumer_key="consumer_key_from_config",
  oauth_token="user_access_token",
  oauth_signature_method="HMAC-SHA1",
  oauth_signature="base64_signature",
  oauth_timestamp="unix_timestamp",
  oauth_nonce="unique_random_string",
  oauth_version="1.0"
```

## Signature Generation

### Base String Construction

1. **HTTP Method**: `GET` or `POST`
2. **Base URL**: Full URL without query string, percent-encoded
3. **Parameters**: All OAuth params + query params, sorted, percent-encoded

```
GET&https%3A%2F%2Fserver.com%2Fapi%2Fconnectstats%2Fsearch&
oauth_consumer_key%3Dkey%26
oauth_nonce%3Dnonce%26
oauth_signature_method%3DHMAC-SHA1%26
oauth_timestamp%3D1234567890%26
oauth_token%3Dtoken%26
oauth_version%3D1.0%26
token_id%3D123
```

### Signing Key

```
{consumerSecret}&{userAccessTokenSecret}
```

### Signature

```
HMAC-SHA1(base_string, signing_key) → base64 encode
```

## Implementation

### Server-Side Verification (`authenticate_header`)

Location: `api/shared.php` in `GarminProcess` class

```php
function authenticate_header($token_id) {
    // 1. Get stored token secret
    $row = $this->sql->query_first_row(
        "SELECT userAccessTokenSecret, userAccessToken
         FROM tokens WHERE token_id = $token_id"
    );

    // 2. Parse Authorization header
    $header = $_SERVER['HTTP_AUTHORIZATION'];
    $oauth_params = $this->parse_oauth_header($header);

    // 3. Rebuild base string
    $base_string = $this->build_base_string(
        $_SERVER['REQUEST_METHOD'],
        $this->get_full_url(),
        $oauth_params
    );

    // 4. Calculate expected signature
    $key = $this->config['consumerSecret'] . '&' .
           $row['userAccessTokenSecret'];
    $expected = base64_encode(hash_hmac('sha1', $base_string, $key, true));

    // 5. Compare signatures
    if ($oauth_params['oauth_signature'] !== $expected) {
        $this->error_response(401, 'Invalid signature');
    }
}
```

### Server-Side Generation (`authorize_header`)

For making requests to Garmin API:

```php
function authorize_header($url, $token, $secret) {
    $oauth_params = array(
        'oauth_consumer_key' => $this->config['consumerKey'],
        'oauth_token' => $token,
        'oauth_signature_method' => 'HMAC-SHA1',
        'oauth_timestamp' => time(),
        'oauth_nonce' => $this->generate_nonce(),
        'oauth_version' => '1.0'
    );

    $base_string = $this->build_base_string('GET', $url, $oauth_params);
    $key = $this->config['consumerSecret'] . '&' . $secret;

    $oauth_params['oauth_signature'] =
        base64_encode(hash_hmac('sha1', $base_string, $key, true));

    return 'OAuth ' . $this->build_header_string($oauth_params);
}
```

## System Authentication

For internal system calls (maintenance, queue tasks):

```php
// Uses serviceKey/serviceKeySecret from config
$process->authenticate_system_call();
```

This verifies the request came from a trusted internal source using the same OAuth mechanism but with service credentials.

## Token Storage

Tokens are stored in the `tokens` table:

| Field | Description |
|-------|-------------|
| token_id | Primary key, returned to app |
| cs_user_id | Internal user ID |
| userAccessToken | Garmin OAuth token |
| userAccessTokenSecret | Garmin OAuth secret (for signing) |
| userId | Garmin's user identifier |

## Security Considerations

### Implemented

- **HMAC-SHA1 signatures**: Requests cannot be forged without the secret
- **Nonce**: Prevents replay attacks
- **Timestamp**: Limits replay window (though not strictly enforced)
- **Per-user secrets**: Compromise of one user doesn't affect others
- **User isolation**: All queries filtered by `cs_user_id`

### Recommendations for Enhancement

1. **Timestamp validation**: Reject requests with old timestamps (e.g., >5 minutes)
2. **Nonce tracking**: Store nonces to prevent replay within timestamp window
3. **Rate limiting**: Limit requests per token_id per time period
4. **Token rotation**: Implement token refresh mechanism

## Client Implementation

The ConnectStats iOS app must:

1. Complete OAuth flow with Garmin to get tokens
2. Call `user_register` with tokens to get `token_id`
3. For each API request:
   - Generate timestamp (Unix seconds)
   - Generate unique nonce
   - Build base string with all params
   - Sign with `consumerSecret&userAccessTokenSecret`
   - Add Authorization header

## Debugging Authentication Issues

Common problems:

1. **Wrong base URL**: Must match exactly (http vs https, trailing slash)
2. **Parameter encoding**: Double-encoding or missed encoding
3. **Parameter sorting**: Must be alphabetical
4. **Signature encoding**: Must be base64 of raw HMAC bytes
5. **Clock skew**: Timestamp too far from server time

Enable debug logging:
```php
error_log("Base string: " . $base_string);
error_log("Expected sig: " . $expected);
error_log("Received sig: " . $oauth_params['oauth_signature']);
```
