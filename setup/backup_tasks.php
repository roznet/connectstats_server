<?php

include_once( '../api/shared.php' );

$process = new GarminProcess();
$process->set_verbose( true );

$process->ensure_commandline($argv);
$process->ensure_schema();

const ALL_USERS = 'ALL';

/*
 * To create the database backup_info
 *   CREATE TABLE backup_status_users (backup_user_id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY, userId VARCHAR(128), activities_cache_id BIGINT(20) UNSIGNED, fitfiles_cache_id BIGINT(20) UNSIGNED, ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP)
 */
if( ! $process->sql->table_exists( 'backup_status_users' ) ){
    printf( 'ERROR: needs to run against a database setup for backup'.PHP_EOL );
    die();
}

function start_id_for_user( $qsl, $userId ){
    return $sql->query_first_row( sprintf( "SELECT * FROM backup_status_users WHERE userId = '%s'", $userId ) );
}

function update_start_id_for_user( $sql, $userId, $activity_id, $fitfiles_id ){

}

$queue = new Queue( $process->api_config );
$queue->ensure_schema();
$queue->set_verbose( true );

$options = getopt( 't:', [], $n );
$remain = array_slice( $argv, $n );


if( isset( $remain[0] ) ){
    $ntasks = intval( $remain[0] );
    
    if( isset( $options['t'] ) ){
        $row = $process->sql->query_first_row( sprintf( 'SELECT token_id,userId FROM tokens WHERE token_id = %d', $options['t'] ) );
        if( isset( $row['userId'] ) ){
            $userId = $row['userId'];
            printf( 'Filtering userId=%s'.PHP_EOL, $userId );
        }
    }
    
    printf( "Running %d tasks".PHP_EOL, $ntasks );
    
    $cwd = getcwd();
    $path = join( '/', array_merge(explode( '/', pathinfo( $cwd )['dirname'] ), ['api','garmin']) );

    
    foreach( ['activities','fitfiles'] as $table ){

        $lookedat = 0;
        $added = 0;
        
        $command_prefix = sprintf( 'run%s', $table );
        $window_start = 0;
        $window_size = 100;

        while( $added < $ntasks ){
            $query = sprintf( "SELECT cache_id,json FROM cache_%s WHERE processed_ts IS NULL ORDER BY cache_id LIMIT %d OFFSET %d", $table,  $window_size,$window_start );
            $tasks = $process->sql->query_as_array( $query );
            if( !$tasks ){
                break;
            }
            foreach( $tasks as $task ){

                $add = false;
                if( isset( $userId ) ){
                    $json = json_decode( $task['json'], true );
                    $search =NULL;
                    if( isset( $json['activityFiles'] ) ){
                        $search = $json['activityFiles'];
                    }else if( isset( $json['activities'] ) ){
                        $search = $json['activities'];
                    }

                    if( isset($search) ){
                        foreach( $search as $one ){
                            if( isset( $one['userId'] ) && $one['userId'] == $userId ){
                                $add = true;
                            }
                        }
                    }
                }else{
                    $add = true;
                }
                if( $add ){
                    $added += 1;
                    $command = sprintf( 'php run%s.php %d', $table, $task['cache_id'] );
                    printf( 'ADD: %s in %s'.PHP_EOL, $command, $path );
                    #$queue->add_task( $command, $path );
                }
                $lookedat += 1;
            }
            $window_start += $window_size;
        }
        printf( '%s: Added %d out of %d'.PHP_EOL, $table, $added, $lookedat );
    }
    #$queue->start_queues();
}
?>
