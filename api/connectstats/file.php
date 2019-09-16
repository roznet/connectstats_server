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
 * This entry point should be called to get access to a fit file for a given user
 *
 * GET Parameters:
 * REQUIRED:
 *   token_id: the token_id returned after the user_register call
 * One of:
 *   activity_id: if the activity_id is provided, the file for that activity will be returned
 *   file_id: if the file_id is known, the file for that id is returned
 */

include_once('../shared.php' );

$process = new GarminProcess();

if( isset( $_GET['token_id'] ) ){
    $token_id = intval($_GET['token_id']);

    // Ensure that the call is authenticated for the corresponding tokent id
    $process->authenticate_header($token_id);
    
    if( isset( $_GET['activity_id'] ) ){
        $activity_id = intval( $_GET['activity_id']);
        $filename = sprintf( 'act_%u.fit', $activity_id );
    }else{
        $activity_id = NULL;
    }
    if( isset( $_GET['file_id'] ) ){
        $file_id = intval($_GET['file_id']);
        $filename = sprintf( 'file_%u.fit', $file_id );
    }else{
        $file_id = NULL;
    }

    $token = $process->sql->query_first_row( "SELECT cs_user_id FROM tokens WHERE token_id = $token_id" );

    $data = $process->query_file( $token['cs_user_id'], $activity_id, $file_id );
    if( $data ){
        if( isset( $_GET['debug'] ) && $_GET['debug'] == 1 ){
            printf( 'fit file with %lu bytes'.PHP_EOL, strlen( $data ) );
        }else{
            header('Content-Type: application/octet-stream');
            header(sprintf('Content-Disposition: attachment; filename=%s', $filename ));
            print( $data );
        }
    }
}
?>
