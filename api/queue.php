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
	function __construct( $input = NULL ) {
        if( $input ){
            $api_config = $input;
        }else{
            include( 'config.php' );
        }
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

    function __construct($input = NULL){
        if( $input ){
            $api_config = $input;
        }else{
            $configs = [  __DIR__.'/config.php', is_readable( dirname( __DIR__ ).'/config.php' ) ];
            $found = NULL;
            foreach( $configs as $config ){
                if( is_readable( $config ) ){
                    $found = $config;
                    break;
                }
            }
            if( $found ){
                include( $found );
            }else{
                die( sprintf( 'Failed to open config file from %s'.PHP_EOL, __DIR__ ) );
            }
        }

        $this->api_config = $api_config;

        $this->sql = new queue_sql( $api_config );
        $this->queue_count = 5;
        $this->queue_sleep = 2; // seconds to sleep in between queue task check
        $this->queue_timeout = 90; // seconds
        $this->verbose = false;

        if( isset( $api_config['queue_timeout'] ) ){
            $this->queue_timeout = intval( $api_config['queue_timeout'] );
        }
        if( isset( $api_config['queue_sleep'] ) ){
            $this->queue_sleep = intval( $api_config['queue_sleep'] );
        }
        if( isset( $api_config['queue_count'] ) ){
            $this->queue_count = intval( $api_config['queue_count'] );
        }
        
        $this->sql->verbose = $this->verbose;
        
        $this->completed = 0;
        $this->last_completed_ts = NULL;
    }

    function set_verbose( $flag ){
        $this->verbose = $flag;
        $this->sql->verbose = $flag;
    }
    
    function log(){
        $args = func_get_args();
        $tag = array_shift( $args );
        $fmt = array_shift( $args );

        $msg = vsprintf( $fmt, $args );
        
        printf( "%s:%s: %s".PHP_EOL, date("Y-m-d h:i:s"), $tag, $msg );
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

    function task_log_dir( $task_cwd, $add_date = true){
        if( isset( $this->api_config['log'] ) ){
            $log_base = $this->api_config['log'];
        }else{
            $log_base = 'log';
        }
        if( substr( $log_base, 0, 1 ) != '/' ){
            // if relative path, make it off the task cwd
            $log_base = sprintf( '%s/%s', $task_cwd, $log_base );
        }
        if( $add_date ){
            $log_dir = sprintf( '%s/%s', $log_base, date( 'Ymd' ) );
        }else{
            $log_dir = $log_base;
        }
        
        if( ! is_dir( $log_dir ) ){
            // if not exist try to create with group write permission
            $this->log( 'INFO', 'creating log_dir %s', $log_dir );
            mkdir( $log_dir, 0775, true );
        }
        return $log_dir;
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

            $log_dir = $this->task_log_dir( $row['task_cwd' ] );
            if( is_dir( $log_dir ) ){
                $log = sprintf( '%s/task_%d.log', $log_dir, $task_id );
                $command = sprintf( '%s > %s 2>&1', $row['task_command'], $log );
            }else{
                $log = false;
                $command = sprintf( '%s > /dev/null 2> /dev/null', $row['task_command'] );
            }
            if( $this->verbose ){
                $this->log( 'EXEC', $command );
            }
            $output = NULL;
            $status = 0;
            if( is_dir( $row['task_cwd'] ) ){
                chdir( $row['task_cwd' ] );
            }
            $time = time();
            exec( $command, $output, $status );
            $elapsed = time() - $time;
            if( $this->verbose ){
                $this->log( 'DONE','%s (%d secs) ', $command, $elapsed );
            }
            if( $elapsed > 30 ){
                $this->log( 'TIME', 'reconnecting mysql' );
                // Restart new connection to avoid time out
                $this->sql = new queue_sql();
            }
            if( $log && file_exists( $log ) && filesize( $log )== 0 ){
                unlink( $log );
            }
            $query = sprintf( 'UPDATE tasks SET finished_ts = FROM_UNIXTIME(%d), queue_id = %d, exec_status = %d WHERE task_id = %d', time(), $this->queue_id, $status, $task_id);
            if( !$this->sql->execute_query( $query ) ){
                $this->log( "ERROR", "%s %s", $query, $this->sql->lasterror );
            }
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
        
        if( isset( $rv['queue_id'] ) && isset( $this->processes[ $rv['queue_id'] ] ) ){
            $rv[ 'running_pid' ] = $this->processes[$rv['queue_id']];
        }

        return( $rv );
    }

        /**
     *    Return information about the queue that had the most recent heartbeat for queue_index
     */
    function heartbeat_for_queue_id( int $queue_id ){
        $rv = $this->sql->query_first_row( sprintf( 'SELECT *, UNIX_TIMESTAMP(heartbeat_ts) AS last_heartbeat FROM queues WHERE queue_id = %d', $queue_id ) );
        
        if( isset( $rv['queue_id'] ) && isset( $this->processes[ $rv['queue_id'] ] ) ){
            $rv[ 'running_pid' ] = $this->processes[$rv['queue_id']];
        }

        return( $rv );
    }

    /**
     *   Return true if it appears the heartbeat row belongs to a dead queue
     */
    function heartbeat_has_died( array $heartbeat ){
        return( !isset( $heartbeat['queue_pid'] ) || ! $heartbeat['queue_pid'] );
    }
    
    function heartbeat_is_running( array $heartbeat ){
        return( isset( $heartbeat['queue_pid'] ) || intval( $heartbeat['queue_pid'] ) > 0 );
    }

    function heartbeat_process_is_dead( array $heartbeat ){
        return( isset( $this->processes ) && ! isset( $this->processes[ $heartbeat['queue_id'] ] ) );
    }
    
    function heartbeat_process_is_stopping( array $heartbeat ){
        return( isset( $heartbeat['status'] ) && ( $heartbeat['status'] == 'stop' || $heartbeat['status'] == 'dead:stopped' ) );
    }
    
    function heartbeat_process_has_stopped( array $heartbeat ){
        return( isset( $heartbeat['status'] ) && ( $heartbeat['status'] == 'dead:stopped' ) );
    }

    function heartbeat_has_timedout( array $heartbeat ){
        return( abs(time() - intval($heartbeat['last_heartbeat'])) > $this->queue_timeout );
    }

    function heartbeat_belong_to_this_queue( array $heartbeat ){
        return( isset( $heartbeat['queue_id'] ) && isset( $this->queue_id ) && intval( $heartbeat['queue_id'] ) == $this->queue_id );
    }
    
    /**
     *   Checks if a queue for same index has recent heart beat but different queue_id
     *   If it's the case, die
     */
    function check_concurrent_queue( int $queue_index ){
        $heartbeat = $this->heartbeat_last($queue_index);

        if( !$this->heartbeat_belong_to_this_queue( $heartbeat ) &&
            !$this->heartbeat_has_timedout( $heartbeat ) &&
            $this->heartbeat_is_running( $heartbeat ) ){
            return $heartbeat;
        }
        return NULL;
    }

    function create_queue( $queue_index ){
        $rv = NULL;
        if( $this->sql->insert_or_update( 'queues', array( 'queue_index'=>$queue_index, 'status' => 'pending' ) ) ){
            $rv = $this->sql->insert_id();
        }
        return $rv;
    }
    
    function run( int $queue_id ){
        $this->ensure_schema();
        $this->verbose = true;
        
        $this->find_running_queues();

        $queue = $this->sql->query_first_row( sprintf( 'SELECT * FROM queues WHERE queue_id = %d', $queue_id ) );
        if( !$queue || ! isset( $queue['queue_index'] ) ){
            die( sprintf( 'ERROR: could not start queue with queue_id = %d', $queue_id ) );
        }
        if( isset( $queue['queue_pid'] ) && $queue['queue_pid'] ){
            if( isset( $this->processes ) && isset( $this->processes[ $queue_id ] ) ){

                die( sprintf( 'ERROR: queue_id = %d already started with pid = %d (in db queue pid = %d)'.PHP_EOL, $queue_id, $this->processes[$queue_id], $queue['queue_pid'] ) );
            }
        }

        $queue_index = $queue['queue_index'];
        $this->queue_id = $queue_id;

        
        if( $queue_index >= $this->queue_count || $queue_index < 0){
            die( sprintf( 'ERROR: Invalid id number for queue, aborting queue_id=%d', $this->queue_id ) );
        }
        $concurrent = $this->check_concurrent_queue( $queue_index );
        if( $concurrent ){
            die( sprintf( "ERROR: aborting start of queue_id %d, concurent queue %d exists for index %d".PHP_EOL, $queue_id, $concurrent['queue_id'], $queue_index ) );
        }
        
        if( $this->sql->insert_or_update( 'queues', array( 'queue_pid' => getmypid(), 'status' => 'running', 'queue_id' => $queue_id ), array( 'queue_id' ) ) ){
            if( $this->verbose ){
                $this->log( 'START', 'Starting queue_id=%d index=%d pid=%d'.PHP_EOL, $this->queue_id, $queue_index, getmypid() );
            }
            $execution_mode = false;
            
            while( true ){

                $res_status = $this->sql->query_first_row( sprintf( 'SELECT status FROM queues WHERE queue_id = %d', $this->queue_id ) );
                if( isset( $res_status['status'] ) && $res_status['status'] == 'stop' ){
                    $this->log( 'STOP', "received message to stop, exiting gracefully" );
                    $this->sql->execute_query( sprintf( "UPDATE queues SET heartbeat_ts = FROM_UNIXTIME( %d ), status = 'dead:stopped', queue_pid = NULL WHERE queue_id = %d", time(), $this->queue_id ) );
                    exit( 0 );
                }
                        
                
                if( $this->check_concurrent_queue( $queue_index ) ){
                    $this->sql->execute_query( sprintf( "UPDATE queues SET heartbeat_ts = FROM_UNIXTIME( %d ), status = 'dead:concurrent', queue_pid = NULL WHERE queue_id = %d", time(), $this->queue_id ) );
                    die( 'ERROR: concurrent queue exist, aborting' );
                }
                $this->update_heartbeat( $queue_index );
                if( ! $this->run_one_task( $queue_index ) ){
                    if( $execution_mode ){
                        $execution_mode = false;
                        if( $this->verbose ){
                            $this->log( 'WAIT','no more tasks, starting sleep cycle' );
                        }
                    }
                    sleep( $this->queue_sleep );
                }else{
                    $execution_mode = true;
                }
            }
        }
    }

    function clean_tasks_and_queues(){
        $res_first = $this->sql->query_first_row( "SELECT task_id FROM `tasks` WHERE ts < NOW() - INTERVAL 45 DAY ORDER BY task_id DESC LIMIT 1" );
        if( isset( $res_first['task_id'] ) ){
            $first_task_id = intval($res_first['task_id']);
            if ($first_task_id > 0) {
                $res_total_tasks = $this->sql->query_first_row("SELECT COUNT(*) FROM `tasks`");
                $res_keep_tasks = $this->sql->query_first_row(sprintf("SELECT COUNT(*) FROM `tasks` WHERE task_id >= %d", $first_task_id));
                $total_tasks = $res_total_tasks['COUNT(*)'];
                $keep_tasks = $res_keep_tasks['COUNT(*)'];
                $this->sql->execute_query(sprintf('DELETE FROM `tasks` WHERE task_id < %d', $first_task_id));
                $this->log('INFO', 'Deleting tasks from task_id = %d, keeping %d out of %d tasks', $first_task_id, $keep_tasks, $total_tasks);
            } else {
                $this->log('INFO',  'Did not find any tasks to delete');
            }
        }
        
        $res_first = $this->sql->query_first_row( "SELECT queue_id FROM `queues` WHERE queue_pid IS NULL AND ts < NOW() - INTERVAL 45 DAY ORDER BY queue_id DESC LIMIT 1" );
        if( isset( $res_first['queue_id'] ) ){
            $first_queue_id = intval($res_first['queue_id']);
            if ($first_queue_id > 0) {
                $res_total_queues = $this->sql->query_first_row("SELECT COUNT(*) FROM `queues`");
                $res_keep_queues = $this->sql->query_first_row(sprintf("SELECT COUNT(*) FROM `queues` WHERE queue_id >= %d", $first_queue_id));
                $total_queues = $res_total_queues['COUNT(*)'];
                $keep_queues = $res_keep_queues['COUNT(*)'];
                $this->sql->execute_query(sprintf('DELETE FROM `queues` WHERE queue_id < %d', $first_queue_id));
                $this->log('INFO', 'Deleting queues from queue_id = %d, keeping %d out of %d queues', $first_queue_id, $keep_queues, $total_queues);
            } else {
                $this->log('INFO',  'Did not find any queue to delete');
            }
        }
    }

    function wait_for_queue_to_stop($heartbeat){
        $queue_id = $heartbeat['queue_id'];
        $this->find_running_queues();
        $heartbeat = $this->heartbeat_for_queue_id($queue_id);

        $safeguard = intval( $this->queue_timeout / $this-> queue_sleep );
        
        while( $safeguard > 0 && ! $this->heartbeat_process_has_stopped( $heartbeat ) && ! $this->heartbeat_has_timedout( $heartbeat ) ){
            $this->log( 'INFO', 'Wait for Queue %d to stop', $queue_id );
            print_r( $heartbeat );
            sleep( $this->queue_sleep );
            $this->find_running_queues();
            $heartbeat = $this->heartbeat_for_queue_id($queue_id);
            $safeguard -= 1;
        }
        if( ! $this->heartbeat_process_has_stopped( $heartbeat ) && ! $this->heartbeat_has_timedout( $heartbeat ) ){
            $this->log( 'ERROR', 'Queue %d did not stopped', $queue_id );
            $this->kill_queue( $heartbeat['queue_id'], $heartbeat['queue_pid'] );
        }else{
            $this->log( 'INFO', 'Queue %d stopped', $queue_id );
        }
    }
    
    function start_queues(){
        $this->find_running_queues();
        
        for( $queue_index = 0 ; $queue_index < $this->queue_count; $queue_index++ ){
            $queue_need_start = true;
            $queue_need_restart = false;
            $reason = NULL;
            
            $heartbeat = $this->heartbeat_last( $queue_index );
            $description = $this->heartbeat_queue_description( $queue_index, $heartbeat );
            if( $heartbeat ){
                if( $this->heartbeat_has_timedout( $heartbeat ) ){
                    $this->sql->execute_query( sprintf( "UPDATE queues SET status = 'dead:timeout', queue_pid = NULL WHERE queue_id = %d", $heartbeat['queue_id'] ) );
                    if( isset( $heartbeat['queue_pid'] ) ){
                        $cmd = sprintf( 'kill -9 %d > /dev/null 2>&1', intval( $heartbeat['queue_pid'] ) );
                        $this->log( 'KILL', 'Queue %d: %s', $queue_index, $cmd );
                        exec( $cmd );
                    }
                    $queue_need_restart = true;
                }else if( $this->heartbeat_process_is_stopping( $heartbeat ) ){
                    $this->wait_for_queue_to_stop( $heartbeat );
                }else if( $this->heartbeat_is_running( $heartbeat ) ){
                    if( $this->heartbeat_process_is_dead( $heartbeat ) ){
                        $queue_need_restart = true;
                    }else{
                        $queue_need_start = false;
                    }
                }
            }

            if( $queue_need_start ){
                if( $queue_need_restart ){
                
                    $queue_id = $heartbeat['queue_id'];
                    $this->exec_queue( $queue_index, $queue_id );

                    $this->find_running_queues();

                    if( isset( $this->processes ) ){
                        $this->find_running_queues();

                        if( isset( $this->processes[ $queue_id ] ) ){
                            $new_queue_pid = $this->processes[ $queue_id ];
                            
                            $this->sql->execute_query( sprintf( "UPDATE queues SET queue_pid = %d, status = 'running' WHERE queue_id = %d", $new_queue_pid, $heartbeat['queue_id'] ) );
                            $heartbeat = $this->heartbeat_last( $queue_index );
                            $description = $this->heartbeat_queue_description( $queue_index, $heartbeat );
                        }
                        
                        $this->log( 'RESTART',$description);
                    }
                }else{
                    $this->log( 'START', $description);
                
                    $queue_id = $this->create_queue( $queue_index );
                    $this->exec_queue( $queue_index, $queue_id );

                }
            }else{
                $this->log( 'RUNNING', $description);
            }
        }
    }

    function heartbeat_queue_description( $queue_index, $heartbeat ){
        $rv = NULL;
        if( $heartbeat == NULL ){
            $rv = sprintf( 'Queue %d: no existing queue', $queue_index );
        }else {
            if( $this->heartbeat_has_died( $heartbeat ) ){
                $rv = sprintf( 'Queue %d: dead. last heartbeat=%s status=%s', $queue_index, $heartbeat['heartbeat_ts'], $heartbeat['status'] );
            }else{
                if( $this->heartbeat_has_timedout( $heartbeat ) ){
                    $rv = sprintf( 'Queue %d [%d]: heartbeat timed out %d (at %s)',
                                   $queue_index,
                                   $heartbeat['queue_id'],
                                   abs(time() - intval($heartbeat['last_heartbeat'])),
                                   $heartbeat['heartbeat_ts']  );
                }else if( $this->heartbeat_is_running( $heartbeat ) ){
                    if( isset( $heartbeat['running_pid'] ) ){
                        $pidstatus = 'live';
                        if( $heartbeat['running_pid']!= $heartbeat['queue_pid'] ){
                            $pidstatus = sprintf( 'live %d', $heartbeat['running_pid'] );
                        }
                    }else{
                        $pidstatus = 'dead';
                    }
                    $rv = sprintf( 'Queue %d [%d]: running. pid=%d[%s] last=%d (at %s)', $queue_index, $heartbeat['queue_id'], $heartbeat['queue_pid'], $pidstatus,
                                   abs(time() - intval($heartbeat['last_heartbeat'])), $heartbeat['heartbeat_ts'] );
                }else{
                    print_r( $heartbeat );
                }
            }
        }
        return $rv;
    }

    function queue_task_status( $queue_id, $queue_index ){
        $query = sprintf( 'SELECT queue_id, COUNT(*) as total,MAX(finished_ts) as last FROM tasks WHERE queue_id=%d AND ( finished_ts IS NOT NULL ) GROUP BY queue_id', $queue_id );
        $row = $this->sql->query_first_row( $query );

        $query = sprintf( 'SELECT MOD(task_id,%d) queue_index, COUNT(*) as total,MIN(created_ts) as first FROM tasks WHERE MOD(task_id,%d)=%d AND ( finished_ts IS NULL ) GROUP BY queue_index',
                          $this->queue_count, $this->queue_count,$queue_index );
        $row_outstanding = $this->sql->query_first_row( $query );
        if( $row_outstanding ){
            return sprintf( 'done=%d todo=%d last=%s', $row['total'], $row_outstanding['total'], $row['last'] );
        }else if( isset( $row['last'] ) ){
            return sprintf( 'done=%d last=%s', $row['total'], $row['last'] );
        }else if( isset( $row['total'] ) ){
            return sprintf( 'done=%d', $row['total'] );
        }else{
            return sprintf( 'nothing done' );
        }
    }


    function find_running_queues( $display = false){

        $mypid = getmypid();
        
        $processes = false;
        exec( '/bin/ps ax -o pid,command', $output, $rv );

        if( $rv == 0 ){
            $processes = array();
            foreach( $output as $line ){
                preg_match( '/([0-9]+) +[-\/a-z0-9]*php runqueue.php ([0-9]+)/', $line, $matches );
                if( $matches ){
                    $pid = intval($matches[1]);
                    $queue_id = intval($matches[2]);
                    if( $pid != $mypid ){
                        $processes[ $queue_id ] = $pid;
                        if( $display ) {
                            printf( 'Queue %d: pid=%d'.PHP_EOL, $queue_id, $pid );
                        }
                    }
                }
            }
            $this->processes = $processes;
        }else{
            if( isset( $this->processes ) ){
                unset( $this->processes );
            }
            if( $display ){
                $this->log( 'ERROR', "Couldn't run /bin/ps ret = %s", $rv );
            }
        }
        return( $processes );
    }

    function stop_queues(){
        $this->find_running_queues();
        for( $queue_index = 0 ; $queue_index < $this->queue_count; $queue_index++ ){
            $queue_need_start = true;
            $reason = NULL;
            
            $heartbeat = $this->heartbeat_last( $queue_index );
            if( isset( $heartbeat['status'] ) && $heartbeat['status'] == 'running' ){
                $desc = $this->heartbeat_queue_description( $queue_index, $heartbeat );
                $this->sql->execute_query( sprintf( "UPDATE queues SET status = 'stop' WHERE queue_id = %d", $heartbeat['queue_id'] ) );
                printf( 'Stopping %s'.PHP_EOL, $desc );
            }
        }
    }
    
    function list_queues( ){

        $this->find_running_queues();
        
        for( $queue_index = 0 ; $queue_index < $this->queue_count; $queue_index++ ){
            $queue_need_start = true;
            $reason = NULL;
            
            $heartbeat = $this->heartbeat_last( $queue_index );
            $queue_id = $heartbeat['queue_id'];
            
            $desc = $this->heartbeat_queue_description( $queue_index, $heartbeat );
            if( isset( $heartbeat['queue_id'] ) ){
                $done = $this->queue_task_status( $heartbeat['queue_id'], $queue_index );
                printf( '%s Tasks: %s'.PHP_EOL, $desc, $done );
            }else{
                print( $desc.PHP_EOL );
            }
        }
    }
    
    function kill_queue($queue_id, $queue_pid ){
        $cmd = sprintf('kill -9 %d > /dev/null 2>&1', intval($queue_pid));
        $this->log('KILL','Queue pid %d: %s' . PHP_EOL, $queue_id, $cmd);
        exec($cmd);
        $this->sql->execute_query(sprintf("UPDATE queues SET status = 'dead:killed', queue_pid = NULL WHERE queue_id = %d", $queue_id));
    }
    
    function kill_queues(){
        for( $queue_index = 0 ; $queue_index < $this->queue_count; $queue_index++ ){
            $queue_need_start = true;
            
            $heartbeat = $this->heartbeat_last( $queue_index );
            if( $heartbeat == NULL ){
                printf( 'Queue %d: no heartbeat'.PHP_EOL, $queue_index );
            }else {
                if( $this->heartbeat_is_running( $heartbeat ) ){
                    $this->kill_queue( $heartbeat['queue_id'], $heartbeat['queue_pid'] );
                }
            }
        }

        $this->find_running_queues();
        while( count( $this->processes ) > 0 ){
            foreach( $this->processes as $queue_id => $queue_pid ){
                $this->kill_queue( $queue_id, $queue_pid );
            }
            $this->find_running_queues();
        }
    }

    function exec_queue( $queue_index, $queue_id ){
        if( isset( $this->api_config['log'] ) ){
            $log_dir = $this->api_config['log'];
        }else{
            $log_dir = 'log';
        }
        if( is_writable( $log_dir ) ){
            $log = sprintf( '%s/queue_%d_for_%d.log', $log_dir, $queue_id, $queue_index );
            $command = sprintf( 'nohup php runqueue.php %d  > %s 2>&1 &', $queue_id, $log );
        }else{
            if( $this->verbose ){
                $this->log( 'WARNING', 'log dir %s is not writable, sending to /dev/null', $log_dir );
            }
            $command = sprintf( 'php runqueue.php %d  > /dev/null 2> /dev/null &', $queue_id );
        }
        if( $this->verbose ){
            $this->log( 'EXEC', $command );
        }
        exec( $command );
    }
}

?>
