<?php

include_once('../shared.php');

$process = new GarminProcess();

$process->authenticate_system_call();
$process->ensure_schema();

$done = false;
if( $_GET['table'] ){
    $table = $_GET['table'];
    $keys = array( 'activities' => 'activity_id',
                   'backfills' => 'backfill_id',
                   'assets' => 'asset_id',
                   'fitfiles' => 'file_id',
                   'tokens' => 'token_id',
                   'users' => 'cs_user_id',
                   'usage' => 'usage_id',
                   'weather' => 'file_id',
                   'fitsession' => 'file_id',
                   'cache_activities' => 'cache_id',
                   'cache_fitfiles' => 'cache_id'
    );

    if( isset( $keys[ $table ] ) && isset( $_GET[ $keys[ $table ] ] ) ){
        $key = $keys[$table];
        $key_start = $_GET[ $keys[$table] ];
        $done = $process->maintenance_export_table( $table, $key, $key_start );
    }
}
// If _GET['cache'] = table (fitfiles|activities) _GET['cache_id'] = start cache_id _GET['n'] => max number
// TODO:
if( ! $done ){
    header( 'HTTP/1.1 400 Bad Request' );
    die;
}
?>
