<?php 

include_once('../shared.php' );

$process = new GarminProcess();


if( isset( $_GET['token_id'] ) ){
    $token_id = intval($_GET['token_id']);

    $process->authenticate_header($token_id);

    $user = $process->validate_user($token_id);

    if( isset( $user['cs_user_id'] ) ){
        $rv = $process->notification_register($user['cs_user_id'], $_GET);
        print( json_encode( $rv ) );
    }else{
        header('HTTP/1.1 400 Bad Request');
    }
}

?>
