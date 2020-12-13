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


class CacheCheck {
    function __construct(){
        $this->rv = [];
        $this->process = new GarminProcess();
        $this->verbose = false;
        if( isset($_GET['verbose']) && $_GET['verbose']==1){
            $this->set_verbose( true );
        }

        $this->max_seconds = 10;
        if( isset( $_GET['max_seconds'] ) ){
            $this->max_seconds = intval( $_GET['max_seconds'] );
            if( $this->max_seconds < 1 ){
                $this->max_seconds = 10;
            }
        }
        
        $this->n = 10;
        if( isset( $_GET['n'] ) ){
            $this->n = intval( $_GET['n'] );
            if( $this->n < 1 || $this->n > 100 ){
                $this->n = 10;
            }
        }
        
        $this->threshold = time() - (3600.0);
        if( isset( $_GET['threshold'] ) ){
            $seconds = intval( $_GET['threshold'] );
            if( $seconds > 0 ){
                $this->threshold = time() - $seconds;
                if( $this->threshold < 0 ){
                    $this->threshold = time() - (3600.0);
                }
            }
        }
        
        if( isset( $_GET['detail'] ) ){
            $this->detail = true;
            $this->report_function = function($x) { return $x; };
        }else{
            $this->detail = false;
            $this->report_function = 'count';
        }
   }

    function set_verbose( $verbose ){
        $this->verbose = $verbose;
        $this->process->set_verbose( $verbose );
    }
    
    function check_recent_ts( $rows, $field = 'ts' ){
        $rv =  array();
        foreach( $rows as $row ){
            if( isset( $row[$field] ) ){
                $date = strtotime( $row[$field] );

                if( $date > $this->threshold ){
                    if( $this->verbose ){
                        printf( 'RECENT %s > %s'.PHP_EOL, date( DATE_RFC2822, $date), date( DATE_RFC2822, $this->threshold  ) );
                    }
                }else{
                    array_push( $rv, $row );
                    if( $this->verbose ){
                        printf( 'OLD    %s > %s'.PHP_EOL, date( DATE_RFC2822, $date), date( DATE_RFC2822, $this->threshold ) );
                    }
                }
            }
        }
        return $rv;
    }

    function check_process_time($rows){

        $rv = array();
        foreach( $rows as $row ){
            $processed = strtotime( $row['processed_ts'] );
            $started   = strtotime( $row['started_ts'] );

            $time = $processed - $started;

            if( $time > $this->max_seconds ){
                if( $this->verbose ){
                    printf( 'SLOW %s secs > %s secs (%s - %s)'.PHP_EOL, $time, $this->max_seconds, date( DATE_RFC2822, $processed ), date( DATE_RFC2822, $started ) );
                }
                array_push( $rv, $row );
            }else{
                if( $this->verbose ){
                    printf( 'FAST %s secs < %s secs (%s - %s)'.PHP_EOL, $time, $this->max_seconds, date( DATE_RFC2822, $processed ), date( DATE_RFC2822, $started ) );
                }
            }
        }
        return( $rv );
    }

    function run_test( $tag, $query, $check_function ){
        $rows = $this->process->sql->query_as_array( $query );
        if( $this->process->sql->lasterror ){
            $error = [ 'sql' => $query, 'error' => $this->process->sql->lasterror ];
            if( isset( $this->rv['errors'] ) ){
                array_push( $this->rv['errors'],  $error);
            }else{
                $this->rv['errors'] = [ $error ];
            }
        }else{
            $report =  $this->$check_function( $rows );
            if( count( $report) > 0 ){
                $this->status = 0;
            }
            if( $this->detail ){
                $this->rv[$tag] = $report;
            }else{
                $this->rv[$tag] = count( $report );
            }
        }
    }

    function run_checks(){
        $this->status = 1;
        $this->run_test( 'old_activities', sprintf( 'SELECT activity_id,ts,FROM_UNIXTIME(startTimeInSeconds) AS startTimeInSeconds FROM activities ORDER BY activity_id DESC LIMIT %d', $this->n ), 'check_recent_ts');
        $this->run_test( 'old_fitfiles',   sprintf( 'SELECT file_id,ts,FROM_UNIXTIME(startTimeInSeconds) AS startTimeInSeconds FROM fitfiles ORDER BY file_id DESC LIMIT %d', $this->n ), 'check_recent_ts', 'check_recent_ts' );
        $this->run_test( 'old_usage',      sprintf( 'SELECT usage_id,ts,cs_user_id FROM `usage` ORDER BY usage_id DESC LIMIT %d', $this->n ), 'check_recent_ts' );

        $this->run_test( 'slow_cache_activities', sprintf( 'SELECT cache_id,started_ts,processed_ts FROM cache_activities ORDER BY cache_id DESC  LIMIT %d', $this->n),'check_process_time' );
        $this->run_test( 'slow_cache_fitfiles',   sprintf( 'SELECT cache_id,started_ts,processed_ts FROM cache_fitfiles ORDER BY cache_id DESC  LIMIT %d', $this->n), 'check_process_time' );

        $this->rv['status'] = $this->status;
        $this->rv['checked'] = ['total_checked'=>$this->n, 'max_time' => $this->max_seconds];

        return $this->rv;
    }
}



$checker = new CacheCheck();
$rv = $checker->run_checks();

if( isset( $argv[0] ) ){
    print( json_encode( $rv ) );
}else{
    header('Content-Type: application/json');
    print( json_encode( $rv ) );
}

?>
