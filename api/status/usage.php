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
$command_line = isset( $argv[0] ) && ! isset( $_SERVER['HTTP_HOST'] );

if( ! $command_line ){
    $process->authenticate_system_call();    
}
$activities = $process->sql->query_as_array( 'SELECT count(*) AS `activity_count`,DATE(ts) as `date` FROM cache_activities WHERE ts > NOW() - INTERVAL 2 WEEK GROUP BY DATE(ts)' );
$users = $process->sql->query_as_array( 'SELECT `date`, COUNT(cs_user_id) AS user_count FROM ( SELECT DATE(ts) as `date`,cs_user_id, count(*) as num FROM `usage` WHERE  ts > NOW() - INTERVAL 2 WEEK GROUP BY cs_user_id, `date` ORDER BY NUM DESC ) AS table1 GROUP BY `date` ORDER BY `date`' );


function group( $summary, $tag, $rows ){
    $rv = $summary;
    foreach ($rows as $row) {
        $date = $row['date'];
        if (array_key_exists($date, $summary)) {
            $rv[$date][$tag] = $row[$tag];
        } else {
            $rv[$date] = array($tag  => $row[$tag], 'date' => $date);
        }
    }
    return $rv;
}


$rv = group( array(), 'activity_count', $activities );
$rv = group( $rv, 'user_count', $users );

if( $command_line ){
    printf( "%-14s %12s %12s".PHP_EOL, "Date",  "Activities",    "Users");
    foreach ( $rv as  $date => $values ){
        $time = strtotime( $date );
        $day = date( 'D', $time );
        printf( "%-14s %12s %12s".PHP_EOL, $day.' '.$date, $values['activity_count'], $values['user_count'] );
    }
}else{
    $process->authenticate_system_call();    
    header('Content-Type: application/json');
    print( json_encode( array_values($rv) ) );
}

?>
