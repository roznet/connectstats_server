<?php

// In order to debug, get a json file with keys to connect from the app
// This can only be run on the server directly via ssh, if you have proper access
// can't be accessed from the web

include_once( '../api/shared.php' );

class DebugKey {
    function __construct(){
        $this->process = new GarminProcess();
    }

}

if( isset( $argv[1] ) && ! isset( $_SERVER['HTTP_HOST'] ) && ! isset( $_SERVER['REQUEST_METHOD'] ) ){
    $debugkey = new DebugKey();
    $tokenIds = $argv;
    array_shift( $tokenIds );

    $process =  new GarminProcess();

    $filter = array();
    foreach( $tokenIds as $tokenId ){
        array_push( $filter, sprintf( 'token_id = %d', $tokenId ) );
    }
    $where = implode( ' OR ', $filter );
    $query = sprintf( 'SELECT token_id,cs_user_id,userAccessToken, userAccessTokenSecret FROM tokens WHERE %s', $where );
    print( $query.PHP_EOL );
    $rv = $process->sql->query_as_array( $query );
    print( json_encode( $rv ) . PHP_EOL);
}else{

    header('HTTP/1.1 403 Forbidden');
    die;
}

?>
