<?php

ini_set('memory_limit', '256M' );
    
include_once( "../shared.php" );

$process = new GarminProcess();

$process->ensure_commandline($argv??NULL);


if( isset( $argv[1] ) ){
    $limit = intval( $argv[1] );
}else{
    $limit = 20;
}

$process->set_verbose(true);
$process->ensure_schema();

if( isset( $argv[2] ) ){
    $cs_user_id = intval( $argv[2] );
    $process->maintenance_s3_upload_backup_assets_for_user( $cs_user_id  );
}else{
    $process->maintenance_s3_upload_backup_assets( $limit  );
}

?>
