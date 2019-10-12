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

include_once( '../sql_helper.php');

class queue_sql extends sql_helper {
	function __construct() {
        include( 'config.php' );
        print_r( $api_config );
		parent::__construct( $api_config );
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
        $this->queue_count = 2;
        $this->queue_timeout = 10; // seconds
        $this->verbose = true;

        $this->sql->verbose = $this->verbose;
        
        $this->ensure_schema();
    }
    
    function ensure_schema() {
        $schema_version = 1;
        $schema = array(
            "tasks" => array(
                'task_id' => 'BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY',
                'queue_id' => 'BIGINT(20) UNSIGNED DEFAULT NULL',
                'task_command' => 'VARCHAR(128)',
                'ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                'finished_ts' => 'DATETIME',
                'not_before_ts' => 'DATETIME'
            ),
            "queues" => array(
                'queue_id' => 'BIGINT(20) UNSIGNED',
                'task_id' => 'BIGINT(20) UNSIGNED DEFAULT NULL',
                'last_task_ts' => 'BIGINT(20) UNSIGNED DEFAULT NULL'
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

    

    /* **********
     * Run Task functionality
     *
     */


    
    /* **********
     * Run Queue functionality
     *
     */
    
    function heartbeat_file( int $queue_id ){
        return sprintf( 'heartbeat/queue_%d', $queue_id );
    }

    function update_heartbeat( int $queue_id ){
        $heartbeat_file = $this->heartbeat_file( $queue_id );
        if( file_exists( $heartbeat_file ) && ! is_writable( $heartbeat_file ) ){
            die( sprintf( 'Unable to write heartbeat file %s', $heartbeat_file ) );
        }
        if( !file_put_contents( $heartbeat_file,  $this->heartbeat_content( $queue_id ) ) ){
            # try to unlink the file
            unlink( $this->heartbeat_file );
            die( sprintf( 'Unable to remove heartbeat file %s', $heartbeat_file ) );
        }
    }

    function heartbeat_content( $queue_id ){
        return json_encode( array( 'pid'=>getmypid(), 'time'=>time() ) );
    }

    function heartbeat_last( int $queue_id ){
        $rv = NULL;
        $heartbeat_file = $this->heartbeat_file( $queue_id );
        
        if( is_readable( $heartbeat_file ) ){
            $rv = json_decode( file_get_contents( $heartbeat_file ), true );
        }
        return $rv;
    }

    function check_concurrent_queue( int $queue_id ){
        $previous = $this->heartbeat_last($queue_id);
        if( isset( $previous['pid'] ) && intval( $previous['pid'] ) != getmypid() &&
            isset( $previous['time'] ) && abs(time() - intval($previous['time'])) < $this->queue_timeout ){
            die( 'concurrent queue exist' );
        }
    }
    
    function run( int $queue_id ){
        print ( 'starting'.PHP_EOL );
        if( $queue_id >= $this->queue_count || $queue_id < 0){
            die( 'Invalid id number for queue' );
        }

        while( true ){
            $this->check_concurrent_queue( $queue_id );
            $this->update_heartbeat( $queue_id );
            printf( 'heartbeat %s'.PHP_EOL, date( DATE_RFC2822 ) );
            sleep( 2 );
        }
    }

    function start_queues(){
        for( $queue_id = 0 ; $queue_id < $this->queue_count; $queue_id++ ){
            $queue_need_start = true;

            $heartbeat = $this->heartbeat_last( $queue_id );
            if( $heartbeat == NULL ){
                printf( 'Queue %d: no heartbeat'.PHP_EOL, $queue_id );
            }else {
                if( !isset( $heartbeat['time'] ) ){
                    printf( 'Queue %d: no time in heartbeat'.PHP_EOL, $queue_id );
                }else{
                    if( abs(time() - intval($heartbeat['time'])) < $this->queue_timeout ){
                        printf( 'Queue %d: heartbeat running. pid=%d last=%s'.PHP_EOL, $queue_id, $heartbeat['pid'], date( DATE_RFC2822, $heartbeat['time'] ) );
                        $queue_need_start = false;
                    }else{
                        printf( 'Queue %d: old heartbeat %d (at %s)'.PHP_EOL, $queue_id, abs(time() - intval($heartbeat['time'])),
                                date( DATE_RFC2822, $heartbeat['time'] ) );
                    }
                }
            }

            if( $queue_need_start ){
                printf( 'Queue %d: Starting'.PHP_EOL, $queue_id);
                $this->exec_queue( $queue_id );
            }
        }
    }

    function kill_queues(){
        for( $queue_id = 0 ; $queue_id < $this->queue_count; $queue_id++ ){
            $queue_need_start = true;
            
            $heartbeat = $this->heartbeat_last( $queue_id );
            if( $heartbeat == NULL ){
                printf( 'Queue %d: no heartbeat'.PHP_EOL, $queue_id );
            }else {
                if( isset( $heartbeat['pid'] ) ){
                    $cmd = sprintf( 'kill -9 %d', intval( $heartbeat['pid'] ) );
                    printf( 'Queue %d: %s'.PHP_EOL, $queue_id, $cmd );
                    exec( $cmd );
                }
            }
            if( file_exists( $this->heartbeat_file( $queue_id )) ){
                unlink( $this->heartbeat_file( $queue_id ) );
            }
        }
    }

    function exec_queue( $queue_id ){
        if( is_writable( 'log' ) ){
            $log = sprintf( 'log/queue_%d_%s', $queue_id, strftime( '%Y%m%d_%H%M%S',time() ) );
            $command = sprintf( 'php runqueue.php %d  > %s.log 2>&1 &', $queue_id, $log );
        }else{
            $command = sprintf( 'php runqueue.php %d  > /dev/null 2> /dev/null &', $queue_id );
        }
        if( $this->verbose ){
            printf( 'Exec %s'.PHP_EOL, $command );
        }
        exec( $command );

    }
}

?>
