<?php
/*
 *  MIT Licence
 *
 *  Copyright (c) 2026 Brice Rosenzweig.
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
 */

/*
 * Login with Garmin OAuth 2.0 (PKCE), see designs/oauth2-migration.md.
 *
 * POST code, code_verifier, redirect_uri (form fields, so they stay out of URLs and logs).
 * Returns {token_id, cs_user_id, userId, server_token, permissions, activity_export}.
 * The app then authenticates with `Authorization: Bearer <server_token>` and token_id.
 *
 * Off (503) until oauth2ClientId and oauth2ClientSecret are in the config.
 */

include_once('../shared.php');

$process = new GarminProcess();
header('Content-Type: application/json');

if( $_SERVER['REQUEST_METHOD'] !== 'POST' ){
    http_response_code( 405 );
    print( json_encode( array( 'error' => 'POST required' ) ) );
    exit;
}
if( ! $process->oauth2->is_configured() ){
    http_response_code( 503 );
    print( json_encode( array( 'error' => 'OAuth 2.0 login is not enabled' ) ) );
    exit;
}

$code = $_POST['code'] ?? '';
$code_verifier = $_POST['code_verifier'] ?? '';
$redirect_uri = $_POST['redirect_uri'] ?? '';

// PKCE verifiers use [A-Za-z0-9-._~] (RFC 7636). Garmin's code format isn't documented: accept any
// printable ASCII, it's only passed on to Garmin's token endpoint (form-encoded), never to SQL
if( ! preg_match( '/^[\x21-\x7E]{1,2048}$/', $code ) || ! preg_match( '/^[-A-Za-z0-9._~]{43,128}$/', $code_verifier )
    || $redirect_uri === '' || strlen( $redirect_uri ) > 512 || ! parse_url( $redirect_uri, PHP_URL_SCHEME ) ){
    http_response_code( 400 );
    print( json_encode( array( 'error' => 'code, code_verifier and redirect_uri are required' ) ) );
    exit;
}

list( $status, $response ) = $process->register_user_oauth2( $code, $code_verifier, $redirect_uri );
http_response_code( $status );
print( json_encode( $response ) );
