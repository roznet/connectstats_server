<?php

include_once('../shared.php');

$process = new GarminProcess();

if( isset( $_GET['token_id'] ) && isset( $_GET['start_year'] ) ){
    $token_id = intval($_GET['token_id']);

    $force = false;
    if( isset( $_GET['force'] ) && $_GET['force'] == 1 ){
        $force = true;
    }
    
    $year = max(intval($_GET['start_year']),2000);
    $date = mktime(0,0,0,1,1,$year);
    $status = $process->backfill_should_start( $token_id, $date, $force );
    if( $status['start'] == true ){
        $process->backfill( $token_id, $date );
    }
    print( json_encode( $status ) );
}
?>



