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
 * 
 *  ----- Typical Workflow ----
 *  1. Garmin API:       oauth process with garmin, returns accessToken and accessTokenSecret
 *  2. ConnectStats API: Register user on connectstats and get cs_user_id 
 *                        uri: api/connectstats/user_register 
 *                        php: register_user()
 *  3. ConnectStats API: Validate the token id is correct and get information about the user
 *                        uri: api/connectstats/validateuser
 *                        php: validate_user()
 *
 *  6. Garmin API:        Callback from garmin with new activities
 *                        uri: api/garmin/activities
 *                        php: save_to_cache('activities')
 *                        desc: save into table cache_activities
 *                        next: push to the queue 'runactivities.php #insertIdInCache#'
 *        6.2             run: php runactivities.php #cache_id#
 *                        php: process('activities', cache_id )
 *                        desc: update activities table with info in cache_activities @ cache_id, try to link to existing fitfiles
 *  7. Garmin API:        Callback from garmin with new files and callbackURL (one for new activities, multiple for backfill callback)
 *                        uri: api/garmin/file
 *                        php: save_to_cache('fitfiles')
 *                        desc: update fitfiles table with info in cache_fitfiles @ cache_id, try to link to existing fitfiles
 *                        next: push to the queue 'runfitfiles.php #insertIdIncache#'
 *        7.2             run: php runfitfiles.php cache_id
 *                        php: process('activities', cache_id )
 *                        next: if callbackURL, will start a runcallback.php file_id to trigger the download
 *        7.3             run: php runcallback.php file_id
 *                        php: run_file_callback( 'fitfiles', [ file_id1, file_id2, ... ] )
 *                        desc: do the callback to the service to get the file and save in assets table reference
 *        7.4             run: php runfitextract.php file_id
 *                        desc: if file from active user and valid: extract fit session info and potentially download weather info
 *                        php: fit_extract( file_id, fit->data_mesgs ) 
 *                             
 *                        
 * 11. Connectstats API:  Maintenance of the database state 
 *                        uri: api/garmin/maintenance
 *                        Trying to download missing callback calls: maintenance_fix_missing_callback()
 *                        Trying to link activities to files calls: maintenance_link_activity_files()
 * 12. Connectstats API:  Get extra json data for activity, for example weather or fit file session information
 *                        uri: api/connectstats/json
 *                        php: query_json()
 */


include_once( 'queue.php');
include_once( 'sql_helper.php');
include_once( 'S3.php' );

class garmin_sql extends sql_helper{
	function __construct() {
        include( 'config.php' );
		parent::__construct( $api_config );
	}
}

class StatusCollector {
    function __construct( ){
        $this->messages = array();
        $this->table = NULL;
        $this->verbose = false;

        if( isset( $_SERVER['HTTP_USER_AGENT'] ) ){
            $this->HTTP_USER_AGENT = $_SERVER['HTTP_USER_AGENT'];
        }else{
            $this->HTTP_USER_AGENT = 'Not available';
        }
        if( isset( $_SERVER['REMOTE_ADDR'] ) ){
            $this->REMOTE_ADDR = $_SERVER['REMOTE_ADDR'];
        }else{
            $this->REMOTE_ADDR = 'Not available';
        }
    }

    function clear($table){
        $this->table = $table;
        $this->messages = array();
    }
    
    function log(){
        if( !isset( $this->start_ts ) ){
            $this->start_ts = microtime(true);
        }
        
        $args = func_get_args();
        $tag = array_shift( $args );
        $fmt = array_shift( $args );

        $msg = vsprintf( $fmt, $args );
        
        printf( "%s:%.3f: %s".PHP_EOL, $tag, microtime(true)-$this->start_ts, $msg );
    }
    
    function error( $msg ){
        if( $this->verbose ){
            $this->log( "ERROR", $msg );
        }
        array_push( $this->messages, $msg );
    }

    function success() {
        return( count( $this->messages ) == 0);
    }
    function hasError() {
        return( count( $this->messages ) > 0 );
    }

    function record($sql,$rawdata) {
        if(  $sql && $this->table !== NULL ){
            $error_table = sprintf( "error_%s", $this->table );

            if( ! $sql->table_exists( $error_table ) ){
                $sql->create_or_alter( $error_table, array(
                    'ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                    'json' => 'MEDIUMTEXT',
                    'message' => 'TEXT',
                    'user_agent' => 'TEXT',
                    'remote_addr' => 'TEXT'
                ) );
                                                        
            }
            if( gettype( $rawdata ) == 'array' ){
                $rawdata = json_encode( $rawdata );
            }
            $row = array( 'json' => $rawdata,
                          'message' => implode(', ', $this->messages ),
                          'user_agent' => $this->HTTP_USER_AGENT,
                          'remote_addr' => $this->REMOTE_ADDR,
            );

            if( ! $sql->insert_or_update( $error_table, $row ) ){
                $this->log('ERROR', 'Failed To Record: %s', $sql->lasterror );
            }
        }else{
            print( "ERRORS".PHP_EOL );
            print_r( $this->messages );
        }
    }
}

/**
 *   This is the main functionality for paging activities and files in queries
 *   It constructs the proper where and paging construct for a select query
 *   The parameters are extracted from the GET params.
 * 
 *   Several style of paging are supported:
 *   - start & limit: the first activitity will be at `start` offset from the latest activities 
 *   and a maximum of `limit` activities are returned
 *
 *   - activity_id: will only return data for that activity id, limit will be irrelevant
 *   
 *   - from_activity_id: will return only activities with a higher activity_id than `from_activity_id`
 */
class Paging {
    const SYSTEM_TOKEN = -1;

    function __construct( $getparams, $token_id, $sql ){
        $this->sql = $sql;
        $this->token_id = $token_id;

        if( isset( $getparams['start'] ) ){
            $this->start = intval( $getparams['start']);
        }

        if( isset( $getparams['from_activity_id' ] ) ){
            $this->from_activity_id = intval( $getparams['from_activity_id'] );
        }

        if( isset( $getparams['to_activity_id' ] ) ){
            $this->to_activity_id = intval( $getparams['to_activity_id'] );
            if( isset( $this->from_activity_id ) ){
                $this->limit = ($this->to_activity_id - $this->from_activity_id);
            }
        }

        if( isset( $getparams['activity_id'] ) ){
            $this->activity_id = intval( $getparams['activity_id'] );
        }

        if( isset( $getparams['file_id'] ) ){
            $this->file_id = intval( $getparams['file_id'] );
        }

        if( isset( $getparams['limit'] ) ){
            $this->limit = intval($getparams['limit']);
        }else{
            if( isset( $getparams['summaryStartTimeInSeconds'] ) && isset( $getparams['summaryEndTimeInSeconds'] ) ){
                $this->summary_start_time_in_seconds = intval($getparams['summaryStartTimeInSeconds']); 
                $this->summary_end_time_in_seconds = intval($getparams['summaryEndTimeInSeconds']); 
            }else{
                $this->limit = 1;
            }
        }

        if( isset( $getparams['summary_id'] ) ){
            $this->summary_id = intval( $getparams['summary_id'] );
        }

        if( $token_id != Paging::SYSTEM_TOKEN ){
            $token = $this->sql->query_first_row( "SELECT cs_user_id FROM tokens WHERE token_id = $token_id" );

            if( isset( $token['cs_user_id' ] ) ){
                $this->cs_user_id = intval($token['cs_user_id']);
            }else{
                $this->cs_user_id = 0; // invalid user
            }
        }
    }

    function activities_where(){
        $conditions = array();

        if( isset( $this->cs_user_id ) && $this->token_id != Paging::SYSTEM_TOKEN ){
            array_push( $conditions, sprintf( 'activities.cs_user_id = %d', $this->cs_user_id ) );
        }
        
        if( isset( $this->activity_id ) ){
            array_push( $conditions, sprintf( 'activities.activity_id = %d', $this->activity_id ) );
        }else if( isset( $this->from_activity_id ) ){
            array_push( $conditions, sprintf( 'activities.activity_id >= %d', $this->from_activity_id ) );
        }else if( isset( $this->summary_start_time_in_seconds ) && isset( $this->summary_end_time_in_seconds ) ){
            array_push( $conditions, sprintf( 'activities.startTimeInSeconds >= %d AND activities.startTimeInSeconds < %d',
                            $this->summary_start_time_in_seconds,
                            $this->summary_end_time_in_seconds
            ) );
        }else if( isset( $this->summary_id ) ){
            array_push( $conditions, sprintf( "summaryId = '%d'", $this->summary_id ) );
        }
        return implode( ' AND ', $conditions );
    }


    function activities_only_one(){
        return $this->limit ==1 || ( !isset( $this->summary_start_time_in_seconds ) );
    }
    
    function activities_paging(){
        if( isset( $this->start ) ){
            return sprintf( 'LIMIT %d OFFSET %d', $this->limit, $this->start );
        }else if( isset( $this->limit ) ){
            return sprintf( 'LIMIT %d', $this->limit );
        }else{
            return '';
        }
    }
    
    function filename_identifier(){
        if( isset( $this->activity_id ) ){
            return sprintf( '%d', $this->activity_id );
        }else if( isset( $this->from_activity_id ) ){
               return sprintf( '%d', $this->from_activity_id );
        }else if( isset( $this->summary_start_time_in_seconds ) && isset( $this->summary_end_time_in_seconds ) ){
            return sprintf( '%d_%d_%d',
                            $this->cs_user_id,
                            $this->summary_start_time_in_seconds,
                            $this->summary_end_time_in_seconds-$this->summary_start_time_in_seconds
            );
        }else if( isset( $this->summary_id ) ){
            return sprintf( "%d", $this->summary_id );
        }else{
            return sprintf( '%d', $this->cs_user_id );
        }
    }

    function activities_total_count(){
        if( ! isset( $this->activities_total_count ) ){
            if( isset( $this->cs_user_id ) && $this->cs_user_id != Paging::SYSTEM_TOKEN ){
                $query = sprintf( 'SELECT COUNT(json) FROM activities WHERE cs_user_id = %d', $this->cs_user_id );
            }else{
                $query = sprintf( 'SELECT COUNT(json) FROM activities' );
            }
            $count = $this->sql->query_first_row( $query );
            if( isset($count['COUNT(json)']) ){
                $this->activities_total_count = intval( $count['COUNT(json)'] );
            }else{
                $this->activities_total_count = 0;
            }
        }
        return $this->activities_total_count;
    }

    function json(){
        $count = $this->activities_total_count();
        if( isset( $this->limit ) ){
            return array( 'total' => $count, 'start' => $this->start??0, 'limit' => $this->limit );
        }elseif( isset( $this->summary_end_time_in_seconds ) && isset( $this->summary_start_time_in_seconds ) ){
            return array( 'total' => $count, 'summaryStartTimeInSeconds' => $this->summary_start_time_in_seconds, 'summaryEndTimeInSeconds' => $this->summary_end_time_in_seconds );
        }else{
            return array( 'total' => $count );
        }
    }
                              
    function direct_file_query(){
        return isset( $this->file_id );
    }

    function summary_file_query() {
        return isset( $this->summary_id );
    }
    
    function file_where(){
        if( isset( $this->summary_id ) ){
            return sprintf("summaryId = '%d'", $this->summary_id );
        }
        return sprintf('file_id = %d', $this->file_id );
    }

    function file_paging() {
        return sprintf( 'LIMIT %d', $this->limit );
    }

    
}

class GarminProcess {
    var $debug = false;
    
    function __construct() {
        $this->use_queue = true;
        $this->start_ts = microtime(true);
        $this->sql = new garmin_sql();
        $this->sql->start_ts = $this->start_ts;
        $this->sql->verbose = false;
        $this->verbose = false;
        $this->status = new StatusCollector();

        if( isset($_GET['verbose']) && $_GET['verbose']==1){
            $this->set_verbose( true );
        }
        if( isset( $_GET['debug']) && $_GET['debug'] == 1){
            $this->debug = true;
            $this->sql->debug = true;
        }
        if( intval(getenv('ROZNET_VERBOSE'))==1){
            $this->set_verbose( true );
        }
        if( intval(getenv('ROZNET_DEBUG'))==1){
            $this->debug = true;
            $this->sql->debug = true;
        }

        include( 'config.php' );
        $this->api_config = $api_config;

    }
    
    function set_verbose($verbose){
        $this->verbose = $verbose;
        $this->sql->verbose = $verbose;
        $this->status->verbose = $verbose;
    }

    function log(){
        if( !isset( $this->start_ts ) ){
            $this->start_ts = microtime(true);
        }
        
        $args = func_get_args();
        $tag = array_shift( $args );
        $fmt = array_shift( $args );

        $msg = vsprintf( $fmt, $args );
        if( $this->debug ){
            $bt = debug_backtrace(false);
            foreach( $bt as $frame ){
                printf( '  %s[%s] %s.%s'.PHP_EOL, $frame['file'], $frame['line'], $frame['class'] ?? '', $frame['function'] );
            }
        }
            
        printf( "%s:%.3f: %s".PHP_EOL, $tag, microtime(true)-$this->start_ts, $msg );
    }
    
    // Reset Database schema from scratch 
    function reset_schema() {
        // For development database only
        if( $this->sql->table_exists( 'dev' ) ){
            $tables = array( 'activities', 'assets', 'tokens', 'error_activities', 'error_fitfiles', 'schema', 'users', 'fitfiles', 'fitsession', 'weather' );
            foreach( $tables as $table ){
                $this->sql->execute_query( "DROP TABLE IF EXISTS `$table`" );
            }
            return true;
        }else{
            return false;
        }
    }

