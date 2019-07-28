<?php

include_once('../shared.php');

$process = new GarminProcess();

$process->authenticate_system_call();
$process->ensure_schema();

$done = false;
if( isset( $_GET['database'] ) && $_GET['table'] ){
    $db = $_GET['database'];
    $table = $_GET['table'];
    $keys = array( 'activities' => 'activity_id',
                   'backfills' => 'backfill_id',
                   'assets' => 'asset_id',
                   'fitfiles' => 'file_id',
                   'tokens' => 'token_id',
                   'users' => 'user_id'
    );

    if( isset( $keys[ $table ] ) && isset( $_GET[ $keys[ $table ] ] ) ){
        $key = $keys[$table];
        $key_start = $_GET[ $keys[$table] ];
        $process->maintenance_export_table( $table, $key, $key_start );
    }
}
if( ! $done ){
    header( 'HTTP/1.1 400 Bad Request' );
    die;
}
?>
