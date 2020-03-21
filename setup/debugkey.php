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

$process =  new GarminProcess();

if( $process->ensure_commandline($argv,1) ){
    $debugkey = new DebugKey();
    $tokenIds = $argv;
    array_shift( $tokenIds );


    $filter = array();
    foreach( $tokenIds as $tokenId ){
        array_push( $filter, sprintf( 'token_id = %d', $tokenId ) );
    }
    $where = implode( ' OR ', $filter );
    $query = sprintf( 'SELECT token_id,cs_user_id,userAccessToken, userAccessTokenSecret FROM tokens WHERE %s', $where );
    $rv = $process->sql->query_as_array( $query );
    if( $rv ){
        $file = 'debugkey.json';
        if( is_file( $file ) ){
            $added = $rv[0]['token_id'];
            $previous = json_decode( file_get_contents( $file ), true );

            foreach( $previous as $one){
                if( $one['token_id'] != $added){
                    array_push( $rv, $one );
                }
            }
        }
        printf( 'Wrote %d keys into %s'.PHP_EOL, count( $rv ), $file );
        file_put_contents( $file, json_encode( $rv ) );
    }else{
        printf( 'Query result empty %s'.PHP_EOL, $query );
    }
}else{

    header('HTTP/1.1 403 Forbidden');
    die;
}

?>
