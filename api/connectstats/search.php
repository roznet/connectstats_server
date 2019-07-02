<?php 

include_once('../shared.php' );

$process = new GarminProcess();

if( isset( $_GET['token_id'] ) ){
    $token_id = intval($_GET['token_id']);

    $process->authenticate_header($token_id);
    
    if( isset( $_GET['start'] ) ){
        $start = intval( $_GET['start']);
    }else{
        $start = 0;
    }
    if( isset( $_GET['limit'] ) ){
        $limit = intval($_GET['limit']);
    }else{
        $limit = 5;
    }

    $token = $process->sql->query_first_row( "SELECT cs_user_id FROM tokens WHERE token_id = $token_id" );

    $process->query_activities( $token['cs_user_id'], $start, $limit );
}
?>
