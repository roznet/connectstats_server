<?php

// In order to debug, get a json file with keys to connect from the app
// This can only be run on the server directly via ssh, if you have proper access
// can't be accessed from the web

include_once( '../api/shared.php' );

$process =  new GarminProcess();

if( $process->ensure_commandline($argv,1) ){
    $activityIds = $argv;
    array_shift( $activityIds );


    $filter = array();
    foreach( $activityIds as $activityId ){
        array_push( $filter, sprintf( 'activity_id = %d', $activityId ) );
    }
    $where = implode( ' OR ', $filter );
    $query = sprintf( 'SELECT activity_id,file_id,`json` FROM activities WHERE %s', $where );

    print( $query.PHP_EOL);
    $rv = $process->sql->query_as_array( $query );
    if( $rv ){
        foreach( $rv as $rows ){
            print_r( $rv );
        }
    }
}else{

    header('HTTP/1.1 403 Forbidden');
    die;
}

?>