    /**
     *    This function will ensure that the script is called from the command line
     */
    function ensure_commandline($argv, $min_args = 0){
        if( ! isset( $argv[$min_args] ) || count( $argv ) < $min_args || isset( $_SERVER['HTTP_HOST'] ) || isset( $_SERVER['REQUEST_METHOD'] ) ){
            header('HTTP/1.1 403 Forbidden');
            die;
        }
        if( $this->verbose ){
            $this->log( 'STARTING','%s', implode( ' ', $argv ) );
        }
        return true;
    }

    /**
     */
    function ensure_schema() {
        $schema_version = 8;
        $schema = array(
            "usage" => array(
                'usage_id' => 'BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY',
                'ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                'cs_user_id' => 'BIGINT(20) UNSIGNED',
                'status' => 'INT UNSIGNED',
                'REQUEST_URI' => 'VARCHAR(256)',
                'SCRIPT_NAME' => 'VARCHAR(256)',
            ),
            "users" => array(
                'cs_user_id' => 'BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY',
                'userId' => 'VARCHAR(128)',
                'ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                'created_ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
            ),
            "users_usage" => array(
                'cs_user_id' => 'BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY',
                'days' => 'BIGINT(20) UNSIGNED',
                'last_ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                'first_ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
            ),
            "tokens" => array(
                'token_id' => 'BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY',
                'cs_user_id' => 'BIGINT(20) UNSIGNED',
                'userAccessToken' => 'VARCHAR(128)',
                'userId' => 'VARCHAR(128)',
                'userAccessTokenSecret' => 'VARCHAR(128)',
                'ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                'created_ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
            ),
            "cache_activities" => array(
                'cache_id' => 'BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY',
                'ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                'started_ts' => 'DATETIME',
                'processed_ts' => 'DATETIME',
                'json'=>'MEDIUMTEXT'
            ),
            "cache_activities_map" => array(
                'activity_id' => 'BIGINT(20) UNSIGNED PRIMARY KEY',
                'cache_id' => 'BIGINT(20) UNSIGNED',
                'ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
            ),
            "cache_fitfiles" => array(
                'cache_id' => 'BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY',
                'ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                'started_ts' => 'DATETIME',
                'processed_ts' => 'DATETIME',
                'json'=>'MEDIUMTEXT'
            ),
            "cache_fitfiles_map" => array(
                'file_id' => 'BIGINT(20) UNSIGNED PRIMARY KEY',
                'cache_id' => 'BIGINT(20) UNSIGNED',
                'ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
            ),
            "activities" =>  array(
                'activity_id' => 'BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY',
                'cs_user_id' => 'BIGINT(20) UNSIGNED',
                'file_id' => 'BIGINT(20) UNSIGNED',
                'ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                'json' => 'TEXT',
                'startTimeInSeconds' => 'BIGINT(20) UNSIGNED',
                'userId' => 'VARCHAR(128)',
                'userAccessToken' => 'VARCHAR(128)',
                'summaryId' => 'VARCHAR(128)',
                'created_ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
                'parent_activity_id' => 'BIGINT(20) UNSIGNED'
            ),
            "weather" =>  array(
                'file_id' => 'BIGINT(20) UNSIGNED PRIMARY KEY',
                'cs_user_id' => 'BIGINT(20) UNSIGNED',
                'ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                'json' => 'TEXT',
            ),
            "fitsession" =>  array(
                'file_id' => 'BIGINT(20) UNSIGNED PRIMARY KEY',
                'cs_user_id' => 'BIGINT(20) UNSIGNED',
                'ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                'json' => 'TEXT',
            ),
            "fitfiles" => array(
                'file_id' => 'BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY',
                'activity_id' => 'BIGINT(20) UNSIGNED',
                'asset_id' => 'BIGINT(20) UNSIGNED',
                'cs_user_id' => 'BIGINT(20) UNSIGNED',
                'fileType' => 'VARCHAR(16)',
                'ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                'userId' => 'VARCHAR(128)',
                'userAccessToken' => 'VARCHAR(128)',
                'callbackURL' => 'TEXT',
                'startTimeInSeconds' => 'BIGINT(20) UNSIGNED',
                'summaryId' => 'VARCHAR(128)',
                'created_ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
            ),
            'assets' => array(
                'asset_id' => 'BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY',
                'file_id' => 'BIGINT(20) UNSIGNED',
                'ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                'tablename' => 'VARCHAR(128)',
                'filename' => 'VARCHAR(32)',
                'path' => 'VARCHAR(128)',
                'data' => 'MEDIUMBLOB',
                'created_ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
            ),
            'assets_s3' => array(
                'asset_id' => 'BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY',
                'file_id' => 'BIGINT(20) UNSIGNED',
                'ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                'tablename' => 'VARCHAR(128)',
                'filename' => 'VARCHAR(32)',
                'path' => 'VARCHAR(128)',
                'data' => 'MEDIUMBLOB',
                'created_ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
            ),
        );
        $create = false;
        if( ! $this->sql->table_exists('schema') ){
            $create = true;
            $this->sql->create_or_alter('schema', array( 'ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP', 'version' => 'BIGINT(20) UNSIGNED' ) );
        }else{
            $r = $this->sql->query_first_row('SELECT MAX(version) AS v FROM `schema`' );
            if( $r['v'] < $schema_version ){
                $create = true;
            }
        }

        if( $create ){
            foreach( $schema as $table => $defs ){
                $this->sql->create_or_alter( $table, $defs );
            }
            $this->sql->insert_or_update('schema', array( 'version' => $schema_version ) );
        }
    }

    function deregister_user(){
        $this->ensure_schema();
        
        $this->status->clear('users');
        
        $end_points_fields = array(
            'userId' => 'VARCHAR(1024)',
        );

        $rawdata = file_get_contents("php://input");
        if( ! $rawdata ){
            $this->status->error('Input from query appears empty' );
        }

        if( $this->status->success() ) {
            $data = json_decode($rawdata,true);
            if( ! $data ) {
                $this->status->error( 'Failed to decode json' );
            }
            foreach( $data as $summary_type => $infos){
                foreach( $infos as $info){
                    if( isset( $info['userAccessToken'] ) ){
                        $user = $this->user_info( $info['userAccessToken'] );
                        $token = $this->validate_token( $info['userAccessToken'] );
                        $query = "UPDATE tokens SET userAccessTokenSecret = NULL WHERE userAccessToken = '$token'";
                        if( ! $this->sql->execute_query( $query ) ){
                            $this->status->error( sprintf( 'Sql failed %s (%s)', $query, $this->sql->lasterror ) );
                        }
                    }
                }
            }
        }
        if( $this->status->hasError() ){
            $this->status->record( $this->sql, $rawdata );
        }
        return $this->status->success();
    }
    
    function register_user( $userAccessToken, $userAccessTokenSecret ){
        $this->ensure_schema();
        
        $this->status->clear('users');

        $values = array( 'userAccessToken' => $userAccessToken,
                         'userAccessTokenSecret' => $userAccessTokenSecret
        );

        # Check the token is valid and we can get a user id from garmin
        $user = $this->get_url_data( $this->api_config['url_user_id'], $userAccessToken, $userAccessTokenSecret );
        if( $user ){
            $userjson = json_decode( $user, true );
            if( isset( $userjson['userId'] ) ){
                $userId = $userjson['userId'];
                $values['userId'] = $userId;
                
                $prev = $this->sql->query_first_row( "SELECT * FROM users WHERE userId = '$userId'" );
                
                if( $prev ){
                    $cs_user_id = $prev['cs_user_id'];
                }else{
                    $this->sql->insert_or_update( 'users', array( 'userId' => $userId ) );
                    $cs_user_id = $this->sql->insert_id();
                }
            }else{
                # If failed, still registeer token/secret as it may be valid later (race condition in creation once in a while)
                if( !$this->sql->insert_or_update( 'tokens', $values, array( 'userAccessToken' ) ) ){
                    $this->status->error( sprintf( 'failed to get save data for %s', $userAccessToken ) );
                }
                $this->status->error( "missing userId in response $user" );
                $this->status->record( $this->sql, $values  );
                # If we didn't get a user id from garmin, just die as unauthorized
                # this will avoid people registering random token
                header('HTTP/1.1 401 Unauthorized error');
                die;
            }
            $values['cs_user_id'] = $cs_user_id;
        }else{
            $this->status->error( sprintf( 'failed to get url data for %s', $userAccessToken ) );
        }

        if( !$this->sql->insert_or_update( 'tokens', $values, array( 'userAccessToken' ) ) ){
            $this->status->error( sprintf( 'failed to get save data for %s', $userAccessToken ) );
        }
        $token_id = $this->sql->insert_id();
        
        $query = sprintf( "SELECT userAccessToken,userId,token_id,cs_user_id FROM tokens WHERE userAccessToken = '%s'", $userAccessToken );

        $rv = $this->sql->query_first_row( $query );

        if( $this->status->hasError() ) {
            $this->status->record($this->sql,$values);
        }
        
        return $rv;
    }

    function user_info_for_token_id( $token_id ){
        $query = sprintf( "SELECT * FROM tokens WHERE token_id = %d", $token_id );

        $rv = $this->sql->query_first_row( $query );

        return $rv;
    }

    function create_active_users(){
        $this->log( 'INFO', 'Create active Users Table' );
        $query = "DROP TABLE IF EXISTS users_active";
        $this->sql->execute_query( $query );
        $query = "CREATE TABLE users_active (cs_user_id BIGINT(20) UNSIGNED PRIMARY KEY, cnt BIGINT(20) UNSIGNED, last_ts TIMESTAMP, first_ts TIMESTAMP)";
        $this->sql->execute_query( $query );
        $query = "INSERT INTO users_active SELECT cs_user_id,COUNT(*) AS cnt,MAX(ts) AS last_ts,MIN(ts) AS first_ts FROM `usage` GROUP BY cs_user_id ORDER BY last_ts DESC";
        $this->sql->execute_query( $query );
        $query = "SELECT * FROM users_active";
        $rv = $this->sql->query_as_array( $query );
        return $rv;
    }        
    
    function user_is_active( $cs_user_id ){
        $query = sprintf( "SELECT cs_user_id,last_ts FROM `users_usage` WHERE cs_user_id = %d LIMIT 1", $cs_user_id );
        $rv = $this->sql->query_first_row( $query );
        $threshold_45_days = time() - ( 24.0 * 3600.0 * 45.0 );
        if( $rv && isset( $rv['last_ts'] ) && strtotime( $rv['last_ts'] ) > $threshold_45_days ) {
            return( true );
        }
        if( $this->verbose ){
            if( isset( $rv['last_ts'] ) ){
                $this->log( 'WARNING', 'User %d inactive since %s', $cs_user_id, $rv['last_ts'] );
            }else{
                $this->log( 'WARNING', 'User %d inactive', $cs_user_id );
            }
        }
        return false;
    }
    
    function user_info( $userAccessToken ){
        $query = sprintf( "SELECT * FROM tokens WHERE userAccessToken = '%s'", $userAccessToken );

        $rv = $this->sql->query_first_row( $query );

        return $rv;
    }


    function cache_for_table($table){
        return sprintf( 'cache_%s', $table);
    }
    
    /**
     * Called from the garmin health API. 
     * It will save the output from the api call back and add to the queue
     * a command to process it later
     */
    function save_to_cache($table){
        $success = false;
        $start_time = time();
        $last_insert = NULL;
        
        $this->ensure_schema();

        $cachetable = $this->cache_for_table($table);

        $this->status->clear($cachetable);

        $rawdata = file_get_contents("php://input");
        if( ! $rawdata ){
            $this->status->error('Input from query appears empty' );
        }

        if( $this->status->success() ) {
            $data = json_decode($rawdata,true);
            if( ! $data ) {
                $this->status->error( 'Failed to decode json' );
            }else{
                $query = sprintf( 'INSERT INTO %s (started_ts,json) VALUES (FROM_UNIXTIME(%d),?)', $cachetable, $start_time);

                $stmt = $this->sql->connection->prepare($query);
                if( $stmt ){
                    if( $this->verbose ){
                        $this->log( 'EXECUTE', $query );
                    }
                    $json = json_encode($data);
                    $stmt->bind_param('s', $json);
                    if (!$stmt->execute()) {
                        $this->status->error(  "Execute failed: (" . $stmt->errno . ") " . $stmt->error );
                        if( $this->verbose ){
                            $this->log( 'ERROR','%s [%s] ',  $stmt->error, $query);
                        }
                    }else{
                        $success = true;
                        $last_insert = $stmt->insert_id;
                    }
                    $stmt->close();
                }else{
                    $this->status->error(  "Execute failed: (" . $stmt->errno . ") " . $stmt->error );
                    if( $this->verbose ){
                        $this->log( 'ERROR', '%s [%s] ',  $stmt->error, $query);
                    }
                }
            }
        }
        if( $success && $last_insert ){
            $this->exec_activities_cmd( $table, $last_insert );
        }else{
            $this->status->record($this->sql,$rawdata);
        }
        return $success;
    }
    
