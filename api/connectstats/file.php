<?php 

include_once('../shared.php' );

$process = new GarminProcess();

if( isset( $_GET['token_id'] ) ){
    $token_id = intval($_GET['token_id']);
    
    $process->authenticate_header($token_id);
    
    if( isset( $_GET['activity_id'] ) ){
        $activity_id = intval( $_GET['activity_id']);
        $filename = sprintf( 'act_%u.fit', $activity_id );
    }else{
        $activity_id = NULL;
    }
    if( isset( $_GET['file_id'] ) ){
        $file_id = intval($_GET['file_id']);
        $filename = sprintf( 'file_%u.fit', $file_id );
    }else{
        $file_id = NULL;
    }

    $token = $process->sql->query_first_row( "SELECT * FROM tokens WHERE token_id = $token_id" );

    $data = $process->query_file( $token['cs_user_id'], $activity_id, $file_id );
    if( $data ){
        if( isset( $_GET['debug'] ) && $_GET['debug'] == 1 ){
            printf( 'fit file with %lu bytes'.PHP_EOL, strlen( $data ) );
        }else{
            header('Content-Type: application/octet-stream');
            header(sprintf('Content-Disposition: attachment; filename=%s', $filename ));
            print( $data );
        }
    }
}
?>
