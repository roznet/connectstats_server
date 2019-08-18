<?php

include_once( '../api/shared.php' );


$keys = array( 'activities' => 'activity_id',
               'backfills' => 'backfill_id',
               'assets' => 'asset_id',
               'fitfiles' => 'file_id',
               'tokens' => 'token_id',
               'users' => 'cs_user_id'
);

$process = new GarminProcess();

$process->ensure_commandline($argv);
$process->ensure_schema();

$newdata = false;
foreach( $keys as $table => $key ){
    $newdata_for_table = $process->maintenance_backup_table( $table, $key );
    if( $newdata_for_table ){
        $newdata = true;
    }
}
if( $newdata ){
    printf( "Should try more".PHP_EOL );
}

?>
