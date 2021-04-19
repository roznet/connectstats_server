<?php 

include_once('../shared.php' );

$process = new GarminProcess();

if( isset( $_GET['token_id'] ) ){
    $token_id = intval($_GET['token_id']);

    $process->authenticate_header($token_id);

    $rv = $process->validate_user($token_id);
    // if we have user id, check if necessary to register notification
    if( isset( $user['cs_user_id'] ) ){
        $process->notification_register($rv['cs_user_id'], $_GET);
    }

    print( json_encode( $rv ) );
}

?>
