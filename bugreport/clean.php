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
 *
 */

/*
 * This script will remove any files for which email and description is null
 */


if (!isset($argv[0]) || isset($_SERVER['HTTP_HOST']) || isset($_SERVER['REQUEST_METHOD'])) {
    header('HTTP/1.1 403 Forbidden');
    die;
}

include_once( dirname( __DIR__, 1 ) ) . '/api/sql_helper.php';
include('config_bugreport.php');

$sql = new sql_helper( $bug_config );
$bug_data_directory = $bug_config['bug_data_directory'];

$rows = $sql->query_as_array( "SELECT * FROM gc_bugreports WHERE filesize IS NOT NULL AND email IS NULL AND description IS NULL" );
$to_clean = 0;
$count_total = 0;
$count_to_delete = 0;

$sql->verbose = true;

foreach( $rows as $row) {
    $count_total += 1;
    $fn = sprintf( '%s/%s', $bug_data_directory, $row['filename'] );
    if( file_exists($fn) ){
        $to_clean += intval($row['filesize']);
        printf( '%s %s'.PHP_EOL, $fn, $row['filesize'] );
        $count_to_delete += 1;
        if( unlink( $fn ) ){
            $query = sprintf('UPDATE gc_bugreports SET filesize = NULL WHERE `id` = %d', $row['id']);
            $sql->execute_query($query);
        }
    }else{
        $query = sprintf('UPDATE gc_bugreports SET filesize = NULL WHERE `id` = %d', $row['id']);
        $sql->execute_query($query);
    }
}

printf( 'Cleaned: %dMb %d/%d'.PHP_EOL, $to_clean / 1024 / 1024, $count_to_delete, $count_total );
?>
