<?php
include_once('../shared.php');

$process = new GarminProcess();
$process->set_verbose(true);

$process->ensure_commandline($argv??NULL);

if( isset( $argv[1] ) ){
    array_shift( $argv ); // get rid of 0
    $table = array_shift( $argv );
    
    $cbids = $argv;
    $process->run_file_callback( $table, $cbids );
}
printf( 'EXIT: Success'.PHP_EOL );
exit();
?>
