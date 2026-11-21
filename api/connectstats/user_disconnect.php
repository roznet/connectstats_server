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
 * Logout from the app: removes the user's consent at Garmin (DELETE user/registration,
 * Bearer or OAuth 1.0) and clears the credentials, so the token_id stops working.
 *
 * GET or POST token_id, authenticated like every user endpoint (OAuth 1.0 or Bearer).
 * Returns {status: 1 or 0, garmin: "oauth2"|"oauth1"|"none", garmin_http}.
 */

include_once('../shared.php');

$process = new GarminProcess();
header('Content-Type: application/json');

$token_id = intval( $_POST['token_id'] ?? $_GET['token_id'] ?? 0 );
if( $token_id <= 0 ){
    http_response_code( 400 );
    print( json_encode( array( 'error' => 'token_id required' ) ) );
    exit;
}
$process->authenticate_header( $token_id );

print( json_encode( $process->disconnect_user( $token_id ) ) );
