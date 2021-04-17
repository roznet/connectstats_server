<?php 

include_once('../shared.php' );

$process = new GarminProcess();


if( isset( $_GET['token_id'] ) ){
    $token_id = intval($_GET['token_id']);

    $process->authenticate_header($token_id);

    $rv = $process->notification_register($token_id, $_GET);

    print( json_encode( $rv ) );
}

?>
