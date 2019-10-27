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
 * This entry point should be called to get access to json data for an activity for a given user
 *
 * GET Parameters:
 * REQUIRED:
 *   token_id: the token_id returned after the user_register call
 * One of:
 *   table: table to get the json from (either weather or fitsession)
 *   activity_id: if the activity_id is provided, the file for that activity will be returned
 *   file_id: if the file_id is known, the file for that id is returned
 */

include_once('../shared.php' );

$process = new GarminProcess();

if( isset( $_GET['token_id'] ) ){
    $token_id = intval($_GET['token_id']);

    // Ensure that the call is authenticated for the corresponding tokent id
    $process->authenticate_header($token_id);

    $paging = new Paging( $_GET, $token_id, $process->sql );

    $tables = array();
    if( isset( $_GET['table'] ) ){
        // same validation as a token
        $table_input = $process->validate_token( $_GET['table'] );
        if( $table_input == 'weather' ){
            array_push($tables, 'weather');
        }else if( $table_input == 'fitsession' ){
            array_push($tables, 'fitsession' );
        }else if( $table_input == 'all' ){
            $tables = [ 'fitsession', 'weather' ];
        }else{
            header('HTTP/1.1 400 Bad Request');
            die;
        }
    }else{
        header('HTTP/1.1 400 Bad Request');
        die;
    }

    $data = $process->query_json( $tables, $paging );
    if( ! $process->verbose ) {
        $filename = sprintf( '%s.json', $table_input );
        header('Content-Type: application/json');
        header(sprintf('Content-Disposition: attachment; filename=%s', $filename ) );
    }
    print( json_encode($data) );
    $process->record_usage( $paging );
    
}

?>
