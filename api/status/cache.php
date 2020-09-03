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
 * -----------
 *
 * This entry point should be called to get a status of the cache processing
 *
 */

include_once('../shared.php' );

$process = new GarminProcess();

function check_recent_ts( $rows, $field, $verbose = false ){
    $rv =  array();
    $threshold = time() - (3600.0);
    foreach( $rows as $row ){
        if( isset( $row[$field] ) ){
            $date = strtotime( $row[$field] );

            if( $date > $threshold ){
                if( $verbose ){
                    printf( 'RECENT %s > %s'.PHP_EOL, date( DATE_RFC2822, $date), date( DATE_RFC2822, $threshold  ) );
                }
            }else{
                array_push( $rv, $row );
                if( $verbose ){
                    printf( 'OLD    %s > %s'.PHP_EOL, date( DATE_RFC2822, $date), date( DATE_RFC2822, $threshold ) );
                }
            }
        }
    }
    return $rv;
}

function check_process_time($rows, $max_seconds = 10){

    $rv = array();
    foreach( $rows as $row ){
        $processed = strtotime( $row['processed_ts'] );
        $started   = strtotime( $row['started_ts'] );

        $time = $processed - $started;

        if( $time > $max_seconds ){
            array_push( $rv, $row );
        }
    }
    return( $rv );
}

date_default_timezone_set('MST');

$n = 10;
if( isset( $_GET['n'] ) ){
    $n = intval( $_GET['n'] );
    if( $n < 1 || $n > 100 ){
        $n = 10;
    }
}

if( isset( $_GET['detail'] ) ){
    $report_function = function($x) { return $x; };
}else{
    $report_function = 'count';
}

$rv = array(

    'total_checked' => $n,
    
    'old_activities' => $report_function(check_recent_ts( $process->sql->query_as_array( sprintf( 'SELECT activity_id,ts,FROM_UNIXTIME(startTimeInSeconds) AS startTimeInSeconds FROM activities ORDER BY activity_id DESC LIMIT %d', $n ) ),
                                     'ts'
    )),

    'old_fitfiles' => $report_function(check_recent_ts( $process->sql->query_as_array( sprintf( 'SELECT file_id,ts,FROM_UNIXTIME(startTimeInSeconds) AS startTimeInSeconds FROM fitfiles ORDER BY file_id DESC LIMIT %d', $n ) ),
                                     'ts'
    )),

    'old_usage' => $report_function(check_recent_ts( $process->sql->query_as_array( sprintf( 'SELECT usage_id,ts,cs_user_id FROM usage ORDER BY usage_id DESC LIMIT %d', $n ) ),
                                     'ts'
    )),


    'slow_cache_activities' => $report_function(check_process_time($process->sql->query_as_array(sprintf( 'SELECT cache_id,started_ts,processed_ts FROM cache_activities ORDER BY cache_id DESC  LIMIT %d', $n)),
                                             10
    )),
    'slow_cache_fitfiles' => $report_function(check_process_time($process->sql->query_as_array(sprintf( 'SELECT cache_id,started_ts,processed_ts FROM cache_fitfiles ORDER BY cache_id DESC  LIMIT %d', $n)),
                                             10
    )),

);

header('Content-Type: application/json');
print( json_encode( $rv ) );

?>
