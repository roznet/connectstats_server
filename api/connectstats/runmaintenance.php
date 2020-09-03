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

foreach( array( 'cache_activities', 'cache_fitfiles' ) as $table ) {
    $query = sprintf( "SELECT MAX(cache_id),MAX(ts),COUNT(*) FROM %s WHERE ts < NOW() - INTERVAL 30 DAY", $table );
    $res = $process->sql->query_first_row( $query );
    $cache_id = $res['MAX(cache_id)'];
    printf( '%s: %d %s %s'.PHP_EOL, $table, $cache_id, $res['MAX(ts)'], $res['COUNT(*)'] );
    $delete_query = sprintf( 'DELETE FROM %s WHERE cache_id < %d', $table, $cache_id );
    printf( 'TODO: %s'.PHP_EOL, $delete_query );
    $delete_query = sprintf( 'DELETE FROM %s_map WHERE cache_id < %d', $table, $cache_id );
    printf( 'TODO: %s'.PHP_EOL, $delete_query );
}

printf( 'EXIT: Success'.PHP_EOL );
exit();
?>
