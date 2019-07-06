<?php 

include_once('../shared.php' );

$process = new GarminProcess();

if( isset( $_GET['token_id'] ) ){
    $token_id = intval($_GET['token_id']);

    $process->authenticate_header($token_id);

    $rv = $process->validate_user($token_id);

    print( json_encode( $rv ) );
}

?>
