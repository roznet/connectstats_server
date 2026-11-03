<?php
/**
 *  Local mock of the callbackURL Garmin sends for an activity file (dev stack only).
 *
 *  Returns a minimal FIT header, only for the test user's live token.
 */
$headers = function_exists('apache_request_headers') ? apache_request_headers() : [];
$auth = $headers['Authorization'] ?? $headers['authorization'] ?? '';

if( !preg_match( '/oauth_token="([^"]+)"/', $auth, $m ) || urldecode( $m[1] ) !== 'testtoken' ){
    header( 'HTTP/1.1 401 Unauthorized' );
    exit;
}
print( "\x0e\x10\x00\x00\x00\x00\x00\x00.FIT\x00\x00" );
