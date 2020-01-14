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

/*
 * Only backup from the cache and tokens/users, the rest will be recreated
 * uncomment to do simple copies
 */
$keys = array(
    'activities' => 'activity_id',
    'assets' => 'asset_id',
    'fitfiles' => 'file_id',
    'weather' => 'file_id',
    'fitsession' => 'file_id',
    'cache_activities' => 'cache_id',
    'cache_fitfiles' => 'cache_id',
    'cache_activities_map' => 'activity_id',
    'cache_fitfiles_map' => 'file_id',
    'tokens' => 'token_id',
    'users' => 'cs_user_id',
    'usage' => 'usage_id',
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

?>
