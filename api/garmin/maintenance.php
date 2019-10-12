<?php

include_once( "../shared.php" );

$process = new GarminProcess();

$process->ensure_commandline($argv??NULL);

if( isset( $argv[1] ) ){
    $limit = intval( $argv[1] );
}else{
    $limit = 20;
}

if( isset( $argv[2] ) ){
    $user_id = intval( $argv[2] );
}else{
    $user_id = NULL;
}
$process->set_verbose(true);
$process->ensure_schema();
$process->maintenance_fix_missing_callback($user_id, $limit);
$process->maintenance_link_activity_files($user_id, $limit);
?>
