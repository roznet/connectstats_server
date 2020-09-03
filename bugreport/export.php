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
 *
 */

/*
 * 
 */

include_once( dirname( __DIR__, 1 ) ) . '/api/sql_helper.php';


$debug = isset( $_GET['debug'] );

if( isset( $_GET['id'] ) ){
    include('config_bugreport.php');

    
    $id = $_GET['id'];
    if( $id > 0 ){
        $sql = new sql_helper($bug_config);
        $bug_data_directory = $bug_config['bug_data_directory'];

        $row = $sql->query_first_row( "SELECT * FROM gc_bugreports WHERE id = $id" );
        if( $row ){
            if( isset( $_GET['zip'] ) && isset( $row['filename'] ) ){
                $filename = $row['filename'];
                header('Content-Type: application/octet-stream');
                header(sprintf('Content-Disposition: attachment; filename=%s', $filename ));
                header("Content-Description: File Transfer");
                header("Content-type: application/octet-stream");
                header("Content-Disposition: attachment; filename=\"".$filename."\"");
                print( file_get_contents( sprintf( '%s/%s', $bug_data_directory, $filename ) ) );
            }else{
                header('Content-Type: application/json;charset=utf-8');
                print( json_encode( $row ) );
            }
        }else{
            header('HTTP/1.1 404 Resource not found');
        }
    }else{
        header('HTTP/1.1 400 bad request');
    }
}else{
    header('HTTP/1.1 400 bad request');
}

?>
