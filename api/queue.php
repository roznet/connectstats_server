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
 */

error_reporting(E_ALL);

include_once( 'sql_helper.php');

class queue_sql extends sql_helper {
	function __construct() {
        include( 'config.php' );
		parent::__construct( $api_config, $db_key = 'db_queue' );
	}
}

/*
 *   Queue User:
 *        push_task( $type = 'exec'|'callable',
 *                  $cmd : string,
 *                  $not_before : timestamp )
 *         Table: queue_task_status (task_id, 
 *                             queue_id : null or queue_id processing the request,
 *                             ts :timestamp
 *                             finished_ts : timestamp or NULL )
 *                queue_task (task_id, 
 *                            task_type, 
 *                            task_command, 
 *                            ts : timestamp, 
 *                            queue_id : NULL or id
 *                            finished_ts timestamp or NULL, 
 *                            started_ts timestamp OR NULL
 *                            not_before_ts : timestamp )
 *                queue_processor (queue_id, 
 *                                 started : timestamp
 *                                 last_task_ts:  timestamp
 *                                 total_finished_tasks : int
 *                                 last_heartbeat_ts : timestamp
 *                                 )
 *
 *   Queue Processor:
 *          Find next task to run:
 *       SELECT * FROM queue_task WHERE ISNULL(queue_id) AND not_before_ts < CURRENT_TIMESTAMP() ORDER BY ts LIMIT 1;
 *       
 *          Lock the task
 *       UPDATE queue_task SET queue_id = $this->queue_id, started_ts = CURRENT_TIMESTAMP() WHERE task_id = $task_id_to_run
 *          
 *        
 */
class Queue {

    function __construct(){
        $this->sql = new queue_sql();
        $this->queue_count = 5;
        $this->queue_sleep = 2; // seconds to sleep in between queue task check
        $this->queue_timeout = 90; // seconds
        $this->verbose = false;

        $this->sql->verbose = $this->verbose;
        
        $this->completed = 0;
        $this->last_completed_ts = NULL;
    }
    
    function ensure_schema() {
        $schema_version = 2;
        $schema = array(
            "tasks" => array(
                'task_id' => 'BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY',
                'queue_id' => 'BIGINT(20) UNSIGNED DEFAULT NULL',
                'task_command' => 'VARCHAR(512)',
                'task_cwd' => 'VARCHAR(128)',
                'exec_status' => 'INT UNSIGNED',
                'ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                'created_ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
                'started_ts' => 'DATETIME',
                'finished_ts' => 'DATETIME',
                'not_before_ts' => 'DATETIME'
            ),
            "queues" => array(
                'queue_id' => 'BIGINT(20) AUTO_INCREMENT PRIMARY KEY',
                'queue_index' => 'BIGINT(20) UNSIGNED DEFAULT NULL',
                'queue_pid' => 'BIGINT(20) UNSIGNED DEFAULT NULL',
                'created_ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
                'heartbeat_ts' => 'DATETIME',
                'status' => 'VARCHAR(16)',
                'ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP'
            )
        );
        $create = false;
        if( ! $this->sql->table_exists('schema') ){
            $create = true;
            $this->sql->create_or_alter('schema', array( 'ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP', 'version' => 'BIGINT(20) UNSIGNED' ) );
        }else{
            $r = $this->sql->query_first_row('SELECT MAX(version) AS v FROM `schema`' );
            if( $r['v'] < $schema_version ){
                $create = true;
            }
        }

        if( $create ){
            foreach( $schema as $table => $defs ){
                $this->sql->create_or_alter( $table, $defs );
            }
            $this->sql->insert_or_update('schema', array( 'version' => $schema_version ) );
        }
    }
    
    /**
     *    This function will ensure that the script is called from the command line
     */
    function ensure_commandline($argv, $min_args = 0){
        if( ! isset( $argv[$min_args] ) || count( $argv ) < $min_args || isset( $_SERVER['HTTP_HOST'] ) || isset( $_SERVER['REQUEST_METHOD'] ) ){
            header('HTTP/1.1 403 Forbidden');
            die;
        }
        return true;
    }

