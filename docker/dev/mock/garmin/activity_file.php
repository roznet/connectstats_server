<?php
/**
 *  Local mock of the callbackURL Garmin sends for an activity file (dev stack only).
 *
 *  Returns a minimal FIT header, only for the test user: its live OAuth 1.0 token, or an
 *  OAuth 2.0 access token for testuser from oauth2.php.
 */
$headers = function_exists('apache_request_headers') ? apache_request_headers() : [];
$auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';

$bearer_ok = preg_match( '/^Bearer at\.([-_A-Za-z0-9]+)\.[0-9a-f]+$/', $auth, $b )
    && base64_decode( strtr( $b[1], '-_', '+/' ) ) === 'testuser';
$oauth1_ok = preg_match( '/oauth_token="([^"]+)"/', $auth, $m ) && urldecode( $m[1] ) === 'testtoken';
if( !$bearer_ok && !$oauth1_ok ){
    header( 'HTTP/1.1 401 Unauthorized' );
    exit;
}
print( "\x0e\x10\x00\x00\x00\x00\x00\x00.FIT\x00\x00" );
