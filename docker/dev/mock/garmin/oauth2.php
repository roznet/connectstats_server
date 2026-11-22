<?php
/**
 *  Local mock of Garmin's OAuth 2.0 endpoints (dev stack only), selected by ?op=
 *
 *    token         POST, diauth token endpoint: grant_type authorization_code or refresh_token
 *    exchange      POST, OAuth 1.0 signed token exchange (partner-gateway/rest/user/token-exchange)
 *    user_id       GET, Bearer
 *    permissions   GET, Bearer
 *    registration  DELETE, Bearer
 *
 *  Tokens encode the Garmin userId, so no state is needed except used refresh tokens:
 *    access token  at.<base64url userId>.<random>     ("at.<b64>.stale" gets a 401)
 *    refresh token rt.<base64url userId>.<random>     single use, like Garmin's
 *    auth code     code.<base64url userId>            ("bad..." gets a 400)
 *  userIds starting with "revoked" can't refresh (400), "flaky" get a 503 on refresh,
 *  "noexport" don't have the ACTIVITY_EXPORT permission.
 *  OAuth 1.0 token -> userId as in user_id.php; exchange signatures are checked with the
 *  test secrets (testtoken/testsecret...) or "secret-" + token; tokens starting "dead" get a 401.
 */
include('config.php');

function b64u_encode($s) { return rtrim(strtr(base64_encode($s), '+/', '-_'), '='); }
function b64u_decode($s) { return base64_decode(strtr($s, '-_', '+/')); }
function reply($code, $data = null) {
    http_response_code($code);
    if ($data !== null) {
        header('Content-Type: application/json');
        print(json_encode($data));
    }
    exit;
}
function issue($userId) {
    return [
        'access_token' => 'at.' . b64u_encode($userId) . '.' . bin2hex(random_bytes(6)),
        'token_type' => 'bearer',
        'refresh_token' => 'rt.' . b64u_encode($userId) . '.' . bin2hex(random_bytes(6)),
        'expires_in' => 86400,
        'refresh_token_expires_in' => 7775998,
        'scope' => 'PARTNER_WRITE PARTNER_READ CONNECT_READ CONNECT_WRITE',
        'jti' => bin2hex(random_bytes(8)),
    ];
}
function token_user($token, $prefix) {
    $parts = explode('.', $token);
    if (count($parts) < 2 || $parts[0] !== $prefix) {
        return null;
    }
    $u = b64u_decode($parts[1]);
    return $u === false || $u === '' ? null : $u;
}
function bearer_user() {
    $headers = function_exists('apache_request_headers') ? apache_request_headers() : [];
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    if (stripos($auth, 'Bearer ') !== 0) {
        reply(401);
    }
    $token = trim(substr($auth, 7));
    $userId = token_user($token, 'at');
    if (!$userId || substr($token, -6) === '.stale') {
        reply(401);
    }
    return $userId;
}
function client_ok($api_config) {
    $headers = function_exists('apache_request_headers') ? apache_request_headers() : [];
    $auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';
    $id = $_POST['client_id'] ?? null;
    $secret = $_POST['client_secret'] ?? null;
    if (stripos($auth, 'Basic ') === 0) {
        list($id, $secret) = array_map('rawurldecode', explode(':', base64_decode(substr($auth, 6)), 2) + [1 => '']);
    }
    return $id === ($api_config['oauth2ClientId'] ?? '') && $secret === ($api_config['oauth2ClientSecret'] ?? '');
}

$op = $_GET['op'] ?? '';
$used_file = '/var/www/tmp/mock_oauth2_used_refresh_tokens';

if ($op === 'token') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply(405);
    if (!client_ok($api_config)) reply(401, ['error' => 'invalid_client']);
    $grant = $_POST['grant_type'] ?? '';
    if ($grant === 'authorization_code') {
        $code = $_POST['code'] ?? '';
        $userId = token_user($code, 'code');
        if (!$userId || strlen($_POST['code_verifier'] ?? '') < 43 || empty($_POST['redirect_uri'])) {
            reply(400, ['error' => 'invalid_grant']);
        }
        reply(200, issue($userId));
    }
    if ($grant === 'refresh_token') {
        $rt = $_POST['refresh_token'] ?? '';
        $userId = token_user($rt, 'rt');
        $used = is_file($used_file) ? file($used_file, FILE_IGNORE_NEW_LINES) : [];
        if (!$userId || in_array($rt, $used, true) || strpos($userId, 'revoked') === 0) {
            reply(400, ['error' => 'invalid_grant']);
        }
        if (strpos($userId, 'flaky') === 0) {
            reply(503);
        }
        file_put_contents($used_file, $rt . "\n", FILE_APPEND | LOCK_EX);
        reply(200, issue($userId));
    }
    reply(400, ['error' => 'unsupported_grant_type']);
}

if ($op === 'exchange') {
    if ($_SERVER['REQUEST_METHOD'] !== 'POST') reply(405);
    $fields = [];
    $headers = function_exists('apache_request_headers') ? apache_request_headers() : [];
    preg_match_all('/([a-z_]+)="([^"]*)"/', $headers['Authorization'] ?? $headers['authorization'] ?? '', $matches, PREG_SET_ORDER);
    foreach ($matches as $m) {
        $fields[$m[1]] = rawurldecode($m[2]);
    }
    $token = $fields['oauth_token'] ?? '';
    $signature = $fields['oauth_signature'] ?? '';
    unset($fields['oauth_signature']);
    $get = $_GET;
    $all = array_merge($fields, $get);
    ksort($all);
    $pairs = [];
    foreach ($all as $k => $v) {
        $pairs[] = "$k=$v";
    }
    $url = sprintf('http://%s%s', $_SERVER['HTTP_HOST'], parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH));
    $base = sprintf('POST&%s&%s', rawurlencode($url), rawurlencode(implode('&', $pairs)));
    $secrets = ['testtoken' => 'testsecret', 'testtoken2' => 'testsecret2'];
    $key = rawurlencode($api_config['consumerSecret']) . '&' . rawurlencode($secrets[$token] ?? 'secret-' . $token);
    if (!$token || !hash_equals(base64_encode(hash_hmac('sha1', $base, $key, true)), $signature) || strpos($token, 'dead') === 0) {
        reply(401);
    }
    // testtoken-b: a second token of the same Garmin user
    $users = ['testtoken' => 'testuser', 'testtoken-b' => 'testuser'];
    reply(200, issue($users[$token] ?? 'user-' . $token));
}

if ($op === 'user_id') {
    reply(200, ['userId' => bearer_user()]);
}
if ($op === 'permissions') {
    $userId = bearer_user();
    // Garmin's format (seen 2026-11-22): {"permissions":[...]}
    reply(200, ['permissions' => strpos($userId, 'noexport') === 0 ? ['HEALTH_EXPORT'] : ['ACTIVITY_EXPORT', 'HEALTH_EXPORT', 'WORKOUT_IMPORT']]);
}
if ($op === 'registration') {
    if ($_SERVER['REQUEST_METHOD'] !== 'DELETE') reply(405);
    bearer_user();
    reply(204);
}
reply(404);