    /* **********
     * Add Task functionality
     *
     */


    function add_task( $command, $cwd, $not_before_ts = NULL ){
        if( $not_before_ts ){
            $this->sql->insert_or_update( 'tasks', array( 'task_cwd' => $cwd, 'task_command' => $command, 'not_before_ts' => $not_before_ts ) );
        }else{
            $this->sql->insert_or_update( 'tasks', array( 'task_cwd' => $cwd, 'task_command' => $command ) );
        }
    }

    /* **********
     * Run Task functionality
     *
     */

    function run_one_task( $queue_index ){
        $done = false;
        $query = sprintf( 'SELECT * FROM tasks WHERE MOD(task_id,%d) = %d AND (finished_ts IS NULL ) AND ( not_before_ts IS NULL || CURRENT_TIMESTAMP() > not_before_ts ) ORDER BY ts LIMIT 1', $this->queue_count, $queue_index );
        $row = $this->sql->query_first_row( $query );

        if( $row && isset( $row['task_id'] ) ){
            $task_id = $row['task_id'];
            $query = sprintf( 'UPDATE tasks SET started_ts = FROM_UNIXTIME(%d), queue_id = %d WHERE task_id = %d', time(), $this->queue_id, $task_id);
            $this->sql->execute_query( $query );

            $log_dir = sprintf( '%s/log', $row['task_cwd' ] );
            if( is_dir( $log_dir ) ){
                $log = sprintf( '%s/task_%d.log', $log_dir, $task_id );
                $command = sprintf( '%s > %s 2>&1', $row['task_command'], $log );
            }else{
                $log = false;
                $command = sprintf( '%s > /dev/null 2> /dev/null', $row['task_command'] );
            }
            if( $this->verbose ){
                printf( 'EXEC: %s'.PHP_EOL, $command );
            }
            $output = NULL;
            $status = 0;
            if( is_dir( $row['task_cwd'] ) ){
                chdir( $row['task_cwd' ] );
            }
            exec( $command, $output, $status );
            if( $log && file_exists( $log ) && filesize( $log )== 0 ){
                unlink( $log );
            }
            $query = sprintf( 'UPDATE tasks SET finished_ts = FROM_UNIXTIME(%d), queue_id = %d, exec_status = %d WHERE task_id = %d', time(), $this->queue_id, $status, $task_id);
            $this->sql->execute_query( $query );
            $this->completed += 1;
            $this->last_completed_ts = time();
            $done = true;
        }
        return $done;
    }
    
    /* **********
     * Run Queue functionality
     *
     */

    function update_heartbeat( int $queue_index ){
        $this->sql->execute_query( sprintf( 'UPDATE queues SET heartbeat_ts = FROM_UNIXTIME( %d ) WHERE queue_id = %d', time(), $this->queue_id ) );
    }

    /**
     *    Return information about the queue that had the most recent heartbeat for queue_index
     */
    function heartbeat_last( int $queue_index ){
        $rv = $this->sql->query_first_row( sprintf( 'SELECT *, UNIX_TIMESTAMP(heartbeat_ts) AS last_heartbeat FROM queues WHERE queue_index = %d ORDER BY heartbeat_ts DESC LIMIT 1', $queue_index ) );
        return( $rv );
    }


    /**
     *   Checks if a queue for same index has recent heart beat but different queue_id
     *   If it's the case, die
     */
    function check_concurrent_queue( int $queue_index ){
        $heartbeat = $this->heartbeat_last($queue_index);
        
        if( isset( $heartbeat['queue_id'] ) &&
            intval( $heartbeat['queue_id'] ) != $this->queue_id &&
            isset( $heartbeat['heartbeat_ts'] ) &&
            abs(time() - intval($heartbeat['last_heartbeat'])) < $this->queue_timeout ){
            $this->sql->execute_query( sprintf( "UPDATE queues SET status = 'dead:concurrent', heartbeat_ts = NULL WHERE queue_id = %d", $this->queue_id ) );
            die( 'ERROR: concurrent queue exist, aborting' );
        }
    }
    
