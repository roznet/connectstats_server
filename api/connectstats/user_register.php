<?php

include_once('../shared.php');

$process = new GarminProcess();

if( isset( $_GET['userAccessToken'] ) && isset( $_GET['userAccessTokenSecret'] ) ){
    $values = $process->register_user( $process->validate_token( $_GET['userAccessToken'] ), $process->validate_token( $_GET['userAccessTokenSecret'] ) );
    if( $values ) {
        print( json_encode( $values ) );
    }else{
        print( json_encode( array( 'error' => 'Failed to register' ) ) );
    }
}else{
    header('HTTP/1.1 400 Bad Request');
}

?>
