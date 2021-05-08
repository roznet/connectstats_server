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
 * This entry point should be called to register a userAccessTOken and save the corresponding userAccessToken
 * if the token is valid, 
 *
 * this will call config['url_user_id'] to verify the token and secret and get the userId corresponding to the token
 *
 * GET Parameters:
 * REQUIRED:
 *   userAccessToken: the userAccessToken from the garmin api
 *   userAccessTokenSecret: the userAccessTokenSecret from the garmin api
 */

include_once('../shared.php');

$process = new GarminProcess();

if( isset( $_GET['userAccessToken'] ) && isset( $_GET['userAccessTokenSecret'] ) ){
    $values = $process->register_user( $process->validate_token( $_GET['userAccessToken'] ), $process->validate_token( $_GET['userAccessTokenSecret'] ) );
    if( $values ) {
        print( json_encode( $values ) );
    }else{
        print( json_encode( array( 'error' => 'Failed to register' ) ) );
    }
}else{
    header('HTTP/1.1 400 Bad Request');
}

?>
