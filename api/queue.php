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
}

?>
