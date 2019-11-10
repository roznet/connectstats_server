<?php

include_once( '../api/shared.php' );

$process = new GarminProcess();
$process->set_verbose( true );

$process->ensure_commandline($argv);
$process->ensure_schema();

$queue = new Queue( $process->api_config );
$queue->ensure_schema();
$queue->set_verbose( true );

function latest_command( $queue, $command_prefix ){
    $latest_row = $queue->sql->query_first_row( sprintf( "SELECT * FROM tasks WHERE task_command LIKE 'php %s.php%%' ORDER BY task_id DESC LIMIT 1", $command_prefix ) );
    $latest_command = $latest_row['task_command' ];

    return $latest_command;

}

if( isset( $argv[1] ) ){
    $ntasks = intval( $argv[1] );
    
    printf( "Running %d tasks".PHP_EOL, $ntasks );
    
    $cwd = getcwd();
    $path = join( '/', array_merge(explode( '/', pathinfo( $cwd )['dirname'] ), ['api','garmin']) );
    
    foreach( ['activities','fitfiles'] as $table ){
        $command_prefix = sprintf( 'run%s', $table );
        $first_task = 0;
        $latest_command = latest_command( $queue, $command_prefix );
        if( $latest_command ){
            $first_task = intval( end( explode( ' ', $latest_command ) ) );
            printf( 'Latest %s (%d): %s'.PHP_EOL, $command_prefix, $first_task,$latest_command );
        }else{
            printf( 'First %s'.PHP_EOL, $command_prefix );
        }
        $query = sprintf( "SELECT cache_id FROM cache_%s WHERE cache_id > %d LIMIT %d", $table, $first_task, $ntasks );
        $tasks = $process->sql->query_as_array( $query );
        foreach( $tasks as $task ){
            $command = sprintf( 'php run%s.php %d', $table, $task['cache_id'] );
            printf( 'ADD: %s in %s'.PHP_EOL, $command, $path );
            #$queue->add_task( $command, $path );
        }
    }
    $queue->start_queues();
}

?>
