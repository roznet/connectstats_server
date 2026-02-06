# OAuth 2.0 Migration Plan

## Overview

Garmin is retiring OAuth 1.0 on **December 31, 2026**. This document outlines the migration strategy for ConnectStats Server and the iOS app.

## Current State Analysis

### Token Dependencies

The system currently has a **tight coupling** with Garmin's OAuth 1.0 tokens:

```
┌─────────────────┐                      ┌─────────────────┐
│  ConnectStats   │  OAuth 1.0 signed    │  ConnectStats   │
│    iOS App      │─────────────────────▶│     Server      │
└─────────────────┘  (Garmin tokens)     └────────┬────────┘
                                                  │
                                                  │ OAuth 1.0 signed
                                                  │ (Garmin tokens)
                                                  ▼
                                         ┌─────────────────┐
                                         │   Garmin API    │
                                         └─────────────────┘
```

### How App-to-Server Auth Currently Works

**Location:** `api/shared.php:1072` - `authenticate_header()`

1. iOS app sends `Authorization: OAuth oauth_token={userAccessToken}, oauth_signature={...}`
2. Server looks up `userAccessTokenSecret` from `tokens` table by `userAccessToken`
3. Server recomputes HMAC-SHA1 signature using the secret
4. Compares signatures to authenticate

**Endpoints using this authentication:**
- `/api/connectstats/search` - query activities
- `/api/connectstats/file` - download FIT files
- `/api/connectstats/json` - get activity JSON data
- `/api/connectstats/validateuser` - validate user
- `/api/connectstats/sync` - sync activities
- `/api/notifications/register` - register for push notifications

### How Server-to-Garmin Auth Works

**Location:** `api/shared.php:1186` - `get_url_data()`

- Uses `userAccessToken` + `userAccessTokenSecret` to sign requests
- Used for downloading FIT files via Garmin's `callbackURL`

### The Problem

OAuth 2.0 tokens **do not have secrets** - they cannot be used for HMAC signing. The current app-to-server authentication scheme will break.

| Use Case | OAuth 1.0 | OAuth 2.0 |
|----------|-----------|-----------|
| Server → Garmin | Sign with `userAccessTokenSecret` | `Bearer {access_token}` |
| App → Server | Sign with `userAccessTokenSecret` | **No secret available** |

## Migration Strategy

### Architecture After Migration

```
┌─────────────────┐                      ┌─────────────────┐
│  ConnectStats   │  Bearer server_token │  ConnectStats   │
│    iOS App      │─────────────────────▶│     Server      │
└─────────────────┘  (our own token)     └────────┬────────┘
                                                  │
                                                  │ Bearer access_token
                                                  │ (Garmin OAuth 2.0)
                                                  ▼
                                         ┌─────────────────┐
                                         │   Garmin API    │
                                         └─────────────────┘
```

**Key insight:** Decouple app-to-server authentication from Garmin tokens entirely.

---

## Phase 1: Server Dual-Auth Support

**Goal:** Server accepts both OAuth 1.0 signatures AND Bearer tokens

### 1.1 Database Changes

```sql
-- Add server-issued token for app-to-server auth
ALTER TABLE tokens ADD COLUMN server_token VARCHAR(64);
ALTER TABLE tokens ADD COLUMN server_token_created_at TIMESTAMP;

-- Index for Bearer token lookup
CREATE INDEX idx_tokens_server_token ON tokens(server_token);
```

### 1.2 Modify `authenticate_header()`

**File:** `api/shared.php`

The function should detect which auth method is being used:

```php
function authenticate_header($token_id){
    $failed = true;

    if( !isset( apache_request_headers()['Authorization'] ) ){
        $this->auth_failed();
    }

    $header = apache_request_headers()['Authorization'];

    // Check for Bearer token (new method)
    if( preg_match('/^Bearer\s+(.+)$/i', $header, $matches) ){
        $failed = !$this->authenticate_bearer_token($matches[1], $token_id);
    }
    // Check for OAuth 1.0 (legacy method)
    else if( preg_match('/^OAuth\s+/i', $header) ){
        $failed = !$this->authenticate_oauth1($header, $token_id);
    }

    if( $failed ){
        $this->auth_failed();
    }
}

function authenticate_bearer_token($server_token, $token_id){
    $row = $this->sql->query_first_row(
        "SELECT token_id FROM tokens WHERE server_token = '" .
        $this->sql->escape($server_token) . "'"
    );

    return isset($row['token_id']) && $row['token_id'] == $token_id;
}

function authenticate_oauth1($header, $token_id){
    // Existing OAuth 1.0 logic moved here
    $full_url = sprintf('%s://%s%s', $_SERVER['REQUEST_SCHEME'],
                        $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI']);

    $maps = $this->interpret_authorization_header($header);
    if( !isset($maps['oauth_token']) || !isset($maps['oauth_nonce']) ||
        !isset($maps['oauth_signature']) ){
        return false;
    }

    $userAccessToken = $maps['oauth_token'];
    $row = $this->sql->query_first_row(
        "SELECT userAccessTokenSecret, token_id FROM tokens
         WHERE userAccessToken = '$userAccessToken'"
    );

    if( !isset($row['token_id']) ){
        return false;
    }

    $reconstructed = $this->authorization_header(
        $full_url, $userAccessToken, $row['userAccessTokenSecret'],
        $maps['oauth_nonce'], $maps['oauth_timestamp']
    );
    $reconstructed = str_replace('Authorization: ', '', $reconstructed);
    $reconmaps = $this->interpret_authorization_header($reconstructed);

    return urldecode($reconmaps['oauth_signature']) == urldecode($maps['oauth_signature'])
           && $row['token_id'] == $token_id;
}

function auth_failed(){
    if( $this->verbose ){
        $this->log('ERROR', 'authorization failed');
    }else{
        header('HTTP/1.1 401 Unauthorized error');
    }
    die;
}
```

### 1.3 New Endpoint: Get Server Token

**File:** `api/connectstats/get_server_token.php`

Allows existing users (authenticated via OAuth 1.0) to obtain a server token:

```php
<?php
include_once('../shared.php');

$process = new GarminProcess();

if( isset($_GET['token_id']) ){
    $token_id = $process->validate_input_id($_GET['token_id']);

    // Authenticate using existing OAuth 1.0 method
    $process->authenticate_header($token_id);

    // Generate and store server token
    $server_token = $process->generate_server_token($token_id);

    echo json_encode([
        'status' => 'ok',
        'server_token' => $server_token
    ]);
}
```

### 1.4 Server Token Generation

**File:** `api/shared.php`

```php
function generate_server_token($token_id){
    // Generate cryptographically secure token
    $server_token = bin2hex(random_bytes(32));

    $this->sql->execute(
        "UPDATE tokens SET server_token = '$server_token',
         server_token_created_at = NOW()
         WHERE token_id = $token_id"
    );

    return $server_token;
}
```

### 1.5 Modify `user_register` to Return Server Token

When registering new users, also generate and return the server token:

```php
// In register_user(), after successful registration:
$server_token = $this->generate_server_token($token_id);

return [
    'token_id' => $token_id,
    'cs_user_id' => $cs_user_id,
    'server_token' => $server_token  // NEW
];
```

---

## Phase 2: iOS App Update

**Goal:** App uses Bearer token for server communication

### 2.1 App Startup Flow

```
┌─────────────────────────────────────────────────────────────┐
│  App startup                                                │
│                                                             │
│  Has server_token stored in Keychain?                       │
│      YES → Use Bearer auth for all server requests          │
│      NO  → Is user registered? (has token_id?)              │
│              YES → Call /get_server_token (OAuth 1.0 auth)  │
│                    Store returned server_token              │
│              NO  → Normal registration flow                 │
│                    (will receive server_token)              │
└─────────────────────────────────────────────────────────────┘
```

### 2.2 Request Signing Changes

**Before (OAuth 1.0):**
```swift
// Complex OAuth 1.0 signature generation
let header = OAuth1.authorizationHeader(
    url: url,
    token: userAccessToken,
    tokenSecret: userAccessTokenSecret,
    consumerKey: consumerKey,
    consumerSecret: consumerSecret
)
request.setValue(header, forHTTPHeaderField: "Authorization")
```

**After (Bearer):**
```swift
// Simple Bearer token
request.setValue("Bearer \(serverToken)", forHTTPHeaderField: "Authorization")
```

### 2.3 Migration Logic in App