    function run( int $queue_index ){
        $this->ensure_schema();
        $this->verbose = true;
        if( $queue_index >= $this->queue_count || $queue_index < 0){
            die( 'ERROR: Invalid id number for queue, aborting' );
        }

        if( $this->sql->insert_or_update( 'queues', array( 'queue_pid' => getmypid(), 'queue_index'=>$queue_index, 'status' => 'running' ) ) ){
            $this->queue_id = $this->sql->insert_id();
            if( $this->verbose ){
                printf( 'Starting queue_id=%d index=%d'.PHP_EOL, $this->queue_id, $queue_index );
            }
            while( true ){
                $this->check_concurrent_queue( $queue_index );
                $this->update_heartbeat( $queue_index );
                if( ! $this->run_one_task( $queue_index ) ){
                    sleep( $this->queue_sleep );
                }
            }
        }
    }

    function start_queues(){
        for( $queue_index = 0 ; $queue_index < $this->queue_count; $queue_index++ ){
            $queue_need_start = true;
            $reason = NULL;
            
            $heartbeat = $this->heartbeat_last( $queue_index );
            if( $heartbeat == NULL ){
                $reason = 'no existing queue';
            }else {
                if( !isset( $heartbeat['last_heartbeat'] ) || !$heartbeat['last_heartbeat'] ){
                    $reason = 'no heartbeat' ;
                }else{
                    if( abs(time() - intval($heartbeat['last_heartbeat'])) < $this->queue_timeout ){
                        printf( 'Queue %d: heartbeat running. pid=%d last=%s'.PHP_EOL, $queue_index, $heartbeat['queue_pid'],  $heartbeat['heartbeat_ts'] );
                        $queue_need_start = false;
                    }else{
                        $reason = sprintf( 'heartbeat timed out %d (at %s)', abs(time() - intval($heartbeat['last_heartbeat'])),
                                           $heartbeat['heartbeat_ts']  );
                        $this->sql->execute_query( sprintf( "UPDATE queues SET status = 'dead:timeout', heartbeat_ts = NULL WHERE queue_id = %d", $heartbeat['queue_id'] ) );
                        if( isset( $heartbeat['queue_pid'] ) ){
                            $cmd = sprintf( 'kill -9 %d > /dev/null 2>&1', intval( $heartbeat['queue_pid'] ) );
                            printf( 'Queue %d: %s'.PHP_EOL, $queue_index, $cmd );
                            exec( $cmd );
                        }
                    }
                }
            }

            if( $queue_need_start ){
                printf( 'Queue %d: Starting. %s'.PHP_EOL, $queue_index, $reason);
                $this->exec_queue( $queue_index );
            }
        }
    }

    function kill_queues(){
        for( $queue_index = 0 ; $queue_index < $this->queue_count; $queue_index++ ){
            $queue_need_start = true;
            
            $heartbeat = $this->heartbeat_last( $queue_index );
            if( $heartbeat == NULL ){
                printf( 'Queue %d: no heartbeat'.PHP_EOL, $queue_index );
            }else {
                if( isset( $heartbeat['queue_pid'] ) ){
                    $cmd = sprintf( 'kill -9 %d > /dev/null 2>&1', intval( $heartbeat['queue_pid'] ) );
                    printf( 'Queue %d: %s'.PHP_EOL, $queue_index, $cmd );
                    exec( $cmd );
                    $this->sql->execute_query( sprintf( "UPDATE queues SET status = 'dead:killed', heartbeat_ts = NULL WHERE queue_id = %d", $heartbeat['queue_id'] ) );
                }
            }
        }
    }

    function exec_queue( $queue_index ){
        if( is_writable( 'log' ) ){
            $log = sprintf( 'log/queue_%d_%s', $queue_index, strftime( '%Y%m%d_%H%M%S',time() ) );
            $command = sprintf( 'php runqueue.php %d  > %s.log 2>&1 &', $queue_index, $log );
        }else{
            $command = sprintf( 'php runqueue.php %d  > /dev/null 2> /dev/null &', $queue_index );
        }
        if( $this->verbose ){
            printf( 'Exec %s'.PHP_EOL, $command );
        }
        exec( $command );
    }
}

?>
