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


error_reporting(E_ALL);

/*
 * 1. download from `url_backup_source` key in config.php and save into the database defined in config.php
 * 2. edit 
 *
 */
include_once( '../api/shared.php' );


$keys = array(
#    'activities' => 'activity_id',
#    'backfills' => 'backfill_id',
#    'assets' => 'asset_id',
#    'fitfiles' => 'file_id',
#    'weather' => 'file_id',
#    'fitsession' => 'file_id'
    'cache_activities' => 'cache_id',
    'cache_fitfiles' => 'cache_id',
    'tokens' => 'token_id',
    'users' => 'cs_user_id',
);

$process = new GarminProcess();

$process->ensure_commandline($argv);
$process->ensure_schema();

/*
 * To create the database backup_info
 *   CREATE TABLE backup_info (backup_id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY, activities_cache_id BIGINT(20) UNSIGNED, fitfiles_cache_id BIGINT(20) UNSIGNED, ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP)
 */
if( ! $process->sql->table_exists( 'backup_info' ) ){
    printf( 'ERROR: needs to run against a database setup for backup'.PHP_EOL );
    die();
}

$newdata = false;
if( true ){
    foreach( $keys as $table => $key ){
        $newdata_for_table = $process->maintenance_backup_table( $table, $key );
        if( $newdata_for_table ){
            $newdata = true;
        }
    }
}

$process->set_verbose( false );

$last_backup = $process->sql->query_first_row( 'SELECT MAX(activities_cache_id) AS activities_cache_id, MAX(fitfiles_cache_id) AS fitfiles_cache_id FROM backup_info' );
$activities_latest = $process->sql->query_first_row( 'SELECT MAX(cache_id) AS cache_id FROM cache_activities' );
$fitfiles_latest = $process->sql->query_first_row( 'SELECT MAX(cache_id) AS cache_id FROM cache_fitfiles' );

$activities_cache_id_from = $last_backup['activities_cache_id'] ?? 0;
$fitfiles_cache_id_from = $last_backup['fitfiles_cache_id'] ?? 0;

$activities_cache_id_to = $activities_latest['cache_id'] ?? 0;
$fitfiles_cache_id_to = $fitfiles_latest['cache_id'] ?? 0;

printf( 'Updating cache activities %d -> %d'.PHP_EOL, $activities_cache_id_from, $activities_cache_id_to );
printf( 'Updating cache fitfiles %d -> %d'.PHP_EOL, $fitfiles_cache_id_from, $fitfiles_cache_id_to );

$query = sprintf( 'UPDATE cache_activities SET started_ts = NULL, processed_ts = NULL WHERE cache_id > %d', $activities_cache_id_from );
$process->sql->execute_query(  $query );

$query = sprintf( 'UPDATE cache_fitfiles SET started_ts = NULL, processed_ts = NULL WHERE cache_id > %d', $fitfiles_cache_id_from );
$process->sql->execute_query(  $query );

$query = sprintf( 'SELECT * FROM cache_fitfiles WHERE cache_id > %s', $fitfiles_cache_id_from );

$results = $process->sql->execute_query( $query );

$i = 0;
foreach( $results as $row ){
    $json = json_decode( $row['json'], true );
    if( isset( $json['activityFiles' ]) ) {
        $list = $json['activityFiles'];
        $newlist = array();
        $f = 0;
        foreach( $list as $one ){
            if( isset( $one['callbackURL'] ) && isset( $one['summaryId'] ) ){
                $callbackURL = sprintf( '%s/api/connectstats/sync?summaryId=%d&table=assets', $process->api_config['url_backup_source'], $one['summaryId'] );
                $one['callbackURL'] = $callbackURL;
            }
            array_push( $newlist, $one );
        }
        $json['activityFiles'] = $newlist;
        $row['json'] = json_encode( $json );
        $process->sql->insert_or_update( 'cache_fitfiles', $row, array( 'cache_id' ) );
        $i++;
    }
}

$process->sql->insert_or_update( 'backup_info', array( 'activities_cache_id' => $activities_cache_id_to, 'fitfiles_cache_id' => $fitfiles_cache_id_to ) );

if( $newdata ){
    printf( "Should try more".PHP_EOL );
}

?>
