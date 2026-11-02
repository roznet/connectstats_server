<?php
// Mock of Garmin's "delete user registration" endpoint. Checks the OAuth 1.0 signature
// against the request's actual method, like Garmin. The token secret has to be
// "secret-" + token. Tokens starting with "dead" get a 403, "limited" a 429.
include('config.php');

$fields = [];
$headers = function_exists('apache_request_headers') ? apache_request_headers() : [];
preg_match_all('/([a-z_]+)="([^"]*)"/', $headers['Authorization'] ?? $headers['authorization'] ?? '', $matches, PREG_SET_ORDER);
foreach ($matches as $m) {
    $fields[$m[1]] = rawurldecode($m[2]);
}
$token = $fields['oauth_token'] ?? '';
$signature = $fields['oauth_signature'] ?? '';
unset($fields['oauth_signature']);
ksort($fields);
$pairs = [];
foreach ($fields as $k => $v) {
    $pairs[] = "$k=$v";
}
$url = sprintf('http://%s%s', $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI']);
$base = sprintf('%s&%s&%s', $_SERVER['REQUEST_METHOD'], rawurlencode($url), rawurlencode(implode('&', $pairs)));
$key = rawurlencode($api_config['consumerSecret']) . '&' . rawurlencode('secret-' . $token);

if ($_SERVER['REQUEST_METHOD'] !== 'DELETE' || !hash_equals(base64_encode(hash_hmac('sha1', $base, $key, true)), $signature)) {
    http_response_code(401);
} elseif (strpos($token, 'dead') === 0) {
    http_response_code(403);
} elseif (strpos($token, 'limited') === 0) {
    http_response_code(429);
} else {
    http_response_code(204);
}
