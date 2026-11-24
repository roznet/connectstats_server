<?php
/*
 *  MIT Licence
 *
 *  Copyright (c) 2019 Brice Rosenzweig.
 *
 *  Permission is hereby granted, free of charge, to any person obtaining a copy
 *  of this software and associated documentation files (the "Software"), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is
 *  furnished to do so, subject to the following conditions:
 *  
 *  The above copyright notice and this permission notice shall be included in all
 *  copies or substantial portions of the Software.
 *  
 *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *  FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 *  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 *  SOFTWARE.
 *  
 * -----------
 *
 *  This entry point can be use to provide status of the api at this location
 *      a redirect means a different url should be used
 */

include( stream_resolve_include_path( 'config.php' ) ?: dirname( __DIR__ ) . '/config.php' );
header('Content-Type: application/json');
$status = array(
    'status' => 1,
);

# This should be full url of the base of redirect api, for example 'https://myserver.example.com/dev'
if( isset( $api_config['api_redirect_url'] ) ){
    $status[ 'redirect' ] = $api_config['api_redirect_url'];
}
# Garmin login flow for new logins (see designs/oauth2-migration.md). Sent only with oauth2ClientId,
# and either garmin_login = oauth2 (everyone) or the caller's IP in garmin_login_test_ips (a list,
# to try OAuth 2.0 login from one phone first). Otherwise the app uses OAuth 1.0. The client id
# isn't secret: it's in the authorization URL. REMOTE_ADDR is the client's IP (Apache remoteip
# behind Caddy).
$garmin_login_test_ips = $api_config['garmin_login_test_ips'] ?? array();
if( ! is_array( $garmin_login_test_ips ) ){
    $garmin_login_test_ips = array_map( 'trim', explode( ',', (string)$garmin_login_test_ips ) );
}
$garmin_login_on = ( $api_config['garmin_login'] ?? '' ) === 'oauth2'
    || in_array( $_SERVER['REMOTE_ADDR'] ?? '', $garmin_login_test_ips, true );
if( $garmin_login_on && ! empty( $api_config['oauth2ClientId'] ) ){
    $status[ 'garmin_login' ] = 'oauth2';
    $status[ 'garmin_client_id' ] = $api_config['oauth2ClientId'];
}
print( json_encode( $status ) );
?>
