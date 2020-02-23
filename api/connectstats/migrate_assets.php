<?php

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
$process->maintenance_migrate_assets($limit);
?>
