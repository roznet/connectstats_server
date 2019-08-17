<?php
include_once( "../shared.php" );
include_once( "../phpFITFileAnalysis.php" );

$process = new GarminProcess();

$process->ensure_commandline( $argv??NULL );

$process->set_verbose(true);

$debug = false;

if( isset( $argv[1] ) ){
    $file_id = $process->validate_input_id($argv[1]);
    
    $data = $process->query_file( NULL, NULL, $file_id );

    if( strlen( $data ) ){
        $fit = new adriangibbons\phpFITFileAnalysis( $data, array( 'input_is_data' => true ) );
        $process->fit_extract( $file_id, $fit->data_mesgs );
    }
}
?>
