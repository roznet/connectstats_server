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
 */


include_once( dirname( __DIR__, 1 ) ) . '/sql_helper.php';
include( dirname( __DIR__ ) . '/config.php' );

if( isset( $api_config['database_app'] ) ){
    $sql = new sql_helper($api_config, 'database_app' );
}else{
    header('HTTP/1.1 500 Internal Server Error');
    die;
}
        
header('Content-Type: application/json');

if (!$sql->table_exists('gc_app_messages')) {
    $sql->create_or_alter('gc_app_messages', array(
        'status_id' => 'BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY',
        'status' => 'INT',
        'ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
        'type' => 'VARCHAR(32)',
        'description' => 'VARCHAR(128)',
        'unixtime' => 'BIGINT(20) UNSIGNED',
        'url' => 'VARCHAR(256)'
    ));
}

$status_id = 0;
$status = 1;

$res = $sql->query_as_array( 'SELECT * FROM gc_app_messages ORDER BY status_id DESC LIMIT 5' );
$messages = array();
foreach( $res as $message ){
    $message['status_id'] = intval( $message['status_id'] );
    $message['status'] = intval( $message['status'] );
    $message['unixtime'] = intval( $message['unixtime'] );
    array_push( $messages, $message );
    if( $message['status_id'] > $status_id ){
        $status = intval( $message['status'] );
        $status_id = intval( $message['status_id']);
    }
}
$rv = array(
    'status' => intval( $status),
    'status_id' => intval( $status_id),
    'messages' => $messages,
);
print( json_encode( $rv ) );
?>