```swift
class AuthManager {
    func getAuthorizationHeader() -> String {
        // Prefer new server token if available
        if let serverToken = Keychain.get("server_token") {
            return "Bearer \(serverToken)"
        }

        // Fall back to OAuth 1.0 for migration period
        return generateOAuth1Header()
    }

    func migrateToServerToken() async throws {
        guard Keychain.get("server_token") == nil,
              let tokenId = Keychain.get("token_id") else {
            return
        }

        // Call server with OAuth 1.0 auth to get server token
        let response = try await api.getServerToken(tokenId: tokenId)
        Keychain.set("server_token", response.serverToken)
    }
}
```

---

## Phase 3: Garmin OAuth 2.0 Migration

**Goal:** Support Garmin's new OAuth 2.0 PKCE flow

### 3.1 Database Changes

```sql
-- OAuth 2.0 token storage
ALTER TABLE tokens ADD COLUMN garmin_access_token VARCHAR(255);
ALTER TABLE tokens ADD COLUMN garmin_refresh_token VARCHAR(255);
ALTER TABLE tokens ADD COLUMN garmin_token_expires_at INT;
ALTER TABLE tokens ADD COLUMN garmin_refresh_expires_at INT;
ALTER TABLE tokens ADD COLUMN oauth_version TINYINT DEFAULT 1;

-- userId becomes primary identifier (already exists, ensure indexed)
CREATE INDEX idx_tokens_userId ON tokens(userId);
```

### 3.2 OAuth 2.0 Token Storage

```php
function store_oauth2_tokens($token_id, $access_token, $refresh_token,
                             $expires_in, $refresh_expires_in){
    $expires_at = time() + $expires_in - 600; // 10 min buffer
    $refresh_expires_at = time() + $refresh_expires_in;

    $this->sql->execute(
        "UPDATE tokens SET
         garmin_access_token = '$access_token',
         garmin_refresh_token = '$refresh_token',
         garmin_token_expires_at = $expires_at,
         garmin_refresh_expires_at = $refresh_expires_at,
         oauth_version = 2
         WHERE token_id = $token_id"
    );
}
```

### 3.3 Token Refresh Logic

```php
function get_valid_garmin_token($token_id){
    $row = $this->sql->query_first_row(
        "SELECT garmin_access_token, garmin_refresh_token,
                garmin_token_expires_at, oauth_version,
                userAccessToken, userAccessTokenSecret
         FROM tokens WHERE token_id = $token_id"
    );

    // OAuth 1.0 - return existing tokens
    if( $row['oauth_version'] == 1 ){
        return [
            'version' => 1,
            'token' => $row['userAccessToken'],
            'secret' => $row['userAccessTokenSecret']
        ];
    }

    // OAuth 2.0 - check expiry and refresh if needed
    if( time() >= $row['garmin_token_expires_at'] ){
        $this->refresh_garmin_token($token_id, $row['garmin_refresh_token']);
        $row = $this->sql->query_first_row(
            "SELECT garmin_access_token FROM tokens WHERE token_id = $token_id"
        );
    }

    return [
        'version' => 2,
        'token' => $row['garmin_access_token']
    ];
}

function refresh_garmin_token($token_id, $refresh_token){
    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, 'https://diauth.garmin.com/di-oauth2-service/oauth/token');
    curl_setopt($ch, CURLOPT_POST, true);
    curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
        'grant_type' => 'refresh_token',
        'client_id' => $this->api_config['oauth2ClientId'],
        'client_secret' => $this->api_config['oauth2ClientSecret'],
        'refresh_token' => $refresh_token
    ]));
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    $response = json_decode(curl_exec($ch), true);
    curl_close($ch);

    if( isset($response['access_token']) ){
        $this->store_oauth2_tokens(
            $token_id,
            $response['access_token'],
            $response['refresh_token'],
            $response['expires_in'],
            $response['refresh_token_expires_in']
        );
    }
}
```

### 3.4 Modify `get_url_data()` for Dual OAuth Support

```php
function get_url_data_for_token($url, $token_id){
    $garmin_token = $this->get_valid_garmin_token($token_id);

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);

    if( $garmin_token['version'] == 2 ){
        // OAuth 2.0 - simple Bearer header
        $headers = ['Authorization: Bearer ' . $garmin_token['token']];
    }else{
        // OAuth 1.0 - signed header
        $headers = [$this->authorization_header(
            $url, $garmin_token['token'], $garmin_token['secret']
        )];
    }

    curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    $data = curl_exec($ch);
    curl_close($ch);

    return $data;
}
```