    /**
     * Main process function for the API entry point call back from the 
     * garmin service
     *
     * if unique_keys null will use required, but sometimes
     * you wnat to exclude some keys from required to determine uniqueness
     * of the rows, for example skip callbackURL
     *
     *    table: the table for the cache information
     *    insert_id: the cache_id to process inside table
     *    required: an array of fields that from the cache info to save as a column in the database. In addition, userId and userAccessToken will always be saved
     *    unique_keys: the array of columns to use in insert or update to ensure only one entry per values in these columns
     */
    function process($table, $insert_id, $required, $unique_keys = NULL ) {
        $this->ensure_schema();
        
        $this->status->clear($table);

        $end_points_fields = array(
            'userId' => 'VARCHAR(1024)',
            'userAccessToken' => 'VARCHAR(512)',
        );
        $cachetable = $this->cache_for_table($table);
        $rawdata = $this->sql->query_first_row(sprintf('SELECT json FROM %s WHERE cache_id = %d', $cachetable, $insert_id));
        if( ! isset($rawdata['json'] ) ){
            $this->status->error('Input from query appears empty' );
        }

        if( $this->status->success() ) {
            $data = json_decode($rawdata['json'],true);
            if( ! $data ) {
                $this->status->error( 'Failed to decode json' );
            }
            if( $this->status->success() ){
                $command_ids = array();
                $notification_ids = array();
                foreach( $data as $summary_type => $activities){
                    foreach( $activities as $activity){
                        $row = array();
                        // First build the insert row data with the end_points_fields
                        foreach( $end_points_fields as $key => $value ){
                            if( array_key_exists( $key, $activity ) ){
                                $row[ $key ] = $activity[$key];
                            }else{
                                $this->status->error( sprintf( 'Missing end point field %s', $key ) );
                            }
                        }

                        // Add to the row data all the required fields from the information from the cache
                        foreach( $required as $key ){
                            if( array_key_exists( $key, $activity ) ) {
                                $row[$key] = $activity[$key];
                            }else{
                                $this->status->error( sprintf( 'Missing required field %s', $key ) );
                            }
                        }

                        // if any fields not in required or end_points_fields save it as an extra json data for reference
                        $extra = array();
                        foreach( $activity as $key => $value ){
                            if( ! array_key_exists( $key, $required ) && ! array_key_exists( $key, $end_points_fields ) ){
                                $extra[$key] = $value;
                            }
                        }
                        
                        if( count( $extra ) > 0 ){
                            $row['json'] = json_encode( $extra ) ;
                        }

                        $callbackURL = false;
                        if( isset( $row['callbackURL'] ) ){
                            $callbackURL = true;
                        }

                        // Save the processed data in the database
                        if( $this->status->success() ){
                            if( ! $this->sql->insert_or_update($table, $row, ($unique_keys != NULL ? $unique_keys : $required) ) ) {
                                $this->status->error( sprintf( 'SQL error %s', $this->sql->lasterror ) );
                            }
                        }

                        // Now save a link between the data and the cache_id for reference later
                        if( $this->status->success() ){
                            $table_insertid = $this->sql->insert_id();
                            // only if new insert (>0) else it was an update and no need to save
                            if( $table_insertid > 0 ){
                                $cache_map_key = NULL;
                                if( $table == 'activities' ){
                                    $cache_map_key = 'activity_id';
                                    array_push( $notification_ids, $table_insertid );
                                }else if( $table == 'fitfiles' ){
                                    $cache_map_key = 'file_id';
                                }
                                if( $cache_map_key ){
                                    $cache_map_table = sprintf( '%s_map', $cachetable );
                                }
                                if( !$this->sql->insert_or_update( $cache_map_table, [ $cache_map_key => $table_insertid, 'cache_id' => $insert_id ] ) ){
                                    $this->status->error( sprintf( 'SQL error %s', $this->sql->lasterror ) );
                                }
                            }
                        }
                       

                        // If callback, record ids that will need to be process in new command
                        if( $this->status->success() ){
                            if( $callbackURL ) {
                                // If it has a callback URL, find out the file_id for matching summaryId so we can
                                // later start a command to get the data from the callback url
                                $found = $this->sql->query_first_row( sprintf( "SELECT file_id FROM `%s` WHERE summaryId = '%s'", $table, $row['summaryId'] ) );
                                if( $found ){
                                    array_push( $command_ids, $found['file_id'] );
                                }
                            }
                        }
                        if( $this->status->success() ){
                            $this->maintenance_after_process($table, $row, $extra);
                        }
                    }
                    if( $command_ids ){
                        $this->exec_callback_cmd( $table, $command_ids );
                    }
                    if( $notification_ids ){
                        $this->exec_notification_cmd( $table, $notification_ids );
                    }
                }
            }
        }
        $rv = $this->status->success();

        if( $this->status->hasError() ) {
            $this->status->record($this->sql,$rawdata);
        }else{
            $query = sprintf( 'UPDATE %s SET processed_ts = FROM_UNIXTIME(%d) WHERE cache_id = %d', $cachetable, time(), $insert_id );
            $this->sql->execute_query( $query );
        }
        return $rv;
    }

    /**
     *  execute a command and log output in a file
     *  Based on the configuration this function will try to:
     *      - run the command in a queue, 
     *      - start new queues if non appear running
     *  If no queue configured it will run the command in the background (not recommended)
     */
    function exec( $command, $logfile ){
        if( $this->use_queue ){
            $queue = new Queue();
            $queue->add_task( $command, getcwd() );
            if( $this->verbose ){
                $this->log( 'QUEUE',  'Add task `%s`', $command );
            }
            if( file_exists( '../queue/queuectl.php' ) ){
                $file_lock = sprintf( '%s/start_check_lock', $this->maintenance_writable_path('log') );
                if( ! file_exists( $file_lock ) || abs( time() - filemtime( $file_lock ) ) > 5 ){
                    $start_check_log = sprintf( '%s/start_check_queue.log', $this->maintenance_writable_path('log') );
                    if( $this->verbose ){
                        $this->log( 'QUEUE',  'Checking queue is started, last check %d secs ago. Log in %s', abs( time() - filemtime( $file_lock ) ), $start_check_log );
                    }
                    touch( $file_lock );
                    chmod( $file_lock, 0775 );
                    touch( $start_check_log );
                    chmod( $start_check_log, 0775 );
                    exec( sprintf( '(cd ../queue;php queuectl.php start) > %s &', $start_check_log ) );
                }else{
                    if( $this->verbose ){
                        $this->log( 'QUEUE',  'Start Skip: last start %d secs ago', abs( time() - filemtime( $file_lock ) ) );
                    }
                }
            }else{
                $this->log( 'ERROR',  'Start queue failed, ../queue/queuectl.ph not found in %s', getcwd() );
            }
        }else{
            if( $this->verbose ){
                $this->log( 'RUN', $command );
            }
            exec( sprintf( '%s > %s 2>&1 &', $command ) );
        }
    }
    
    function exec_activities_cmd( $table, $last_insert_id ){
        $logpath = $this->maintenance_writable_path( 'log' );
        if( is_writable( $logpath ) ){
            $logfile = sprintf( '%s/process_%s_%d_%s.log', $logpath, $table, $last_insert_id, strftime( '%Y%m%d_%H%M%S',time() ) );
            $command = sprintf( 'php run%s.php %d', $table, $last_insert_id );
        }else{
            $logfile = '/dev/null';
            $command = sprintf( 'php run%s.php %d ', $table, $last_insert_id );
        }
        if( $this->verbose ){
            $this->log( 'EXEC', $command );
        }
        $this->exec( $command, $logfile );
    }

    /**
     *  run the callback command for a list of file ids. 
     *  this is called by the main process function to start
     *  all the callback for the ping received by the service
     * 
     *  command_ids are the id from for the table containing the url to callback(typically `file_id` in `fitfiles`)
     * 
     *  If the size of the requests are too large it will break it into severacl chunk and execute each on the queue
     */
    function exec_callback_cmd( $table, $command_ids, $cmd = 'php runcallback.php %s %s' ){
        if( count($command_ids) > 25 ){
            $chunks = array_chunk( $command_ids, 5 );
        }else{
            $chunks = array( $command_ids );
        }
        foreach( $chunks as $chunk ){
            $file_ids = implode( ' ', $chunk );

            $command = sprintf( $cmd, $table, $file_ids );
            if( is_writable( 'log' ) ){
                $logfile = str_replace( ' ', '_', sprintf( 'log/callback-%s-%s-%s', $table, substr($file_ids,0,10),substr( hash('sha1', $command ), 0, 8 ) ) );
            }else{
                $logfile = '/dev/null';
            }
            if( $this->verbose ){
                $this->log( 'EXEC',  $command );
            }
            $this->exec( $command, $logfile );
        }
    }

    function exec_notification_cmd( $table, $notification_ids ){
        # if apn url defined, send notifictaion
        if( isset( $this->api_config['apn_url'] ) ){
            $this->exec_callback_cmd( $table, $notification_ids, 'php ../notifications/activity.php %s %s' );
        }else{
            if( $this->verbose ) {
                $this->log( 'INFO', 'Notification not setup, skipping' );
            }
        }
    }
    function authorization_header_for_token_id( $full_url, $token_id ){
        $row = $this->sql->query_first_row( "SELECT * FROM tokens WHERE token_id = $token_id" );
        return $this->authorization_header( $full_url, $row['userAccessToken'], $row['userAccessTokenSecret'] );
    }

    function interpret_authorization_header( $header ){
        $maps = array();
        $split = explode( ', ', str_replace( 'OAuth ', '', $header ) );

        foreach( $split as $def ) {
            $sub = explode('=', str_replace( '"', '', $def ) );
            if( count( $sub ) > 0 ){
                $maps[ $sub[0] ] = $sub[1];
            }
        }
        return $maps;
    }

