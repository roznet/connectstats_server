<?php
include_once( "../shared.php" );
include_once( "../phpFITFileAnalysis.php" );

$process = new GarminProcess();

$process->ensure_commandline( $argv??NULL );

$process->set_verbose(true);

$debug = false;

if( isset( $argv[1] ) ){
    array_shift( $argv ); // get rid of 0
    $cbids = $argv ;
    
    foreach( $cbids as $arg_file_id ){
        $file_id = $process->validate_input_id($arg_file_id);
    
        $data = $process->query_file( NULL, NULL, $file_id );

        if( strlen( $data ) ){
            try {
                $fit = new adriangibbons\phpFITFileAnalysis( $data, array( 'input_is_data' => true ) );
                $process->fit_extract( $file_id, $fit->data_mesgs );
            }catch(Exception $e ){
                printf( "ERROR: can't process fit file for id %d. Error: %s", $file_id, $e->getMessage() );
            }
        }
    }
}
exit();
?>