### 3.5 Webhook Handler Updates

Webhooks will use `userId` instead of `userAccessToken` after OAuth 2.0 migration:

```php
function lookup_user_from_webhook($item){
    // Try userId first (OAuth 2.0)
    if( isset($item['userId']) ){
        $row = $this->sql->query_first_row(
            "SELECT token_id, cs_user_id FROM tokens
             WHERE userId = '" . $this->sql->escape($item['userId']) . "'"
        );
        if( $row ){
            return $row;
        }
    }

    // Fall back to userAccessToken (OAuth 1.0)
    if( isset($item['userAccessToken']) ){
        $row = $this->sql->query_first_row(
            "SELECT token_id, cs_user_id FROM tokens
             WHERE userAccessToken = '" . $this->sql->escape($item['userAccessToken']) . "'"
        );
        if( $row ){
            return $row;
        }
    }

    return null;
}
```

### 3.6 iOS App: OAuth 2.0 PKCE Flow

```swift
class GarminOAuth2 {
    func startAuthorization() -> URL {
        // Generate PKCE codes
        let codeVerifier = generateCodeVerifier()  // 43-128 char random string
        let codeChallenge = codeVerifier.sha256().base64URLEncoded()

        // Store verifier for token exchange
        Keychain.set("pkce_verifier", codeVerifier)

        var components = URLComponents(string: "https://connect.garmin.com/oauth2Confirm")!
        components.queryItems = [
            URLQueryItem(name: "client_id", value: clientId),
            URLQueryItem(name: "response_type", value: "code"),
            URLQueryItem(name: "code_challenge", value: codeChallenge),
            URLQueryItem(name: "code_challenge_method", value: "S256"),
            URLQueryItem(name: "redirect_uri", value: redirectUri),
            URLQueryItem(name: "state", value: UUID().uuidString)
        ]

        return components.url!
    }

    func exchangeCodeForTokens(code: String) async throws -> OAuth2Tokens {
        let codeVerifier = Keychain.get("pkce_verifier")!

        let response = try await post(
            url: "https://diauth.garmin.com/di-oauth2-service/oauth/token",
            params: [
                "grant_type": "authorization_code",
                "client_id": clientId,
                "client_secret": clientSecret,
                "code": code,
                "code_verifier": codeVerifier,
                "redirect_uri": redirectUri
            ]
        )

        return OAuth2Tokens(
            accessToken: response.access_token,
            refreshToken: response.refresh_token,
            expiresIn: response.expires_in
        )
    }
}
```

---

## Phase 4: Cleanup (Post December 2026)

After OAuth 1.0 retirement:

1. Remove OAuth 1.0 authentication code from `authenticate_header()`
2. Remove `userAccessToken` and `userAccessTokenSecret` columns (or keep for historical reference)
3. Remove OAuth 1.0 signing code from iOS app
4. Update documentation

---

## Testing Strategy

### The Testing Challenge

This migration is high-risk because:
1. Authentication failures lock users out completely
2. Can't easily test with production Garmin tokens
3. Need to test both old and new auth paths simultaneously
4. Token refresh failures could cause delayed issues

### Test Environments

```
┌─────────────────────────────────────────────────────────────┐
│  Environment Setup                                          │
├─────────────────────────────────────────────────────────────┤
│  LOCAL DEV     │ Mock Garmin responses, synthetic tokens    │
│  STAGING       │ Real Garmin eval keys, test accounts       │
│  PRODUCTION    │ Gradual rollout with feature flags         │
└─────────────────────────────────────────────────────────────┘
```

### Phase 1 Testing: Dual-Auth Server

#### Unit Tests

