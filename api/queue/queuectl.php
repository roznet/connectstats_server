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

include_once( '../queue.php');

$queue = new Queue();
$queue->verbose = true;
#$queue->sql->verbose = true;

$queue->ensure_commandline($argv??NULL,1);

$options = getopt( 'n', [], $n );
$remain = array_slice( $argv, $n );

if( count( $remain ) > 0 ){
    $command = $remain[0];
    $args = array_slice( $remain, 1 );

    switch( $command ){
    case 'kill':
        $queue->kill_queues();
        break;
    case 'start':
        $queue->start_queues();
        break;
    case 'list':
        $queue->list_queues();
        break;
    case 'ps':
        $queue->find_running_queues(true);
        break;
    case 'add':
        $not_before_ts = NULL;
        if( isset($args[1] ) ){
            if( intval( $args[1] ) > 0 ){
                $not_before_ts = time() + intval( $args[1] );
            }
        }
        $queue->add_task( $args[0], getcwd(), $not_before_ts );
        break;
    case 'run':
        $queue->run($args[0]);
        break;
    default:
        
    }
}

?>
