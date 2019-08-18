<?php

include_once( '../api/shared.php' );


$keys = array( 'activities' => 'activity_id',
               'backfills' => 'backfill_id',
               'assets' => 'asset_id',
               'fitfiles' => 'file_id',
               'tokens' => 'token_id',
	       'users' => 'cs_user_id',
	       'weather' => 'file_id',
	       'fitsession' => 'file_id'
);

$process = new GarminProcess();

$process->ensure_commandline($argv);
$process->ensure_schema();

foreach( $keys as $table => $key ){
    $process->maintenance_backup_table( $table, $key );
}

?>
