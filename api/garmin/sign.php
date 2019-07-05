<?php 
//
// This is intended to only be run from the command line, not remotely
//


include_once('../shared.php');

if( isset( $argv[1] ) && isset( $argv[2] )  ){
    $process = new GarminProcess();
    
    $token_id = $process->validate_input_id( $argv[1] );
    $url = $process->validate_url( $argv[2] );

    $process->verbose = true;

    print( $process->authorization_header_for_token_id( $url, $token_id  ) .PHP_EOL );
}


?>
