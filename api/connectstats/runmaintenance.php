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

/*
 *
 * usage: runfitfiles.php cache_id
 * 
 *       Arg: cache_id should be a cache_id from the table cache_fitfiles
 *
 *       Description: will process the informaiton in the cache for files, insert into fitfiles table
 *                    and trigger the callback download if necessary by calling runcallback.php on file_id for created rows in fitfiles
 */

include_once('../shared.php');

$process = new GarminProcess();
$process->set_verbose(true);

$process->ensure_commandline($argv??NULL);

$max_days = 45;

foreach( array( 'cache_activities', 'cache_fitfiles', 'cache_activities_map', 'cache_fitfiles_map' ) as $table ) {
    $query = sprintf( "SELECT COUNT(*) FROM %s WHERE ts < NOW() - INTERVAL %d DAY", $table, $max_days );
    $res = $process->sql->query_first_row( $query );
    $delete_count = $res['COUNT(*)'];
    $query = sprintf( "SELECT COUNT(*) FROM %s", $table);
    $res = $process->sql->query_first_row( $query );
    $total_count = $res['COUNT(*)'];

    $delete_query = sprintf( 'DELETE FROM %s WHERE ts < NOW() - INTERVAL %d DAY', $table, $max_days);
    printf( 'INFO: Will delete %d entries out of %d from %s'.PHP_EOL, $delete_count, $total_count, $table );
    printf( 'TODO: %s'.PHP_EOL, $delete_query );
}

if( ! $process->sql->table_exists( 'usage_summary' ) ){
    $query = "CREATE TABLE usage_summary (usage_summary_id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY, cs_user_id BIGINT(20) UNSIGNED, `day` DATE, `count` BIGINT(20) UNSIGNED, max_ts TIMESTAMP, min_ts TIMESTAMP)";
    $process->sql->execute_query( $query );
    $query = "CREATE INDEX usage_summary_day ON usage_summary (`day`)";
    $process->sql->execute_query( $query );
    $query = "CREATE INDEX usage_summary_cs_user_id ON usage_summary (`cs_user_id`)";
    $process->sql->execute_query( $query );
}
$summarize_query = sprintf("INSERT INTO usage_summary (`day`,cs_user_id,`count`,`max_ts`,`min_ts`) SELECT date(ts) AS `day`,cs_user_id,COUNT(*) AS `count`,MAX(ts) AS `max_ts`,MIN(ts) AS `min_ts` FROM `usage` WHERE date(ts) < SUBDATE(CURDATE(),%d) GROUP BY date(ts),cs_user_id ORDER BY `day`,cs_user_id", $max_days );
printf( 'TODO: %s'.PHP_EOL, $summarize_query );
$delete_query = sprintf( "DELETE FROM `usage` WHERE date(ts) < SUBDATE(CURDATE(),%d)", $max_days );
printf( 'TODO: %s'.PHP_EOL, $delete_query );

printf( 'EXIT: Success'.PHP_EOL );
exit();
?>
