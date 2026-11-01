<?php
/**
 *  Local mock of Garmin's  GET /wellness-api/rest/user/id  (dev stack only).
 *
 *  Returns {"userId": ...} for the OAuth token in the Authorization header:
 *  testtoken -> testuser (matches setup/sample-*.json), anything else -> user-<token>.
 */
$headers = function_exists('apache_request_headers') ? apache_request_headers() : [];
$auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';

if( !preg_match( '/oauth_token="([^"]+)"/', $auth, $m ) ){
    header( 'HTTP/1.1 401 Unauthorized' );
    exit;
}
$token = urldecode( $m[1] );
$users = [ 'testtoken' => 'testuser' ];

header( 'Content-Type: application/json' );
print( json_encode( [ 'userId' => $users[$token] ?? 'user-'.$token ] ) );