```php
class AuthenticationTest extends PHPUnit\Framework\TestCase {

    // Test Bearer token authentication
    function test_bearer_auth_valid_token(){
        $token_id = $this->createTestUser();
        $server_token = $this->process->generate_server_token($token_id);

        $_SERVER['HTTP_AUTHORIZATION'] = "Bearer $server_token";

        // Should not throw/die
        $this->process->authenticate_header($token_id);
        $this->assertTrue(true);
    }

    function test_bearer_auth_invalid_token(){
        $this->expectException(AuthenticationException::class);

        $_SERVER['HTTP_AUTHORIZATION'] = "Bearer invalid_token";
        $this->process->authenticate_header(123);
    }

    function test_bearer_auth_wrong_token_id(){
        $token_id_1 = $this->createTestUser();
        $token_id_2 = $this->createTestUser();
        $server_token = $this->process->generate_server_token($token_id_1);

        $_SERVER['HTTP_AUTHORIZATION'] = "Bearer $server_token";

        // Token belongs to user 1, but requesting as user 2
        $this->expectException(AuthenticationException::class);
        $this->process->authenticate_header($token_id_2);
    }

    // Test OAuth 1.0 still works (backward compatibility)
    function test_oauth1_still_works(){
        $token_id = $this->createTestUserWithOAuth1();
        $header = $this->generateOAuth1Header($token_id);

        $_SERVER['HTTP_AUTHORIZATION'] = $header;

        // Should not throw/die
        $this->process->authenticate_header($token_id);
        $this->assertTrue(true);
    }

    // Test server token generation
    function test_generate_server_token(){
        $token_id = $this->createTestUser();

        $token1 = $this->process->generate_server_token($token_id);
        $token2 = $this->process->generate_server_token($token_id);

        // Each call generates new token
        $this->assertNotEquals($token1, $token2);
        $this->assertEquals(64, strlen($token2)); // 32 bytes = 64 hex chars
    }
}
```

#### Integration Tests

```bash
#!/bin/bash
# test_dual_auth.sh

BASE_URL="https://staging.example.com/api"

# Test 1: OAuth 1.0 still works
echo "Test 1: OAuth 1.0 authentication"
OAUTH1_HEADER=$(generate_oauth1_header "$TOKEN_ID" "$ACCESS_TOKEN" "$ACCESS_SECRET")
curl -s -H "Authorization: $OAUTH1_HEADER" \
    "$BASE_URL/connectstats/validateuser?token_id=$TOKEN_ID" | jq .

# Test 2: Get server token via OAuth 1.0
echo "Test 2: Get server token"
SERVER_TOKEN=$(curl -s -H "Authorization: $OAUTH1_HEADER" \
    "$BASE_URL/connectstats/get_server_token?token_id=$TOKEN_ID" | jq -r .server_token)
echo "Server token: $SERVER_TOKEN"

# Test 3: Bearer auth works
echo "Test 3: Bearer authentication"
curl -s -H "Authorization: Bearer $SERVER_TOKEN" \
    "$BASE_URL/connectstats/validateuser?token_id=$TOKEN_ID" | jq .

# Test 4: Invalid bearer rejected
echo "Test 4: Invalid bearer rejected (expect 401)"
curl -s -w "%{http_code}" -H "Authorization: Bearer invalid_token" \
    "$BASE_URL/connectstats/validateuser?token_id=$TOKEN_ID"
```

### Phase 2 Testing: iOS App Migration

#### Test Matrix

| Scenario | Initial State | Action | Expected Result |
|----------|---------------|--------|-----------------|
| Fresh install | No tokens | Register | Gets token_id + server_token |
| Existing user, app update | Has OAuth 1.0 tokens, no server_token | App startup | Calls /get_server_token, stores it |
| Existing user, has server_token | Has server_token | API call | Uses Bearer auth |
| Server token invalid | Has invalid server_token | API call | 401, re-fetch server_token |
| Offline migration | Has OAuth 1.0, no network | App startup | Uses OAuth 1.0 until network available |

#### TestFlight Staged Rollout

1. **Internal testing (1 week)**: Dev team only
2. **Beta group 1 (1 week)**: 100 users who opted in
3. **Beta group 2 (1 week)**: 1000 users
4. **General release**: Monitor for 401 errors spike

### Phase 3 Testing: Garmin OAuth 2.0

#### Pre-Migration Checklist

- [ ] All existing users have `userId` populated in database
- [ ] Contact Garmin support to enable OAuth 2.0 for consumer key
- [ ] Receive new OAuth 2.0 client secret from Garmin portal
- [ ] Test token exchange endpoint with test account

#### Token Exchange Testing

```bash
# Test token exchange (OAuth 1.0 -> OAuth 2.0)
# Must be signed with OAuth 1.0 credentials

curl -X POST "https://apis.garmin.com/partner-gateway/rest/user/token-exchange" \
    -H "Authorization: $OAUTH1_SIGNED_HEADER" \
    -H "Content-Type: application/json"

# Expected response:
# {
#   "access_token": "...",
#   "refresh_token": "...",
#   "expires_in": 86400,
#   "refresh_token_expires_in": 7775998
# }
```

