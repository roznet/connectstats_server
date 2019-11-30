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
 * This entry point should be called only for maintenance and backup
 * It will need to be authenticated by the system token
 *
 * GET Parameters:
 * REQUIRED:
 * One of:
 *   summary_id: the summary id for the item to be retrieved
 *   table: the table to retrieve
 */

include_once('../shared.php' );

$process = new GarminProcess();


if( isset( $_GET['table'] ) ){
    
    $paging = new Paging( $_GET, Paging::SYSTEM_TOKEN, $process->sql );

    if( $paging->summary_file_query() ){
        // If one file, authenticate by the user of the file
        $user = $process->sql->query_first_row( sprintf( "SELECT cs_user_id FROM fitfiles WHERE summaryId = %d", $paging->summary_id ) );
        if( isset($user['cs_user_id']) ){
            $token = $process->sql->query_first_row( sprintf( "SELECT token_id FROM tokens WHERE cs_user_id = %d ORDER BY token_id DESC LIMIT 1", $user['cs_user_id'] ) );
            $token_id = $token['token_id'];
        }
        if( ! isset( $token_id ) ){
            die;
        }
        $process->authenticate_header($token_id );
    }else{
        // Ensure that the call is authenticated with the system token
        $process->authenticate_system_call();
    }
    
    $table = $_GET['table'];

    if( $table == 'fitfiles' ){
        $data = $process->query_file( $paging );
        $filename = sprintf( '%s.%s', $paging->filename_identifier(), $paging->activities_only_one() ? 'fit' : 'zip' );
        
        if( $data ){
            if( isset( $_GET['debug'] ) && $_GET['debug'] == 1 ){
                printf( 'fit file with %lu bytes'.PHP_EOL, strlen( $data ) );
            }else{
                header('Content-Type: application/octet-stream');
                header(sprintf('Content-Disposition: attachment; filename=%s', $filename ));
                print( $data );
                $status = 0;
            }
        }else if( isset( $_GET['debug'] ) && $_GET['debug'] == 1 ){
            printf( 'no data found'.PHP_EOL );
        }
    }
}
?>
