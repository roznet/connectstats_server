<?php
/*
 *  MIT Licence
 *
 *  Copyright (c) 2019 Brice Rosenzweig.
 *
 *  Permission is hereby granted, free of charge, to any person obtaining a copy
 *  of this software and associated documentation files (the "Software"), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is
 *  furnished to do so, subject to the following conditions:
 *  
 *  The above copyright notice and this permission notice shall be included in all
 *  copies or substantial portions of the Software.
 *  
 *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *  FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 *  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 *  SOFTWARE.
 *  
 *  USAGE:
 *      php backup_tasks.php [-t=token_id] n 
 *  Will try to run the updates from the cache for n activities and files
 *      Optionally will only run for the activities of token_id
 *
 *
 */

include_once( '../api/shared.php' );

$process = new GarminProcess();
$process->set_verbose( false );

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

function start_id_for_user( $sql, $userId ){
    return $sql->query_first_row( sprintf( "SELECT * FROM backup_status_users WHERE userId = '%s'", $userId ) );
}


$queue = new Queue( $process->api_config );
$queue->ensure_schema();
$queue->set_verbose( false );

$options = getopt( 't:', [], $n );
$remain = array_slice( $argv, $n );

$execute = true;

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

    $latest = start_id_for_user( $process->sql, $userId ?? ALL_USERS );
    if(! $latest ){
        $latest = [ 'userId' => $userId ?? ALL_USERS, 'activities_cache_id' => 0, 'fitfiles_cache_id' => 0 ];
    }

    $starting = $latest;
    
    foreach( ['activities','fitfiles'] as $table ){

        $lookedat = 0;
        $added = 0;
        
        $command_prefix = sprintf( 'run%s', $table );
        $window_start = 0;
        $window_size = 100;

        while( $added < $ntasks ){
            $query = sprintf( "SELECT cache_id,json FROM cache_%s WHERE cache_id > %d AND ( processed_ts IS NULL OR processed_ts = DATE('1970-01-01 00:00:00') )  ORDER BY cache_id LIMIT %d OFFSET %d", $table, $starting[sprintf( '%s_cache_id', $table )], $window_size,$window_start );
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
                if( $add ) {
                    $added += 1;
                    $command = sprintf( 'php run%s.php %d', $table, $task['cache_id'] );
                    printf( 'ADD: %s in %s'.PHP_EOL, $command, $path );
                    $latest[ sprintf( '%s_cache_id', $table ) ] = $task['cache_id'];
                    if( $execute ){
                        $queue->add_task( $command, $path );
                    }
                }
                $lookedat += 1;
                if( $added >= $ntasks ){
                    break;
                }
            }
            $window_start += $window_size;
        }
        printf( '%s: Added %d out of %d'.PHP_EOL, $table, $added, $lookedat );
    }
    $process->sql->insert_or_update( 'backup_status_users', $latest, array( 'userId' ) );
    if( $execute ){
        exec( '(cd ../api/queue;php queuectl.php start)');
    }
}
?>