    /**
     *   This function will check that the call has been authorised with the internal key
     *   of the server. This should be called to valid any system call or maintenance calls.
     */
    function authenticate_system_call(){
        $failed = true;
        
        $full_url = sprintf( '%s://%s%s', $_SERVER['REQUEST_SCHEME'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
        if( isset( apache_request_headers()['Authorization'] ) ){
            $header = apache_request_headers()['Authorization'];

            $maps = $this->interpret_authorization_header( $header );
            if( isset( $maps['oauth_token'] ) && isset( $maps['oauth_nonce'] ) && isset( $maps['oauth_signature'] ) ){
                $reconstructed = $this->authorization_header( $full_url, $this->api_config['serviceKey'], $this->api_config['serviceKeySecret'], $maps['oauth_nonce'], $maps['oauth_timestamp'] );
                $reconstructed = str_replace( 'Authorization: ', '', $reconstructed );
                $reconmaps = $this->interpret_authorization_header( $reconstructed );
                // Check if token id is consistent with the token id of the access token

                if( urldecode($reconmaps['oauth_signature']) == urldecode($maps['oauth_signature']) ){
                    $failed = false;
                }
            }
        }

        if( $failed ){
            if( $this->verbose ){
                $this->log( 'ERROR', 'authorization failed' );
            }else{
                header('HTTP/1.1 401 Unauthorized error');
            }
            die;
        }
    }

    /**
     *   Will check that the token_id is consistent with the authorization
     *   header for the secret recorded in the database via oauth 1.0
     *   In case of inconsistency it will terminate with a 401 unauthorized error
     *
     *   This function should be called before any processing of data from the database
     *   is returned for protection of the user data
     */
    function authenticate_header($token_id){
        $failed = true;

        // This should never be called with system token
        if( $token_id == Paging::SYSTEM_TOKEN ){
            if( $this->verbose ){
                $this->log( 'ERROR', 'authorization failed' );
            }else{
                header('HTTP/1.1 401 Unauthorized error');
            }
            die;
        }
        
        $full_url = sprintf( '%s://%s%s', $_SERVER['REQUEST_SCHEME'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
        if( isset( apache_request_headers()['Authorization'] ) ){
            $header = apache_request_headers()['Authorization'];

            $maps = $this->interpret_authorization_header( $header );
            if( isset( $maps['oauth_token'] ) && isset( $maps['oauth_nonce'] ) && isset( $maps['oauth_signature'] ) ){
                $userAccessToken = $maps['oauth_token'];
                $row = $this->sql->query_first_row( "SELECT userAccessTokenSecret,token_id FROM tokens WHERE userAccessToken = '$userAccessToken'" );
                if( isset( $row['token_id'] ) ){
                    $reconstructed = $this->authorization_header( $full_url, $userAccessToken, $row['userAccessTokenSecret'], $maps['oauth_nonce'], $maps['oauth_timestamp'] );
                    $reconstructed = str_replace( 'Authorization: ', '', $reconstructed );
                    $reconmaps = $this->interpret_authorization_header( $reconstructed );
                    // Check if token id is consistent with the token id of the access token


                    if( urldecode($reconmaps['oauth_signature']) == urldecode($maps['oauth_signature']) && $row['token_id'] == $token_id ){
                        $failed = false;
                    }
                }
            }
        }
        if( $failed ){
            if( $this->verbose ){
                $this->log( 'ERROR', 'authorization failed' );
            }else{
                header('HTTP/1.1 401 Unauthorized error');
            }
            die;

        }
    }

    /**
     *   Compute the authorization header using the oauth 1.0 method
     *   nonce and timestamp can be specified to verify an existing signature
     *   otherwise they will be generated
     */
    function authorization_header( $full_url, $userAccessToken, $userAccessTokenSecret, $nonce = NULL, $timestamp = NULL){
        $consumerKey = $this->api_config['consumerKey'];;
        $consumerSecret = $this->api_config['consumerSecret'];
    
        $url_info = parse_url( $full_url );

        $get_params = array();
        if( isset( $url_info['query'] ) ){
            parse_str( $url_info['query'], $get_params );
        }

        $url = sprintf( '%s://%s%s', $url_info['scheme'], $url_info['host'], $url_info['path'] );

        if( $nonce == NULL ){
            $nonce = bin2hex(random_bytes( 16 ));
        }
        if( $timestamp == NULL ){
            $timestamp = (string)round(microtime(true) );
        }

        $method = 'GET';

        $signatureMethod = 'HMAC-SHA1';
        $version = '1.0';

        $oauth_params = array(
            'oauth_consumer_key' => $consumerKey,
            'oauth_token' => $userAccessToken,
            'oauth_nonce' => $nonce,
            'oauth_signature_method' => 'HMAC-SHA1',
            'oauth_timestamp' => $timestamp,
            'oauth_version' => $version
        );
        $all_params = array_merge( $oauth_params, $get_params );
        $params_order = array_keys( $all_params );
        sort($params_order);

        $base_params = array();

        foreach($params_order as $param) {
            array_push( $base_params, sprintf( '%s=%s', $param, $all_params[$param]) );
        }

        $base = sprintf( '%s&%s&%s', $method, rawurlencode($url), rawurlencode(implode('&',$base_params) ) );

        $key = rawurlencode($consumerSecret) . '&' . rawurlencode($userAccessTokenSecret);
        $oauth_params['oauth_signature'] = base64_encode(hash_hmac('sha1', $base, $key, true));

        $header_params = array_keys($oauth_params);
        sort($header_params);
        $headers = array();
        foreach( $header_params as $param) {
            array_push( $headers, sprintf( '%s="%s"', $param, rawurlencode($oauth_params[$param] ) ) );
        }

        $header = sprintf( 'Authorization: OAuth %s', implode(', ', $headers) );

        return $header;
    }

    /**
     *    Execute a curl query by adding a oauth 1.0 signature for the
     *    corresponding userAccessToken and tokenSecret
     */
    function get_url_data($url, $userAccessToken, $userAccessTokenSecret){
        $ch = curl_init();

        curl_setopt($ch, CURLOPT_URL, $url );
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1 );
        if( $userAccessToken && $userAccessTokenSecret ){
            $headers = [ $this->authorization_header( $url, $userAccessToken, $userAccessTokenSecret ) ];
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers );
            if ($this->verbose) {
                $this->log('CURL', 'AuthToken: %s URL: %s', $userAccessToken, $url);
            }
        }else{
            if ($this->verbose) {
                $this->log('CURL', '%s', $url);
            }
        }

        $data = curl_exec($ch);

        if( $data === false ) {
            $this->status->error( sprintf( 'CURL Failed %s', curl_error($ch ) ) );
        }
        curl_close($ch);
        return $data;
    }

    /**
     *   This will validate that the token id exists. 
     *   It will return all the information on the token if exist with
     *   all secrets removed
     */
    function validate_user( $token_id ){
        $user = $this->user_info_for_token_id($token_id);
        unset( $user['userAccessTokenSecret'] );

        return $user;
    }

    /**
     *   Validate input from the GET parameters for a database id
     *   It should be called for any parameter passed to an sql query as an id
     *   to protect injection attacks
     */
    function validate_input_id( $val ) : int {
        return intval($val);
    }
    
    /**
     *   Validate input from the GET parameters for a token
     *   It should be called for any parameter passed to an sql query as an token
     *   to protect injection attacks
     */
    function validate_token( $val ) : string {
        return filter_var( $val, FILTER_VALIDATE_REGEXP, array('options' => array( 'regexp' => '/^([-A-Za-z0-9]+)$/' ) ) );
    }

    /**
     *   Validate input from the GET parameters for a valid url
     *   It should be called for any parameter passed to an sql query as an url
     *   to protect injection attacks
     */
    function validate_url( $val ) : string {
        return filter_var( $val, FILTER_VALIDATE_URL );
    }

    /**
     *   Validate the data is a valid fit file by looking at
     *   expected bytes in header
     */
    function validate_fit_file( $data, $fileType ){
        if( $fileType == 'tcx' || $fileType == 'gpx' ){
            return( substr( $data, 0, 5 ) == '<?xml' );
        }
        if( strlen($data) < 13 ){
            return false;
        }
        $rv = unpack( 'c3', $data, 9 );
        // Equal to 'fit'
        return ( $rv[1] == 70 && $rv[2] == 73 && $rv[3] == 84 );
    }

    /**
     * Check that conditions are met to do extract of the fit file
     *   1. the file is not too old, Garmin Health sometimes sends very old file triggered by external backfill, 
     *      to protect against that we check that the file is more recent thant 'ignore_fitextract_hours_threashold'
     *   2. some user never deregister but don't use the service anymore, so also check if the user is active
     */
    function should_fit_extract( $file_id ){
        $query = sprintf( 'select file_id,cs_user_id,startTimeInSeconds,fileType FROM fitfiles WHERE file_id = %d', $file_id );
        $should = $this->sql->query_first_row( $query );
        if( isset( $should['fileType'] ) && strtolower( $should['fileType'] ) != 'fit' ){
            if( $this->verbose ){
                $this->log( 'INFO', "Skipping extract of non fit file %s", $should['fileType'] );
            }
            return false;
        }
        
        if( isset($should['startTimeInSeconds']) ){
            if( ! $this->ignore_time_threshold( intval( $should['startTimeInSeconds'] ), 'ignore_fitextract_hours_threshold', NULL ) ){
                if( isset( $should['cs_user_id'] ) ){
                    return $this->user_is_active( intval( $should['cs_user_id'] ) );
                }
            }
        }
        return false;
    }
    
    /**
     *  Extract and save information from downloaded fit files
     *  if darkSkyNet key exists, try to download weather
     *  Note that if the updated activity is older than $max_hours, 
     *  the weather won't be uploaded
     *  Note2: fit_mesgs are passed in as php arrays and assume fit parsing was done before
     *         so that this library does not need to include the fit library
     */
    function fit_extract( $file_id, $fit_mesgs ){
        // First see if we have correct file_id
        $query = sprintf( 'SELECT file_id,cs_user_id FROM fitfiles WHERE file_id = %d', $file_id );
        $user = $this->sql->query_first_row( $query );
        if( isset( $user['cs_user_id'] ) ){
            $cs_user_id = intval( $user['cs_user_id'] );

            $this->ensure_schema();
                
            // Can we get gps positions?
            if( isset( $fit_mesgs['session']['start_position_lat'] ) &&
                isset( $fit_mesgs['session']['start_position_long'] ) &&
                isset( $fit_mesgs['session']['timestamp'] ) &&
                isset( $fit_mesgs['session']['start_time'] ) ){
                $lat = $fit_mesgs['session']['start_position_lat'] ;
                $lon = $fit_mesgs['session']['start_position_long'] ;
                $ts =  $fit_mesgs['session']['timestamp'];
                $st =  $fit_mesgs['session']['start_time'];

                // We will only query weather for activities that were done less than 24h ago
                // This is to avoid blast update during backfill
                if( $lat != 0.0 && $lon != 0.0 &&
                    ! $this->ignore_time_threshold( intval($ts), 'ignore_fitextract_hours_threshold', NULL ) ) {
                    $weather = array();
                    if( isset( $this->api_config['darkSkyKey'] ) ){
                        $darkSky = $this->weather_query_darkSky( $this->api_config['darkSkyKey'], $lat, $lon, $st, $ts );
                        if( $darkSky ){
                            $weather['darkSky'] = $darkSky;
                        }
                    }
                    if( isset( $this->api_config['visualCrossingKey'] ) ){
                        $visualCrossing = $this->weather_query_visualCrossing( $this->api_config['visualCrossingKey'], $lat, $lon, $st, $ts );
                        if( $visualCrossing ){
                            $weather['visualCrossing'] = $visualCrossing;
                        }
                    }

                    if( isset( $this->api_config['openWeatherMapKey'] ) ){
                        $openWeatherMap = $this->weather_query_openWeatherMap( $this->api_config['openWeatherMapKey'], $lat, $lon, $st, $ts );
                        if( $openWeatherMap ){
                            $weather['openWeatherMap'] = $openWeatherMap;
                        }
                    }

                    $this->sql->insert_or_update('weather', array('cs_user_id' => $cs_user_id, 'file_id' => $file_id, 'json' => json_encode($weather)), array('file_id'));
                }
            }
            if( isset( $fit_mesgs['session'] ) ){
                $fitdata = json_encode($fit_mesgs['session'] );
                $this->sql->insert_or_update( 'fitsession', array( 'cs_user_id'=>$cs_user_id, 'file_id'=> $file_id,'json'=>$fitdata ), array( 'file_id' ) );
            }
        }
    }
    function weather_query_visualCrossing( $key, $lat, $lon, $st, $ts )
    {
        $datefmt = '%Y-%m-%dT%H:%M:%S';
        $url = sprintf('https://weather.visualcrossing.com/VisualCrossingWebServices/rest/services/weatherdata/history?aggregateHours=1&combinationMethod=aggregate&collectStationContributions=false&maxStations=-1&maxDistance=-1&includeNormals=false&contentType=json&unitGroup=metric&locationMode=single&locations=%f,%f&startDateTime=%s&endDateTime=%s&key=%s&iconSet=icons1',
                       $lat, $lon, $st,  $ts, $key);
        $weather = array();

        $data = $this->get_url_data($url, NULL, NULL);
        if ($data === false) {
            $this->status->error(sprintf('CURL Failed %s', curl_error($ch)));
        } else {
            $weather = json_decode($data, true);
            // Save space, no need to keep name of columns 
            if( isset( $weather['columns'] ) ){
                unset( $weather['columns'] );
            }
        }
        return ($weather);
    }
    
    function weather_query_openWeatherMap( $key, $lat, $lon, $st, $ts )
    {
        // https://api.openweathermap.org/data/2.5/onecall/timemachine?lat=60.99&lon=30.9&dt=1598373599&appid={KEY}
        $url = sprintf('https://api.openweathermap.org/data/2.5/onecall/timemachine?lat=%f&lon=%f&dt=%d&appid=%s&units=metric',$lat, $lon, $st,  $key );
        $weather = array();

        $data = $this->get_url_data($url, NULL, NULL);
        if ($data === false) {
            $this->status->error(sprintf('CURL Failed %s', curl_error($ch)));
        } else {
            $weather = json_decode($data, true);
            $keep_hourly = array();
            if (isset($weather['hourly'])) {
                foreach ($weather['hourly'] as $one) {
                    $cur = $one['dt'];
                    $inside = ($cur > ($st - 3600.0)) && ($cur < ($ts + 3600.0));
                    if ($inside) {
                        array_push($keep_hourly, $one);
                    }
                }
                $weather['hourly'] = $keep_hourly;
            }
        }
        return ($weather);
    }

    function weather_query_darkSky( $key, $lat, $lon, $st, $ts )
    {
        $url = sprintf('https://api.darksky.net/forecast/%s/%f,%f,%d?units=si&exclude=minutely,flags,alerts,daily', $key, $lat, $lon, $st);
        $weather = array();

        $data = $this->get_url_data($url, NULL, NULL );
        if ($data === false) {
            $this->status->error(sprintf('CURL Failed %s', curl_error($ch)));
        } else {
            $weather = json_decode($data, true);
            $keep_hourly = array();
            if (isset($weather['hourly']['data'])) {
                foreach ($weather['hourly']['data'] as $one) {
                    $cur = $one['time'];
                    $inside = ($cur > ($st - 3600.0)) && ($cur < ($ts + 3600.0));
                    if ($inside) {
                        array_push($keep_hourly, $one);
                    }
                }
                $weather['hourly']['data'] = $keep_hourly;
            }
        }

        return ($weather);
    }

    /**
     *    This function is called in the background
     *    It will query the Garmin API for each file that needs to be downloaded
     *    and if necessary will extract information from the fit file
     *    will be called for table fitfiles, cbids will be file_id of the table (fitfiles)
     */
    function run_file_callback( string $table, array $cbids ){
        $this->ensure_schema();
        foreach( $cbids as $cbid ){
            $this->file_callback_one( $table, $cbid );
        }

        $command = sprintf( 'php runfitextract.php %s', implode(' ', $cbids ) );
        if( $this->verbose ){
            $this->log( 'EXEC', $command );
        }
        $retval = 0;
        system( $command, $retval );
         if( $retval != 0 && $this->verbose){
            $this->log( 'ERROR','ret=%d for %s', $retval, $command );
        }
    }

    function file_path_for_file_row( $row ){
        if( isset( $row['fileType'] ) ){
            $fileType = strtolower( $row['fileType'] );
        }else{
            $fileType = 'fit';
        }
            
        $fnamebase = sprintf( '%s.%s', $row['file_id'], $fileType );
            
        if( isset( $row['cs_user_id'] ) ){
            $pathdir = sprintf( "assets/users/%s/%s", $row['cs_user_id'], $fileType);
        }else{
            $pathdir = sprintf( "assets/users/%s/%s", $row['userId'],$fileType );
        }
        $path = sprintf( '%s/%s', $pathdir, $fnamebase );

        return $path;
    }
    
    function ignore_time_threshold( $time, $hours_config_tag, $months_config_tag ){
        $hours_threshold = 0;
        $months_threshold = 0;
        if( $months_config_tag && isset( $this->api_config[$months_config_tag] ) ){
            $months_threshold = intval( $this->api_config[$months_config_tag] );
        }
        if( $hours_config_tag && isset( $this->api_config[$hours_config_tag] ) ){
            $hours_threshold = intval( $this->api_config[$hours_config_tag] );
        }

        if( $hours_threshold > 0 || $months_threshold > 0 ){
            $time_threshold = time() - ( $months_threshold * 30*24*3600 ) - ($hours_threshold * 3600);
            if( $time < $time_threshold ){
                if( $this->verbose ){
                    $this->log( 'WARNING','time %s older than threshold %s, skipping for %s %s',
                            date("Y-m-d", $time),
                            date("Y-m-d", $time_threshold ),
                            $hours_config_tag ?? '',
                            $months_config_tag ?? ''
                    );
                }
                return true;
            }else{
                if( $this->verbose ){
                    $this->log( 'INFO','time %s within threshold %s, proceeding for %s %s',
                            date("Y-m-d", $time),
                            date("Y-m-d", $time_threshold ),
                            $hours_config_tag ?? '',
                            $months_config_tag ?? ''
                    );
                }
            }

        }
        return false;
    }

    /**
     *   Run one callback on table (typically fitfiles) and cbid (file_id)
     *   Will download the file and if successfull save it in assets table
     */
    function file_callback_one( $table, $cbid ){
        
        if( isset($this->api_config['save_to_s3_bucket'] ) ){
            $save_to_s3 = $this->api_config['save_to_s3_bucket'];
        }else{
            $save_to_s3 = false;
        }
        
        $this->status->clear('assets');

        $query = sprintf( "SELECT * FROM %s WHERE file_id = %s", $table, $cbid );
        $row = $this->sql->query_first_row( $query );
        if( ! $row ){
            $this->status->error( sprintf( 'sql error %s', $this->sql->lasterror ) );
        }
        
        if( $this->status->success() ){
            if( isset( $row['startTimeInSeconds'] ) && $this->ignore_time_threshold( intval( $row['startTimeInSeconds'] ), NULL, 'ignore_activities_months_threshold' ) ){
                return;
            }
            
            $callback_url = $row[ 'callbackURL' ];
            $callback_info = parse_url( $callback_url );
            $get_params = array();
            parse_str( $callback_info['query'], $get_params );

            $userAccessToken = $row[ 'userAccessToken' ];

            $user = $this->user_info( $userAccessToken );
            if( ! $user ){
                $this->status->error( "unregistered user for $userAccessToken" );
            }
            if( isset( $user['cs_user_id'] ) && intval( $user['cs_user_id'] ) > 0 ){
                if( ! $this->user_is_active( intval( $user['cs_user_id'] ) ) ){
                    return;
                }
            }

            if( isset( $row['fileType'] ) ){
                $fileType = strtolower( $row['fileType'] );
            }else{
                $fileType = 'fit';
            }

            $path = $this->file_path_for_file_row( $row );
            $fnamebase = basename( $path );
            $pathdir = dirname( $path );

            if( isset($user['userAccessTokenSecret'] ) ){
                $userAccessTokenSecret = $user['userAccessTokenSecret'];

                if( isset($row['userId']) && !isset($user['userId'])){
                    $this->sql->insert_or_update('tokens', array('userId'=>$row['userId'], 'token_id'=>$user['token_id']), array( 'token_id' ) );
                }
            
                $url = $callback_url;
                $ntries = 3;
                $nextwait = 1;
                $data = false;
                while( $ntries > 0 ){
                    $data = $this->get_url_data( $url, $userAccessToken, $userAccessTokenSecret );
                    if( $data ){
                        $validFormat = $this->validate_fit_file($data, $fileType);
                    }else{
                        $validFormat = false;
                    }
                    if( $data && $validFormat ){
                        $ntries = 0;
                    }else{
                        if( $this->verbose ){
                            if( $data && !$validFormat){
                                $this->log( "ERROR","Invalid file data for $fileType format for $cbid, sleeping $nextwait" );
                            }else{
                                $this->log( "ERROR","Failed to get callback data for $cbid, sleeping $nextwait" );
                            }
                        }
                        $data = false;
                        $ntries-=1;
                        if( $ntries > 0 ){
                            $this->sleep( $nextwait );
                            $nextwait *= 2;
                        }
                    }
                }

                if($data === false ){
                    if( $validFormat ){
                        $this->status->error( 'Failed to get data' );
                    }else{
                        $this->status->error( "Got invalid format data for $fileType" );
                    }

                    $row = array(
                        'tablename' => $table,
                        'file_id' => $cbid,
                        'message' => 'No Data for callback url',
                        'callbackURL' => $callback_url,
                        'userAccessToken' => $userAccessToken
                    );
                    $this->status->record($this->sql, $row );
                    if( $this->verbose ){
                        $this->log( "ERROR","Failed repeatedly to get callback data for $cbid, skipping" );
                    }
                }else{
                    if( $save_to_s3 ){
                        if( $this->verbose ){
                            $this->log( 'S3', 'save %s to bucket %s', $path, $save_to_s3 );
                        }
                        $this->save_to_s3_bucket($save_to_s3,$path,$data);
                        
                        $row = array(
                            'tablename' => $table,
                            'file_id' => $cbid,
                            'path' => sprintf( 's3:%s', $path ),
                            'filename' => $fnamebase,
                        );
                        $this->sql->insert_or_update( 'assets', $row, array( 'file_id', 'tablename' ) );
                        $assetid = $this->sql->insert_id();

                        $this->sql->insert_or_update( $table, array( 'file_id' => $cbid, 'asset_id' => $assetid ), array( 'file_id' ) );
                        
                    }else{
                        $exists = $this->sql->query_first_row(sprintf("SELECT asset_id FROM assets WHERE file_id=%s AND tablename='%s'",$cbid,$table));

                        if( $exists ){
                            $query = "UPDATE assets SET data=? WHERE file_id=? AND tablename=?";
                            $stmt = $this->sql->connection->prepare( $query );
                        }else{
                            $query = "INSERT INTO assets (data,file_id,tablename) VALUES(?,?,?)";
                            $stmt = $this->sql->connection->prepare( $query );
                        }
                        if( $stmt ){
                            if( $this->verbose ){
                                $this->log( 'EXECUTE', $query );
                            }
                            $null = NULL;
                            $stmt->bind_param('bis',$null,$cbid,$table );
                            $stmt->send_long_data(0,$data);
                            if (!$stmt->execute()) {
                                $this->status->error(  "Execute failed: (" . $stmt->errno . ") " . $stmt->error );
                                if( $this->verbose ){
                                    $this->log( 'ERROR', "%s [%s]", $stmt->error, $query);
                                }
                            }
                            $stmt->close();
                        }else{
                            $this->status->error( sprintf( 'Failed to prepare %s, %s', $query, $this->sql->lasterror ) );
                        }
                    }

                    if( $this->status->success() ){
                        $exists = $this->sql->query_first_row(sprintf("SELECT asset_id FROM assets WHERE file_id=%s AND tablename='%s'",$cbid,$table));
                        if( isset( $exists['asset_id'] ) ){
                            $this->sql->insert_or_update( $table, array( 'asset_id' => $exists['asset_id'], 'file_id'=> $cbid ), array( 'file_id' ) );
                        }else{
                            $this->status->error( 'Failed to get back asset_id' );
                        }
                    }
                }
            }
        }
        if( $this->status->hasError() ){
            $this->status->record( $this->sql, array( 'cbid' => $cbid, 'table'=>$table ) );
        }
    }


    function s3(){
        if( isset( $this->s3 ) ){
            return( $this->s3 );
        }

        
        if( isset( $this->api_config['s3_access_key'] ) && isset( $this->api_config['s3_secret_key'] ) ){
            $this->s3 = new S3($this->api_config['s3_access_key'], $this->api_config['s3_secret_key'] );
            $this->s3->setSignatureVersion('v4');
            if( isset( $this->api_config['s3_region'] ) ){
                $this->s3->setRegion($this->api_config['s3_region']);
            }
        }else{
            $this->s3 = NULL;
        }

        return( $this->s3 );
    }

    function retrieve_from_s3_cache_if_available( $path ){
        if( isset( $this->api_config['s3_cache_local'] ) && is_dir($this->api_config['s3_cache_local']) ){
            $local_cache_path = sprintf( '%s/%s', $this->api_config['s3_cache_local'], $path );
            if( is_file( $local_cache_path ) ){
                $data = file_get_contents( $local_cache_path );
                if( $this->verbose ){
                    $this->log( 'INFO', 'Read s3 file from local cache %s', $local_cache_path );
                }
                return $data;
            }
        }
        return NULL;
    }
    
    function save_to_s3_cache_if_applicable($path, $data ){
        if( isset( $this->api_config['s3_cache_local'] ) && is_dir($this->api_config['s3_cache_local']) ){
            $local_cache_path = sprintf( '%s/%s', $this->api_config['s3_cache_local'], $path );
            if( is_dir( dirname( $local_cache_path ) ) || mkdir( dirname( $local_cache_path ), 0755, true ) ){
                file_put_contents( $local_cache_path, $data );
                if( $this->verbose ){
                    $this->log( 'INFO', 'Saved s3 file to local cache %s', $local_cache_path );
                }
            }
        }
    }

    function remove_from_s3_cache( $path ){
        if( isset( $this->api_config['s3_cache_local'] ) && is_dir($this->api_config['s3_cache_local']) ){
            $local_cache_path = sprintf( '%s/%s', $this->api_config['s3_cache_local'], $path );
            if( is_file( $local_cache_path ) ){
                unlink( $local_cache_path );
            }
        }
    }
    function save_to_s3_bucket($bucket,$path,$data){
        if( substr($bucket, 0, 10) == 'localhost:' ){
            $basepath = substr($bucket,10);
            if( !$basepath ){
                $basepath = '.';
            }
            $fullpath = sprintf( '%s/%s', $basepath, $path );
            if( is_dir( dirname( $fullpath ) ) || mkdir( dirname( $fullpath ), 0755, true ) ){
                file_put_contents( $fullpath, $data );
            }
        }else{
            $s3 = $this->s3();
            if( $s3 ){
                $s3->putObject( $data, $bucket, $path );
            }
            $this->save_to_s3_cache_if_applicable($path, $data );
        }
    }

    function retrieve_from_s3_bucket($bucket,$path){
        $data = $this->retrieve_from_s3_cache_if_available( $path );
        if( $data ){
            return $data;
        }
        if( substr($bucket, 0, 10) == 'localhost:' ){
            $basepath = substr($bucket,10);
            if( !$basepath ){
                $basepath = '.';
            }
            $data = file_get_contents( sprintf( '%s/%s', $basepath, $path ) );
            return $data;
        }else{
            $s3 = $this->s3();
            if( $s3 ){
                $response = $s3->getObject( $bucket, $path );
                if( isset( $response->body ) ){
                    $data = $response->body;
                    $this->save_to_s3_cache_if_applicable($path, $data );
                }else{
                    $data = NULL;
                }
            }else{
                $data = NULL;
            }
            return $data;
        }
    }

    function backup_from_s3_bucket($bucket,$path,$s3_bucket){
        
        if( substr($bucket, 0, 10) != 'localhost:' || substr($s3_bucket, 0, 10) == 'localhost:' ){
            $this->log( 'ERROR', 'Expecting to backup s3 bucket (got %s) to a local bucket (got %s)', $s3_bucket, $bucket );
            die;
        }
        
        $basepath = substr($bucket,10);
        if( !$basepath ){
            $basepath = '.';
        }
            
        // Get the remote object
        $s3 = $this->s3();
        if( $s3 ){
            $response = $s3->getObject( $s3_bucket, $path );
            $data = $response->body;
            $target_file = sprintf( '%s/%s', $basepath, $path );
            $target_dir = dirname( $target_file );
            if( ! is_dir( $target_dir ) ){
                mkdir( $target_dir, 0755, true );
            }
            $bytes = file_put_contents( $target_file, $data );
            if( $bytes=== false ){
                $this->log( 'ERROR', 'Failed to write file %s (%s/%s)', $target_file, $bytes, strlen( $data ) );
            }
        }
    }

    function maintenance_backup_asset_from_s3($limit = 2){
        if( !isset( $this->api_config['backup_from_s3_bucket'] ) ){
            $this->log( 'INFO', 'skipping backup from s3 bucket because not setup in config' );
            return;
        }
        $save_to_bucket = $this->api_config['save_to_s3_bucket'];
        $backup_from_bucket = $this->api_config['backup_from_s3_bucket'];

        $this->create_active_users();
        
        $this->log( 'INFO','Backing up from %s to %s', $backup_from_bucket, $save_to_bucket );

        $query = 'SELECT `path` FROM `assets` a, `fitfiles` f, `users_active` u WHERE `path` IS NOT NULL AND f.asset_id = a.asset_id AND u.cs_user_id = f.cs_user_id AND u.last_ts > NOW() - INTERVAL 45 DAY ORDER BY a.asset_id DESC';

        $found = $this->sql->query_as_array($query);
        $this->log( 'INFO', 'found %d', count( $found ) );

        $done = 0;
        $already = 0;
        
        $local_dir = substr( $save_to_bucket, 10 );
        if( ! is_dir( $local_dir ) ){
            $this->log( 'ERROR', 'backup target %s is not a directory', $local_dir );
            die;
        }

        foreach( $found as $row ){
            $full_path = $row['path'];
            $path = substr($full_path, 3 );
            $s3 = substr( $full_path, 0, 3 );
            
            if( $s3 == 's3:' ){
                $local_file = sprintf( '%s/%s',  $local_dir, $path);
                if( is_file( $local_file ) ){
                    $already += 1;
                }else{
                    if( $limit < 10 || $done % ( $limit/10 ) == 0){
                        $this->log( 'INFO', 'Saving %s to %s [%d/%d previous %d]', $path, $local_file, $done, $limit, $already );
                    }
                    $this->backup_from_s3_bucket($save_to_bucket, $path, $backup_from_bucket );
                    $done += 1;
                }
            }
            if( $done > $limit ){
                break;
            }
        }
        printf( 'Done %d/%d (this run %d, previous run %d)'.PHP_EOL, $done + $already, count( $found ), $done, $already );
    }
    
    function sleep( $seconds ){
        sleep( $seconds );
        // After sleep, sql connect likely to time out
        $this->sql = new garmin_sql();
        $this->sql->verbose = $this->verbose;
        $this->sql->start_ts = $this->start_ts;
    }
    
    function maintenance_after_process($table,$row,$json){
        $to_set = array();
        $other_to_set = array();

        // if multi sport sub activity

        if( $table == 'activities' ){
            if( isset( $json['parentSummaryId'] ) ){
                $query = sprintf( "SELECT activity_id FROM activities WHERE summaryId = '%s'", intval($json['parentSummaryId']) );
                $parentrow = $this->sql->query_first_row( $query );
                if( isset( $parentrow['activity_id'] ) ){
                    $parent_id = $parentrow['activity_id'];
                    $this->sql->execute_query( sprintf( "UPDATE activities SET parent_activity_id = %d WHERE summaryId = '%d'", $parent_id, $row['summaryId'] ) );
                }
            }

            if( isset( $json['isParent'] ) && intval($json['isParent']) == 1 ){
                if( isset( $json['startTimeInSeconds'] ) && isset( $json['startTimeInSeconds'] ) && isset( $row['userAccessToken'] ) ){
                    $startTime = intval($json['startTimeInSeconds']);
                    $endTime = $startTime + intval($json['durationInSeconds']);
                    $query = sprintf( "SELECT activity_id,json,parent_activity_id FROM activities WHERE startTimeInSeconds >= %d AND startTimeInSeconds <= %d AND userAccessToken = '%s'",  $startTime, $endTime, $row['userAccessToken'] );
                    $found = $this->sql->query_as_array( $query );

                    $parent_row = $this->sql->query_first_row( sprintf( "SELECT activity_id FROM `%s` WHERE summaryId = '%s'", $table, $row['summaryId'] ) );
                    if( isset( $parent_row['activity_id'] ) ){
                        foreach( $found as $child_row ){
                            // only check what does not have already parent_activity_id
                            if( !isset( $child_row['parent_activity_id'] ) ){
                                $child_json = json_decode( $child_row['json'], true );

                                if( isset( $child_json['parentSummaryId'] ) && $child_json['parentSummaryId'] == $json['summaryId'] ){
                                    $query = sprintf( "UPDATE `%s` SET parent_activity_id=%d WHERE activity_id = '%s'",  $table, $parent_row['activity_id'], $child_row['activity_id'] );
                                    if( ! $this->sql->execute_query( $query ) ){
                                        $this->log( "ERROR", $this->sql->lasterror );
                                    }
                                }
                            }
                        }
                    }
                }
            }
        }
        //
        // First see if we can update users
        //
        $cs_user_id = NULL;
        if( isset( $row['summaryId'] )){
            $fullrow = $this->sql->query_first_row( sprintf( "SELECT * FROM `%s` WHERE summaryId = '%s'", $table, $row['summaryId'] ) );
            if( $fullrow['cs_user_id'] ){
                $cs_user_id = $fullrow['cs_user_id'];
            }
            if( !$fullrow['cs_user_id'] && isset( $row['userAccessToken'] ) && isset( $row['userId'] ) ){
            
                $userInfo = $this->user_info($row['userAccessToken']);
                if( isset( $userInfo['token_id'] ) ){
                    $token_id = $userInfo['token_id'];
            
                    if( ! isset( $userInfo['cs_user_id'] ) ){
                        // Check if use exists
                        $userId = $row['userId'];
                        $prev = $this->sql->query_first_row( "SELECT userId FROM users WHERE userId = '$userId'" );
                        if( ! $prev ){
                            $this->sql->insert_or_update( 'users', array( 'userId' => $userId ) );
                            $cs_user_id = $this->sql->insert_id();
                            $this->sql->execute_query( "UPDATE tokens SET cs_user_id = $cs_user_id, userId = '$userId' WHERE token_id = $token_id" );
                        }
                    }else{
                        $cs_user_id = $userInfo['cs_user_id'];
                    }

                    array_push( $to_set, sprintf( 'cs_user_id = %d', $cs_user_id ) );
                }
            }

            //
            //
            // Link activities / fitfiles on userId, startTimeInSeconds
            if( $table == 'activities' ){
                $other_table = 'fitfiles';
                $other_key = 'file_id';
                $table_key = 'activity_id';
            }else if( $table == 'fitfiles' ) {
                $other_table = 'activities';
                $other_key = 'activity_id';
                $table_key = 'file_id';
            }else{
                $other_table = NULL;
            }

            // If we have userId and startTimeInSeconds, check if we find a row for the same in the other table
            if( $other_table && (isset( $row['userId'] ) && isset( $row['startTimeInSeconds' ] ) ) ){
                $query = sprintf( "SELECT * FROM `%s` WHERE userId = '%s' AND startTimeInSeconds = %d", $other_table, $row['userId'], $row['startTimeInSeconds'] );
                $found = $this->sql->query_as_array( $query );

                // If we found 1, that means we should link them
                if( count( $found ) == 1 ){
                    $found = $found[0];
                    if( $this->verbose ){
                        $this->log( 'INFO', 'Found link between for %s.%s=%s and %s.%s=%s', $table, $table_key, $fullrow[$table_key], $other_table, $other_key, $found[$other_key] );
                    }
                    $nothing_to_do = 1;
                    // check if the other table is missing the link to this one, 
                    if( ! $found[$table_key] ){
                        if( $fullrow[$table_key] ){
                            $nothing_to_do = 0;
                            $query = sprintf( 'UPDATE `%s` SET %s = %d WHERE %s = %d', $other_table, $table_key, $fullrow[$table_key], $other_key, $found[$other_key] );
                            $this->sql->execute_query( $query );
                        }
                    }
                    // check if the table is missing the link to other, 
                    if( ! $fullrow[$other_key] ){
                        $nothing_to_do = 0;
                        array_push( $to_set, sprintf( '%s = %d', $other_key, $found[$other_key] ) );
                    }

                    if( $nothing_to_do && $this->verbose ){
                        $this->log( 'INFO', 'Links already done for %s.%s=%s and %s.%s=%s', $table, $table_key, $fullrow[$table_key], $other_table, $other_key, $found[$other_key] );
                    }
                }else{
                    if( $this->verbose ){
                        $this->log( 'INFO', 'Nothing found to link for %s.%s=%s in %s', $table, $table_key, $fullrow[$table_key], $other_table );
                    }
                }
            }
        
            if( count( $to_set ) ){
                $query = sprintf( "UPDATE `%s` SET %s WHERE summaryId = '%s'",  $table, implode( ', ', $to_set), $row['summaryId'] );
                if( ! $this->sql->execute_query( $query ) ){
                    $this->log( "ERROR",  $this->sql->lasterror );
                }
            }
        }
    }

    function maintenance_process_old_cache($table,$limit=20){
        $query = sprintf( 'SELECT cache_id,ts,started_ts,timediff(now(),started_ts),now() FROM cache_%s WHERE processed_ts is null AND timediff(now(),started_ts)>3600 ORDER BY started_ts DESC LIMIT %d',
                          $table, $limit );
        $res = $this->sql->query_as_array( $query );
        foreach( $res as $row ){
            $this->exec_activities_cmd( $table, $row['cache_id'] );
        }
    }
    
    // This will update cs_user_id in table by matching userId from the service data
    function maintenance_link_cs_user($table,$limit=20){
        $this->ensure_schema();
        
        $res = $this->sql->query_as_array( "SELECT userId,COUNT(userId) FROM $table WHERE ISNULL(cs_user_id) GROUP BY userId LIMIT $limit" );

        foreach( $res as $one ){
            $userId = $one['userId'];

            #$prev = $this->sql->query_first_row( "SELECT * FROM users WHERE userId = '$userId'" );
            $prev = $this->sql->query_first_row( "SELECT * FROM users" );
                    
            if( isset( $prev['cs_user_id'] ) ){
                $cs_user_id = $prev['cs_user_id'];
            }else{
                $this->sql->execute_query( sprintf( "INSERT INTO users (userId) VALUES ('%s')", $userId ) );
                $cs_user_id = $this->sql->insert_id() ;
            }
            $this->sql->execute_query( sprintf( "UPDATE %s SET cs_user_id=%s WHERE userId='%s'", $table, $prev['cs_user_id'], $userId ) );
        }
    }

    // this will rerun the callback functions from fitfiles (push) that didn't succeed and are still missing
    function maintenance_fix_missing_callback($cs_user_id = NULL,$limit=20){
        if( $cs_user_id ){
            $query = sprintf( 'SELECT * FROM fitfiles WHERE ISNULL(asset_id) AND cs_user_id = %d ORDER BY ts DESC LIMIT %d', intval($cs_user_id), intval($limit) );
        }else{
            $query = "SELECT * FROM fitfiles WHERE ISNULL(asset_id) ORDER BY ts DESC LIMIT $limit";
        }
        $missings = $this->sql->query_as_array( $query );
        $command_ids = array();
        foreach( $missings as $one ){
            array_push( $command_ids, $one['file_id'] );
        }
        if( count( $command_ids ) ){
            $this->exec_callback_cmd('fitfiles', $command_ids );
        }else{
            if( $this->verbose ){
                $this->log( 'INFO', 'No missing callback found' );
            }
        }
    }

    // this will try to match to fitfiles, activities that are not having any detail files
    function maintenance_link_activity_files($cs_user_id=NULL,$limit=20){
        $this->ensure_schema();

        if( $cs_user_id ){
            $query = sprintf( "SELECT * FROM activities WHERE ISNULL(file_id ) AND cs_user_id = %d LIMIT %d", $cs_user_id, $limit );
        }else{
            $query = "SELECT * FROM activities WHERE ISNULL(file_id ) LIMIT $limit";
        }
        $res = $this->sql->query_as_array( $query );

        $mintime = NULL;
        $maxtime = NULL;
        
        foreach( $res as $one ){
            $startTime = $one['startTimeInSeconds'];
            $cs_user_id = $one['cs_user_id'];
            $found = $this->sql->query_first_row( "SELECT * FROM fitfiles WHERE startTimeInSeconds=$startTime AND cs_user_id=$cs_user_id" );
            if( $found ){
                $query = sprintf( 'UPDATE activities SET file_id = %s WHERE activity_id = %s', $found['file_id'], $one['activity_id'] );
                $this->sql->execute_query( $query );
            }else{
                if( $this->verbose ){
                    $this->log( 'INFO', 'Missing activity for %s', strftime("%Y-%m-%d, %H:%M:%S", $startTime ) );
                }
                if( $mintime == NULL ||  $startTime < $mintime ){
                    $mintime = $startTime;
                }
                if( $maxtime == NULL || $startTime > $maxtime ){
                    $maxtime = $startTime;
                }
            }
        }
    }

    function maintenance_s3_upload_backup_assets( $limit ){

        if( ! $this->sql->table_exists('upload_bad_files' ) ){
            $create = true;
            $this->sql->create_or_alter('upload_bad_files', array( 'ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP', 'cs_user_id' => 'BIGINT(20) UNSIGNED KEY' ) );
        }
        $last_user = $this->sql->query_first_row( 'SELECT MAX(cs_user_id) FROM `users`' );
        $last_user = $last_user['MAX(cs_user_id)'];

        $user = $this->sql->query_first_row( 'SELECT MAX(cs_user_id) FROM upload_bad_files' );
        if( ! $user ){
            $first_cs_user_id = 1;
        }else{
            $first_cs_user_id = intval($user['MAX(cs_user_id)'])+1;
        }

        $this->log( 'INFO', 'Processing %d users from %d to %d',$limit, $first_cs_user_id, $last_user );
        $done = 0;

        for( $cs_user_id = $first_cs_user_id; ($cs_user_id < $last_user && $done < $limit ); $cs_user_id++){
            if( $this->user_is_active( $cs_user_id ) ){
                $done += 1;
                $this->log('INFO', 'processing user %d [done %d/%d]',$cs_user_id, $done, $limit );

                $this->maintenance_s3_upload_backup_assets_for_user( $cs_user_id );
                $this->sql->execute_query( sprintf('INSERT INTO upload_bad_files (cs_user_id) VALUES(%d)',$cs_user_id) );
            }else{
                $this->log('INFO', 'skipping user %d',$cs_user_id );
            }                
        }
    }

    function maintenance_s3_upload_backup_assets_for_user( $cs_user_id ){
        $query = sprintf( 'SELECT cs_user_id,fitfiles.startTimeInSeconds,fitfiles.file_id,fitfiles.asset_id,path,data FROM fitfiles,assets WHERE assets.asset_id = fitfiles.asset_id AND fitfiles.asset_id IS NOT NULL AND cs_user_id = %d AND path IS NULL ORDER BY fitfiles.file_id DESC', $cs_user_id);
        $stmt = $this->sql->connection->query($query);
        $s3_bucket = $this->api_config['save_to_s3_bucket'];
        $threshold = intval($this->api_config['ignore_activities_date_threshold'] );

        if( $stmt ){
            while( $row = $stmt->fetch_array( MYSQLI_ASSOC ) ){
                $s3_path = $this->file_path_for_file_row( $row );
                $time = date("Y-m-d", $row['startTimeInSeconds']);

                if( intval($row['startTimeInSeconds']) > $threshold ){
                    $s3_data = $this->save_to_s3_bucket( $s3_bucket, $s3_path, $row['data']);
                    
                          
                    $this->log( 'INFO', 'Uploading %s. startTimeInSeconds=%s cs_user_id=%d asset_id=%d file_id=%d mysql: %s bytes s3: %s bytes.', $s3_path, $time, $cs_user_id, $row['asset_id'], $row['file_id'], strlen( $row['data'] ), strlen( $s3_data ));

                }else{
                    $this->log( 'INFO', 'Skipping %s. startTimeInSeconds=%s cs_user_id=%d asset_id=%d file_id=%d mysql: %s bytes', $s3_path, $time, $cs_user_id, $row['asset_id'], $row['file_id'], strlen( $row['data'] ) );
                }
            }
        }
    }
    
    function maintenance_migrate_assets( $limit, $defaultstart=0 ){
        $query = sprintf( 'SELECT MAX(asset_id) FROM assets_s3' );
        $max = $this->sql->query_first_row( $query );
        if( isset( $max['MAX(asset_id)'] ) ){
            $start = $max['MAX(asset_id)'] + 1;
        }else{
            $start = $defaultstart;
        }

        if( ! isset($this->api_config['save_to_s3_bucket'] ) ){
            $this->log( 'WARNING', 'no bucket defined');
            return;
        }
        $s3_bucket = $this->api_config['save_to_s3_bucket'];
        $this->log( 'INFO', 'migrating from %s to %s using bucket %s', $start, $start + $limit, $s3_bucket );
        
        for( $cache_id = $start; $cache_id < $start+$limit; $cache_id++){
            
            $row = $this->sql->query_first_row( sprintf( 'SELECT * FROM assets WHERE asset_id = %s', $cache_id ) );
            if( $row ){
                $this->log( 'INFO', 'Processing asset_id=%d', $cache_id);
                if( strlen( $row['path'] ) > 0 && substr( $row['path'], 0, 3 )== 's3:'){
                    $this->log('INFO', 'already in c3 asset_id=%d', $cache_id);
                } else if( strlen( $row['data'] ) > 0 ){
                    $arow = $this->sql->query_first_row( sprintf( 'SELECT * FROM fitfiles WHERE file_id = %s', $row['file_id'] ) );
                    if( isset( $arow['cs_user_id'] ) ){
                        $path = $this->file_path_for_file_row( $arow );
                        $this->log( 'INFO', 'Uploading %s bytes asset_id=%d file_id=%d', strlen( $row['data'] ), $cache_id, $row['file_id']);
                        $row['path'] = 's3:' . $path;
                        $row['filename'] = basename( $path );
                        $this->save_to_s3_bucket( $s3_bucket, $path, $row['data'] );
                        unset( $row['data'] );
                    }
                }
                $this->sql->insert_or_update( 'assets_s3', $row );
            }
        }
    }
    
    function maintenance_export_table( $table, $key, $key_start, $key_offset = 0 ){
        $done = false;

	    $tmp_path = $this->maintenance_writable_path('tmp');

        if( is_writable( $tmp_path ) ){
            // Make sure there is anything to do
            $query = sprintf( 'SELECT MAX(%s) AS maxkey FROM `%s`', $key, $table );
            $max = $this->sql->query_first_row( $query );

            $key_max = NULL;
            if( isset( $max['maxkey'] ) ){
                $key_max = intval( $max['maxkey'] );
                if( $key_offset > 0 ){
                    $key_max =  $key_max - $key_offset;
                }
                
                if( $key_max <= intval($key_start) ){
                    # this is printed to mysql so -- as comment
                    $this->log( '-- INFO', 'nothing new' );
                    return true;
                }
            }

            
            $db = $this->api_config['database'];
            $outfile = sprintf( '%s/%s_%s.sql', $tmp_path, $table, $key_start );
            $logfile = sprintf( '%s/%s_%s.log', $tmp_path, $table, $key_start );
            $defaults = sprintf( '%s/.%s_%s.cnf', $tmp_path, $db, $table );
            file_put_contents( $defaults, sprintf( '[mysqldump]'.PHP_EOL.'password=%s'.PHP_EOL, $this->api_config['db_password'] ) );
            chmod( $defaults, 0600 );
            $limit = '';

            $mysqldump = '/usr/bin/mysqldump';
            if( ! is_executable( $mysqldump ) ){
                $mysqldump = '/usr/local/mysql/bin/mysqldump';
            }
            if( ! is_executable( $mysqldump ) ){
                header('HTTP/1.1 500 Internal Server Error');
                die;
            }
            $where = sprintf( "%s>%s", $key, $key_start );
            if( $key_max && $key_offset > 0 ){
                $where = sprintf( "%s AND %s<%s", $where, $key, $key_max );
            }
            
            $command = sprintf( '%s --defaults-file=%s --single-transaction=TRUE -t --hex-blob --result-file=%s -u %s %s %s --where "%s%s"', $mysqldump, $defaults, $outfile, $this->api_config['db_username'], $db, $table, $where, $limit );
            if( $this->verbose ){
                printf( 'Exec %s<br />'.PHP_EOL, $command );
            }
            exec( "$command > $logfile 2>&1" );
            unlink( $defaults );

            if( is_readable( $outfile ) ){
                if( $this->verbose ){
                    printf( 'Output: %s (%s bytes)<br />', $outfile, filesize( $outfile ) );
                    print( '<code>' );
                    readfile( $logfile );
                    print( '</code>' );
                }else{
                    header('Content-Type: application/sql');
                    header(sprintf('Content-Disposition: attachment; filename=%s', $outfile ));
                    readfile( $outfile );
                }
                $done = true;
                unlink( $outfile );
            }

            # don't leave 0 size log files around
            if( is_readable( $logfile ) && filesize( $logfile ) == 0 ){
                unlink( $logfile );
            }

        }else{
            if( $this->verbose ){
                $this->log( 'ERROR', 'Cannot write to tmp path %s from %s', $tmp_path, getcwd() );
            }
        }
        return $done;
    }

    function maintenance_writable_path($def = 'tmp'){
        if( isset( $this->api_config[$def] ) && is_writable( $this->api_config[$def] ) ){
            return $this->api_config[$def];
        }else{
            return $def;
        }
    }


    function maintenance_backup_table( $table, $key ){
        // optional setting

	    $tmp_path = $this->maintenance_writable_path('tmp');
        $backup_path = $this->maintenance_writable_path('backup_path');

        if( !is_writable( $tmp_path )  ){
            $this->log( 'WARNING', 'tmp (%s)_path not setup and writeable, skipping backup', $tmp_path );
            return;
        }
        if( ! is_writable( $backup_path ) ){
            $this->log( 'WARNING', 'backup_path (%s) not setup and writeable, skipping backup', $backup_path );
            return;
        }
        
        $newdata = false;
        
        if( isset( $this->api_config['url_backup_source'] ) && is_writable( $tmp_path ) && is_writable( $backup_path )){
            $last = $this->sql->query_first_row( sprintf( 'SELECT MAX(%s) FROM `%s`', $key, $table ) );
            $last_key = intval($last[ sprintf( 'MAX(%s)', $key ) ]);
            $database = $this->api_config['database'];
            $url_src = $this->api_config['url_backup_source'];
            $url = sprintf( '%s/api/garmin/backup?database=%s&table=%s&%s=%s', $url_src, $database, $table, $key, $last_key  );
            $this->log( 'CURL',  $url );

            $backup_dir = sprintf( '%s/%s', $backup_path, strftime( '%Y/%m' ) );
            if( ! is_dir( $backup_dir ) ){
                if( ! mkdir( $backup_dir, 0755, true ) ){
                    $this->log( "ERROR","failed to create directory %s", $backup_dir );
                    die;
                }
            }
            $sql_out = sprintf( '%s/backup_%s_%s.sql', $backup_dir, $table, $last_key );
            file_put_contents( $sql_out, $this->get_url_data( $url, $this->api_config['serviceKey'], $this->api_config['serviceKeySecret'] ) );

            $outsize = filesize( $sql_out );
            # Really should check it output is starts with --INFO and ends with nothing new 
            if( $outsize > 30 ){
                $this->log( 'OUT','Got new data for %s (%d bytes)', $table, $outsize );
                $defaults = sprintf( '%s/.%s.cnf', $tmp_path, $database );
                file_put_contents( $defaults, sprintf( '[mysql]'.PHP_EOL.'password=%s'.PHP_EOL, $this->api_config['db_password'] ) );
                chmod( $defaults, 0660 );
                $command = sprintf( 'mysql --defaults-file=%s -u %s -h %s %s < %s', $defaults, $this->api_config['db_username'], $this->api_config['db_host'], $database, $sql_out );
                $this->log( 'EXEC',  $command );
                system(  $command );
                $newdata = true;
            }else{
                $this->log( "OUT", "Nothing New for %s", $table );
            }
        }
        return $newdata;
    }

    function maintenance_clean_cache_for_asset_row($row){
        if (isset($this->api_config[ 's3_cache_local'] ) &&  substr($row['path'], 0, 3) == 's3:') {
            $s3_path = substr($row['path'], 3, strlen($row['path']));

            if ($this->verbose) {
                $this->log('S3', 'Clear %s from s3 cache', $s3_path );
            }
            $this->remove_from_s3_cache($s3_path );
        }
    }
    
    function data_from_asset_row($row){
        $rv = NULL;
        if( isset( $row['path'] ) ){
            if( substr( $row['path'], 0, 3 ) == 's3:' ){
                if( isset($this->api_config['save_to_s3_bucket'] ) ){
                    $s3_bucket = $this->api_config['save_to_s3_bucket'];
                    $s3_path = substr( $row['path'], 3, strlen( $row['path'] ) );
                    
                    if( $this->verbose ){
                        $this->log( 'S3', 'Retrieve %s from s3 bucket %s', $s3_path, $s3_bucket);
                    }
                
                    $rv = $this->retrieve_from_s3_bucket( $s3_bucket, $s3_path);
                }
            }
        }
        if( $rv == NULL && $row['data'] ){
            $rv = $row['data'];
        }
        return $rv;
    }
    
    function query_file( $paging ){
        if( $paging->direct_file_query() ){
            $query = sprintf( "SELECT file_id,path,data FROM assets WHERE %s", $paging->file_where() );
        }else if ($paging->summary_file_query() ){
            $query = sprintf( "SELECT fitfiles.file_id,data,path FROM fitfiles, assets WHERE fitfiles.asset_id = assets.asset_id AND fitfiles.summaryId = '%d'", $paging->summary_id );
        }else{
            $query = sprintf( "SELECT activity_id,data,path FROM activities, assets WHERE activities.file_id = assets.file_id AND %s %s", $paging->activities_where(), $paging->activities_paging() );
        }
        if( $this->verbose ){
            $this->log( 'EXECUTE', $query );
        }
        $stmt = $this->sql->connection->query($query);
        
        $rv = NULL;
        $zip = NULL;
        $zipname = NULL;
        if( $stmt ){
            while( $results = $stmt->fetch_array( MYSQLI_ASSOC ) ){
                if( ! $rv ){
                    $onename = sprintf( '%d.fit', isset($results['activity_id']) ? $results['activity_id'] : $results['file_id'] );
                    
                    $rv = $this->data_from_asset_row( $results );
                    if ($this->verbose) {
                        $this->log('INFO', 'got fit file %s (%d bytes)', $onename, strlen($rv));
                    }
                }else{
                    // If multiple, create zip archive
                    if( ! $zip ){
                        $zipname = sprintf( '%s/%s.zip', $this->maintenance_writable_path( 'tmp' ), $paging->filename_identifier() );
                        $zip = new ZipArchive();
                        $zip->open( $zipname, ZipArchive::CREATE );
                        // Add first one
                        $zip->addFromString( $onename, $rv );
                    }
                    $onename = sprintf( '%d.fit', isset($results['activity_id']) ? $results['activity_id'] : $results['file_id'] );
                    $data = $this->data_from_asset_row( $results );
                    $zip->addFromString( $onename, $data );
                }
            }
        }
        if( isset( $zip ) ){
            $zip->close();
            $zip = NULL;

            $rv = file_get_contents( $zipname );
            if( $this->verbose ){
                $this->log( 'INFO','using zip file %s (%d bytes)', $zipname, strlen( $rv ) );
            }
            unlink( $zipname );
        }
        
        return $rv;
    }

    function query_json( $tables, $paging ){

        if( $paging->direct_file_query() ){
            $query = sprintf( "SELECT file_id,`json` FROM %%s WHERE %s %s", $paging->file_where(), $paging->file_paging() );
        } else {
            $query = sprintf( "SELECT activity_id,jsontable.`json` FROM activities, %%s jsontable WHERE activities.file_id = jsontable.file_id AND activities.activity_id = COALESCE(parent_activity_id,activity_id) AND %s ORDER BY activities.startTimeInSeconds DESC %s", $paging->activities_where(),$paging->activities_paging() );
        }
        $results = array();

        foreach( $tables as $table ){
            $results[$table] = array();
            
            $table_query = sprintf( $query, $table );
            $rows = $this->sql->query_as_array( $table_query );
            foreach( $rows as $result ){
                $activity_json = json_decode($result['json'], true );
                if( isset( $result['activity_id'] ) ){
                    $activity_id = $result['activity_id'];
                    $activity_json['activity_id'] = $activity_id;
                }else if( isset( $result['file_id'] ) ){
                    $file_id = $result['file_id'];
                    $activity_json['file_id'] = $file_id;
                }
                array_push( $results[$table], $activity_json );
            }
        }
        return $results;
    }

    /**
     * This function is the main function used by connectstats
     * To retrieve the summaries of activities in that server for a specific user
     */
    function query_activities( $paging ) {
        $query = sprintf( "SELECT activity_id,parent_activity_id,json FROM activities WHERE %s ORDER BY startTimeInSeconds DESC %s", $paging->activities_where(), $paging->activities_paging() );
        $json = $this->query_activities_json($query);
        $count = $paging->activities_total_count();

        $rv = array( 'activityList' => $json, 'paging' => $paging->json() );
        
        print( json_encode( $rv ) );

        
        return $rv;
    }


    /**
     * This function retrieves the activities received from the garmin service
     * And reconstruct the format that garmin sends in the push, which can 
     * be useful for debugging in retriggering a push from old activities
     *
     * NOTE that this is sorted by time stamp, to simulate the order they were sent
     *      by the garmin service
     */
    function query_activities_garmin_health_format( $paging ){

        $query = sprintf( 'SELECT activity_id,json,userId,userAccessToken FROM activities WHERE %s ORDER BY activities.ts DESC %s', $paging->activities_where(), $paging->activities_paging() );
        
        $res = $this->sql->query_as_array( $query );
        $rv = array();
        foreach( $res as $one ){
            if( isset($one['json']) ){
                $activity_json = json_decode( $one['json'], true );
                $activity_json['userId'] = $one['userId'];
                $activity_json['userAccessToken'] = $one['userAccessToken'];
                array_push($rv, $activity_json);
            }
        }
        print( json_encode( array( 'activities' => $rv ) ) );
    }

    function query_file_garmin_health_format( $paging ){

        $query = sprintf( 'SELECT activity_id,file_id,userId,userAccessToken,startTimeInSeconds FROM activities WHERE NOT ISNULL(file_id) AND %s ORDER BY activities.ts DESC %s', $paging->activities_where(), $paging->activities_paging() );
        
        $full_url = sprintf( '%s://%s%s', $_SERVER['REQUEST_SCHEME'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );

        $url_info = parse_url( $full_url );
        $url_params = array();
        
        parse_str( $url_info['query'], $url_params );

        $file_url = sprintf( '%s://%s/%s?token_id=%d',
                             $url_info['scheme'],
                             $url_info['host'],
                             str_replace( 'api/connectstats/search', 'api/connectstats/file', $url_info['path'] ),
                             $url_params['token_id']
        );
        
        $res = $this->sql->query_as_array( $query );
        $rv = array();
        foreach( $res as $one ){
            if( isset($one['file_id']) ){
                $activity_file = array(
                    'userId' => $one['userId'],
                    'userAccessToken' => $one['userAccessToken'],
                    'summaryId' => $one['file_id'],

                    'fileType' => 'FIT',
                    'startTimeInSeconds' => intval($one['startTimeInSeconds']),
                    'callbackURL' => sprintf( '%s&file_id=%d', $file_url, $one['file_id'] )
                );

                array_push($rv, $activity_file);
            }
        }
        print( json_encode( array( 'activityFiles' => $rv ) ) );
    }

    function query_activities_json( $query ){
        $res = $this->sql->query_as_array( $query );
        $json = array();
        foreach( $res as  $one ){
            if( isset($one['json']) ){
                $activity_json = json_decode($one['json'], true );
                $activity_json['cs_activity_id'] = $one['activity_id'];
                if( isset( $one['parent_activity_id'] ) ){
                    $activity_json['cs_parent_activity_id'] = $one['parent_activity_id'];
                }
                              
                array_push($json, $activity_json );
            }
        }
        return $json;
    }

    function record_usage( $paging, $status = 0 ){

        if( isset( $paging->cs_user_id ) ){
            $row = array( 'cs_user_id' => $paging->cs_user_id, 'status' => $status );
            foreach( [ 'REQUEST_URI', 'SCRIPT_NAME' ] as $key ){
                if( isset( $_SERVER[$key] ) ){
                    $row[$key] = $_SERVER[$key];
                }
            }
            $this->sql->insert_or_update( 'usage', $row );

            $check = $this->sql->query_first_row(sprintf('SELECT * FROM `users_usage` WHERE cs_user_id = %d', intval($paging->cs_user_id)));
            $yesterday = time() - (24.0 * 3600.0);
            if (!isset($check['last_ts']) || strtotime($check['last_ts']) < $yesterday) {
                $ndays = 1;
                if (isset($check['days'])) {
                    $ndays = intval($check['days']) + 1;
                    $this->sql->execute_query(sprintf('UPDATE `users_usage` SET `days` = %d WHERE `cs_user_id` = %d', intval($ndays), intval($paging->cs_user_id)));
                } else {
                    $this->sql->execute_query(sprintf('INSERT INTO `users_usage` (`cs_user_id`,`days`) VALUES( %d,%d )',  intval($paging->cs_user_id), $ndays,));
                }
            }
        }
    }


    function create_notification_table(){
        if( ! $this->sql->table_exists( 'notifications' ) ){
            $query = "CREATE TABLE notifications_devices (device_token VARCHAR(128) PRIMARY KEY, cs_user_id BIGINT(20) UNSIGNED, INDEX (cs_user_id), enabled INT, push_type INT, ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, create_ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP)";
            $this->sql->execute_query( $query );
            $query = "CREATE TABLE notifications (notification_id BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY, device_token VARCHAR(128), cs_user_id BIGINT(20) UNSIGNED, status INT, apnid VARCHAR(128), ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, create_ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP, received_ts TIMESTAMP)";
            $this->sql->execute_query( $query );
            $query = "CREATE TABLE notifications_activities (activity_id BIGINT(20) UNSIGNED PRIMARY KEY,  notification_id BIGINT(20) UNSIGNED, ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP, create_ts TIMESTAMP DEFAULT CURRENT_TIMESTAMP)";
            $this->sql->execute_query( $query );
        }
    }
    
    function notification_register($cs_user_id, $getparams){
        $device_token = "";
        $enabled = 0;
        $push_type = 0;
        if( isset( $getparams['notification_enabled'] ) ){
            $enabled = intval( $getparams['notification_enabled'] );
        }else{
            // if notification_enabled key is not there, don't bother with the rest
            return;
        }
        if( isset( $getparams['notification_device_token'] ) ){
            $device_token = $this->validate_token( $getparams['notification_device_token'] );
        }
        if( isset( $getparams['notification_push_type'] ) ){
            $push_type = intval( $getparams['notification_push_type'] );
        }
        if( strlen( $device_token ) == 0 ){
            $notification_enabled = 0;
        }

        $row = array( 'cs_user_id' => $this->validate_input_id( $cs_user_id ) );
        
        $row['device_token'] = $device_token;
        $row['push_type'] = $push_type;
        $row['enabled'] = $enabled;
        
        $this->sql->insert_or_update( 'notifications_devices', $row, array( 'device_token' ) );
        return( $row );
    }

    /**
     *   Will push notification for activity_id if necessary
     */
    function notification_push_for_activity( $activity_id ){
        $query = sprintf( 'SELECT activity_id, notification_id FROM notifications_activities WHERE activity_id = %d', $activity_id );
        $check = $this->sql->query_first_row( $query );
        if( isset( $check['notification_id'] ) ){
            $this->log( 'INFO', 'notification for activity_id %d was already sent as notification_id = %d', $activity_id, intval( $check['notification_id'] ) );
            return;
        }
        $this->log( 'INFO', 'Checking required notification for activity_id = %d', $activity_id );

        $query = sprintf( 'SELECT activity_id,cs_user_id FROM activities WHERE activity_id = %d', $activity_id );
        $check = $this->sql->query_first_row( $query );
        if( !isset( $check['cs_user_id' ] ) || intval( $check['cs_user_id'] ) == 0 ){
            $this->log( 'INFO', 'Skipping notification, no cs_user_id for activity_id %d', $activity_id );
            return;
        }
        $cs_user_id = intval( $check['cs_user_id'] );

        if( $this->user_is_active( $cs_user_id ) ){
            $notification_id = $this->notification_push_to_user($cs_user_id, $activity_id );
            if( $notification_id > 0 ){
                $this->sql->insert_or_update( 'notifications_activities', [ 'activity_id' => $activity_id, 'notification_id' => $notification_id ], [ 'activity_id'] );
            }else{
                $this->log( 'INFO', 'No valid notification for cs_user_id %s and activity_id %d', $cs_user_id, $activity_id );
            }
        }else{
            $this->log( 'INFO', 'Skipping notification, cs_user_id %s inactive for activity_id %d', $cs_user_id, $activity_id );
        }
    }
    /**
     *   Will push notification to all the registered and enabled devices for user_id
     *   Will return one of the notification_id if at least one was successful
     */
    function notification_push_to_user($cs_user_id, $activity_id = NULL){
        $query = sprintf( 'SELECT device_token,enabled,push_type FROM notifications_devices WHERE cs_user_id = %d', $cs_user_id);
        $found = $this->sql->query_as_array( $query );
        $sample_notification_id = false;
        foreach( $found as $row ){
            $push_type = intval($row['push_type']);
            if( intval($row['enabled']) == 1 && $push_type>0){
                $msg = NULL;
                if( $push_type == 1){
                    $msg = [ "aps" => [ "content-available" => 1, "alert" => "New Activity Available!", "badge" => 1 ] ];
                }else if( $push_type == 2){
                    $msg = [ "aps" => [  "alert" => "New Activity Available!", "badge" => 1 ] ];
                }
                if( intval($activity_id) > 0 ){
                    $msg['activity_id'] = $activity_id;
                }
                $msg['cs_user_id'] = $cs_user_id;
                
                if( $msg ){
                    $one = $this->notification_push_to_device( $row['device_token'], $msg, $cs_user_id );
                    if( intval($one) > 0 ){
                        $sample_notification_id = intval($one);
                    }
                }else{
                    $this->log( 'INFO', 'Skipping unknown push_type for device %s for user %s', $row['device_token'], $cs_user_id );
                }
            }else{
                $this->log( 'INFO', 'Skipping disabled device %s for user %s', $row['device_token'], $cs_user_id );
            }
        }
        return $sample_notification_id;
    }

    /**
     *   Push a notification to a device_token if config is properly configured. 
     *   Return notification_id if successfull
     */
    function notification_push_to_device($device_token, $message, $cs_user_id)
    {
        foreach( array('apn_keyfile', 'apn_keyid', 'apn_teamid', 'apn_bundleid', 'apn_url' ) as $key ){
            if( ! isset( $this->api_config[$key] ) ){
                if( $this->verbose ){
                    $this->log( "INFO", "Missing apn key %s, skipping push notification", $key );
                    return false;
                }
            }
        }
        
        $keyfile = $this->api_config['apn_keyfile'];
        $keyid = $this->api_config['apn_keyid'];
        $teamid = $this->api_config['apn_teamid'];
        $bundleid = $this->api_config['apn_bundleid'];
        $url = $this->api_config['apn_url'];
        $token = $device_token;

        $json_message = json_encode($message);

        $key = openssl_pkey_get_private('file://' . $keyfile);

        $header = ['alg' => 'ES256', 'kid' => $keyid];
        $claims = ['iss' => $teamid, 'iat' => time()];

        $header_encoded = $this->base64($header);
        $claims_encoded = $this->base64($claims);

        $signature = '';
        openssl_sign($header_encoded . '.' . $claims_encoded, $signature, $key, 'sha256');
        $jwt = $header_encoded . '.' . $claims_encoded . '.' . base64_encode($signature);

        // only needed for PHP prior to 5.5.24
        if (!defined('CURL_HTTP_VERSION_2_0')) {
            define('CURL_HTTP_VERSION_2_0', 3);
        }
        $headers = array(
                "apns-topic: {$bundleid}",
                "authorization: bearer $jwt"
        );
        if( isset( $message['aps']['alert'] ) ){
            $headers['apns-priority'] = 10;
            $headers['apns-push-type'] = 'alert';
        }else{
            $headers['apns-priority'] = 5;
            $headers['apns-push-type'] = 'background';
        }
        $http2ch = curl_init();
        curl_setopt_array($http2ch, array(
            CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_2_0,
            CURLOPT_URL => "$url/3/device/$token",
            CURLOPT_PORT => 443,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_POST => TRUE,
            CURLOPT_POSTFIELDS => $json_message,
            CURLOPT_RETURNTRANSFER => TRUE,
            CURLOPT_TIMEOUT => 30,
            CURLOPT_HEADER => 1
        ));

        if( $this->verbose ){
            $this->log( 'INFO', 'apns-priority: %s', $headers['apns-priority'] );
            $this->log( 'INFO', 'apns-push-type: %s', $headers['apns-push-type'] );
            $this->log( 'INFO', 'Payload %s', $json_message );
        }
        $result = curl_exec($http2ch);
        if ($result === FALSE) {
            $this->log( 'ERROR', "Curl failed: %s", curl_error($http2ch));
            return false;
        }
        $header_size = curl_getinfo( $http2ch, CURLINFO_HEADER_SIZE );
        $response_header = substr( $result, 0, $header_size );
        $response_body = substr( $result, $header_size );
        $status = curl_getinfo($http2ch, CURLINFO_HTTP_CODE);
        $response_header_lines = explode( PHP_EOL, $response_header );
        $response_headers = [];
        foreach( $response_header_lines as $line ){
            $split_line = explode( ':', $line, 2);
            if( count($split_line) == 2 ){
                $response_headers[ strtolower(trim($split_line[0])) ] = trim( $split_line[1] );
            }
        }
        $this->log( 'INFO', 'apns response status: %s ', $status );
        $this->log( 'INFO', 'apns response header: %s ', json_encode($response_headers) );
        $this->sql->insert_or_update( 'notifications', [ 'device_token' => $device_token, 'cs_user_id' => $cs_user_id, 'status' => $status, 'apnsid' => $response_headers['apns-id'] ] );
        $notification_id = $this->sql->insert_id();
        if( $status == 200 ){
            if( $this->verbose ){
                $this->log( 'INFO', 'Successfully send notification on %s to %s', $url, $device_token );
            }
        }else{
            if( $this->verbose ){
                $this->log( 'WARNING', 'Failed to send notification to %s with status %d and info %s (using %s)', $device_token, $status, $response_body, $url );
            }
            $notification_id = false;
        }
        return $notification_id;
    }
    
    function base64($data) {
          return rtrim(strtr(base64_encode(json_encode($data)), '+/', '-_'), '=');
    }

};
    
?>
