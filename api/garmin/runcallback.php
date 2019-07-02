<?php
include_once('../shared.php');

if( isset( $argv[1] ) ){
    array_shift( $argv ); // get rid of 0
    $table = array_shift( $argv );
    
    $cbids = $argv;
    $process = new GarminProcess();
    $process->set_verbose(true);
    $process->run_file_callback( $table, $cbids );
}
?>