#### Token Refresh Testing

```php
// Test that token refresh works before expiry
function test_token_refresh(){
    // Create user with OAuth 2.0 tokens that expire in 1 second
    $token_id = $this->createOAuth2User(expires_in: 1);

    sleep(2);

    // Should trigger refresh
    $garmin_token = $this->process->get_valid_garmin_token($token_id);

    // Verify new token was fetched
    $this->assertNotEquals($original_token, $garmin_token['token']);
}
```

#### Webhook Testing

```bash
# Simulate OAuth 2.0 style webhook (with userId, no userAccessToken)
curl -X POST "$BASE_URL/api/garmin/activities" \
    -H "Content-Type: application/json" \
    -d '{
        "activities": [{
            "userId": "d3315b1072421d0dd7c8f6b8e1de4df8",
            "summaryId": "test-123",
            "activityType": "RUNNING",
            "startTimeInSeconds": 1704067200
        }]
    }'

# Verify activity was associated with correct user
```

### Monitoring & Alerting

#### Key Metrics to Monitor

```
┌─────────────────────────────────────────────────────────────┐
│  Metric                          │  Alert Threshold         │
├─────────────────────────────────────────────────────────────┤
│  401 Unauthorized rate           │  > 1% of requests        │
│  /get_server_token calls         │  Spike detection         │
│  Token refresh failures          │  Any failures            │
│  Webhook processing failures     │  > 0.1% of webhooks      │
│  OAuth 1.0 vs Bearer auth ratio  │  Track migration prog.   │
└─────────────────────────────────────────────────────────────┘
```

#### Logging for Debugging

```php
// Add to authenticate_header()
function authenticate_header($token_id){
    $auth_method = 'unknown';
    $auth_result = 'failed';

    // ... authentication logic ...

    // Log for monitoring
    error_log(sprintf(
        "AUTH: token_id=%d method=%s result=%s ip=%s",
        $token_id, $auth_method, $auth_result, $_SERVER['REMOTE_ADDR']
    ));
}
```

### Rollback Plan

#### Phase 1 Rollback
- Server continues accepting OAuth 1.0
- No action needed, old auth still works

#### Phase 2 Rollback
- Push app update that reverts to OAuth 1.0 only
- Server still accepts OAuth 1.0

#### Phase 3 Rollback
- OAuth 1.0 tokens remain valid for 30 days after exchange
- Can revert server to use OAuth 1.0 tokens if still within window

---

## Timeline

```
2025                                                    2026
  │                                                       │
  Q1        Q2        Q3        Q4        Q1        Q4   │
  │         │         │         │         │         │    │
  ▼         ▼         ▼         ▼         ▼         ▼    ▼
┌─────────┬─────────┬─────────┬─────────┬─────────┬─────────┐
│ Phase 1 │ Phase 1 │ Phase 2 │ Phase 2 │ Phase 3 │ Phase 3 │
│ Dev     │ Deploy  │ Dev     │ Release │ Dev     │ Migrate │
│         │ +Test   │         │         │         │ Users   │
└─────────┴─────────┴─────────┴─────────┴─────────┴─────────┘
                                                          │
                                              Dec 31, 2026 │
                                              OAuth 1.0    │
                                              Retired ─────┘
```

## Configuration Changes

### New Config Values

```php
// api/config.php additions

// OAuth 2.0 credentials (from Garmin portal after migration enabled)
'oauth2ClientId' => 'your-oauth2-client-id',
'oauth2ClientSecret' => 'your-oauth2-client-secret',

// Feature flags for gradual rollout
'allow_bearer_auth' => true,      // Phase 1: enable Bearer token auth
'allow_oauth1_auth' => true,      // Keep true until Phase 4
'require_server_token' => false,  // Phase 2: require server token for new registrations
```

## References

- [Garmin OAuth 2.0 PKCE Specification](https://developerportal.garmin.com/developer-programs/content/829/programs-docs)
- [Garmin OAuth 1.0 to OAuth 2.0 Migration Guide](https://developer.garmin.com/health-api/overview/)
- Original design docs: [authentication.md](authentication.md), [garmin-integration.md](garmin-integration.md)
