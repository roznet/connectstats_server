<?php

include_once('../shared.php');

$process = new GarminProcess();

if( isset( $_GET['token_id'] ) && isset( $_GET['start_year'] ) ){
    $token_id = intval($_GET['token_id']);

    $force = false;
    if( isset( $_GET['force'] ) && $_GET['force'] == 1 ){
        $force = true;
    }

    $rv = $process->backfill_api_entry( $token_id, intval($_GET['start_year']), $force );
    print( json_encode( $rv ) );
}
?>



