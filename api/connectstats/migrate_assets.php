<?php

include_once( "../shared.php" );

$process = new GarminProcess();

$process->ensure_commandline($argv??NULL);


if( isset( $argv[1] ) ){
    $cs_user_id = intval( $argv[1] );
}else{
    $limit = 1;
}
if( isset( $argv[2] ) ){
    $limit = intval( $argv[2] );
}else{
    $limit = 20;
}

$process->set_verbose(true);
$process->ensure_schema();
$process->maintenance_s3_upload_backup_assets( $cs_user_id, $limit  );
?>
