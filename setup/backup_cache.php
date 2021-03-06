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
 * To configure:
 *  update `url_backup_source` key in config.php to the url back you want to do the back up from
 *  the back up will save into the database defined in config.php as 'database'
 *
 * Actions of the script:
 *  download the update since last back up from the source for tokens and users as well as the cache
 *
 *  will replace the url call back in the cache by a call to sync from the backup source
 *  you'll need to run backup_tasks to process the cache, this allows for throttling/staging/testing
 *
 */
include_once( '../api/shared.php' );
ini_set('memory_limit', '4096M'); // or you could use 1G

/*
 * Only backup from the cache and tokens/users, the rest will be recreated
 * uncomment to do simple copies
 */
$keys = array(
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

if( $newdata ){
    printf( "Should try more".PHP_EOL );
}

if( true ) {
    $process->set_verbose( true );

    $last_backup = $process->sql->query_first_row( 'SELECT MAX(activities_cache_id) AS activities_cache_id, MAX(fitfiles_cache_id) AS fitfiles_cache_id, MAX(fitfiles_url_fixed_cache_id) AS fitfiles_url_fixed_cache_id FROM backup_info' );
    $activities_latest = $process->sql->query_first_row( 'SELECT MAX(cache_id) AS cache_id FROM cache_activities' );
    $fitfiles_latest = $process->sql->query_first_row( 'SELECT MAX(cache_id) AS cache_id FROM cache_fitfiles' );

    $activities_cache_id_from = $last_backup['activities_cache_id'] ?? 0;
    $fitfiles_cache_id_from = $last_backup['fitfiles_cache_id'] ?? 0;

    $activities_cache_id_to = $activities_latest['cache_id'] ?? 0;
    $fitfiles_cache_id_to = $fitfiles_latest['cache_id'] ?? 0;

    $fitfiles_url_fixed_cache_id = intval($last_backup['fitfiles_url_fixed_cache_id']);

    $process->log( 'UPDATING', 'cache activities %d -> %d', $activities_cache_id_from, $activities_cache_id_to );
    $process->log( 'UPDATING', 'cache fitfiles %d -> %d', $fitfiles_cache_id_from, $fitfiles_cache_id_to );
    $process->log( 'UPDATING', 'url in cache fitfiles from %d', $fitfiles_url_fixed_cache_id);

    $query = sprintf( 'UPDATE cache_activities SET started_ts = NULL, processed_ts = NULL WHERE cache_id > %d', $activities_cache_id_from );
    $process->sql->execute_query(  $query );

    $query = sprintf( 'UPDATE cache_fitfiles SET started_ts = NULL, processed_ts = NULL WHERE cache_id > %d', $fitfiles_cache_id_from );
    $process->sql->execute_query(  $query );

    $max = 250000;
    
    $query = sprintf( 'SELECT * FROM cache_fitfiles WHERE cache_id > %s LIMIT %d', $fitfiles_url_fixed_cache_id, $max );

    $results = $process->sql->execute_query( $query );

    $process->set_verbose( false );

    $i = 0;
    $report_i = $max/10;

    $updated = 0;
    
    foreach( $results as $row ){
        $json = json_decode( $row['json'], true );
        if( $i % $report_i == 0){
            $process->log( 'UPDATING', 'url in fitfiles cache %d updated %d reached %d', $i, $updated, $fitfiles_url_fixed_cache_id );
        }
        if( isset( $json['activityFiles' ]) ) {
            $list = $json['activityFiles'];
            $newlist = array();
            $f = 0;
            $require_save = false;
            foreach( $list as $one ){
                if( isset( $one['callbackURL'] ) && isset( $one['summaryId'] ) ){
                    $callbackURL = sprintf( '%s/api/connectstats/sync?summary_id=%d&table=fitfiles', $process->api_config['url_backup_source'], $one['summaryId'] );
                    if( $one['callbackURL'] != $callbackURL ){
                        $one['callbackURL'] = $callbackURL;
                        $require_save = true;
                    }
                }
                array_push( $newlist, $one );
            }
            if( $require_save ){
                $json['activityFiles'] = $newlist;
                $row['json'] = json_encode( $json );
                $process->sql->insert_or_update( 'cache_fitfiles', $row, array( 'cache_id' ) );
                $updated ++;
            }
            if( intval( $row['cache_id'] ) > $fitfiles_url_fixed_cache_id ){
                $fitfiles_url_fixed_cache_id = intval($row['cache_id']);
            }
            $i++;
        }
    }

    $process->log( 'UPDATED', 'url in fitfiles cache %d updated %d reached %d', $i, $updated, $fitfiles_url_fixed_cache_id );
    $process->sql->verbose = true;
    $process->sql->insert_or_update( 'backup_info', array( 'activities_cache_id' => $activities_cache_id_to, 'fitfiles_cache_id' => $fitfiles_cache_id_to, 'fitfiles_url_fixed_cache_id' => $fitfiles_url_fixed_cache_id ) );
}
 
?>
