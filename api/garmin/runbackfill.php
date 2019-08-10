<?php

include_once('../shared.php');

$process = new GarminProcess();

if( isset( $argv[1] ) && isset( $argv[2] )){
    $token_id = $process->validate_input_id($argv[1]);
    $days = MIN(intval($argv[2]),90); // no more than 90 days
    if( isset( $argv[3] ) ){
        $sleep = MAX(MIN(intval($argv[3]),300),3);
    }else{
        $sleep = 100;
    }
    $process->set_verbose(true);
    if( $process->run_backfill( $token_id, $days, $sleep ) ){
        print( "More to do".PHP_EOL);
    }else{
        print( "Done".PHP_EOL);
    }
}

?>



