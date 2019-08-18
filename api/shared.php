<?php

error_reporting(E_ALL);

/*
 *  ----- Garmin Service API -----
 *  User Registration
 *    - Save user access token and secret
 *  Fit File ping
 *    - start subprocess to get callbackURL
 *    - save to db the data
 *    - try to match to activity
 *    - unique by activityFile Id (or the url)
 *    - summary id seem unrelated ot activity summary id and activityFileId
 *  Activity Summary
 *    - save the summary as json
 *    - try to match any corresponding file
 *    - json contains summaryId that matches garmin connect
 *  Matching fit file and activity
 *    - userId and startTimeInSeconds
 *
 *  ----- Query API -----
 *  Query Info for user (userid)
 *    - min date, max date, number of activities, max activity id
 *  List of Activity for user (userid, number, offset from last)
 *    - from activities select for userid startTimeInSeconds DESC
 *  Query from file for userid, assetId
 */

# include_once( $_SERVER['DOCUMENT_ROOT'].'/php/sql_helper.php' )
include_once( 'sql_helper.php');

class garmin_sql extends sql_helper{
	function __construct() {
        include( 'config.php' );
		parent::__construct( $api_config['database'] );
	}
	static function get_instance() {
		static $instance;
		if( ! isset( $instance ) ){
            include( 'config.php' );
            $instance = new sql_helper( NULL, $api_config['database'] );
		}
		return( $instance );
	}
}

class StatusCollector {
    function __construct( ){
        $this->messages = array();
        $this->table = NULL;
        $this->verbose = false;
    }

    function clear($table){
        $this->table = $table;
        $this->messages = array();
    }
    
    function error( $msg ){
        if( $this->verbose ){
            printf( "ERROR: %s".PHP_EOL, $msg );
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
                    'json' => 'TEXT',
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
                          'user_agent' => $_SERVER['HTTP_USER_AGENT'],
                          'remote_addr' => $_SERVER['REMOTE_ADDR'],
            );

            if( ! $sql->insert_or_update( $error_table, $row ) ){
                printf( 'FAILED TO RECORD: %s'.PHP_EOL, $sql->lasterror );
            }
        }else{
            print( "ERRORS".PHP_EOL );
            print_r( $this->messages );
        }
    }
}


class GarminProcess {
    function __construct() {
        $this->sql = new garmin_sql();
        $this->sql->verbose = false;
        $this->verbose = false;
        $this->status = new StatusCollector();
        if( isset($_GET['verbose']) && $_GET['verbose']==1){
            $this->set_verbose( true );
        }

        include( 'config.php' );
        $this->api_config = $api_config;
    }

    function set_verbose($verbose){
        $this->verbose = $verbose;
        $this->sql->verbose = $verbose;
        $this->status->verbose = $verbose;
    }

    // Reset Database schema from scratch 
    function reset_schema() {
        // For development database only
        if( $this->sql->table_exists( 'dev' ) ){
            $tables = array( 'activities', 'assets', 'tokens', 'error_activities', 'error_fitfiles', 'schema', 'users', 'fitfiles', 'backfills', 'fitsessions', 'weather' );
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
        return true;
    }
    
    function ensure_schema() {
        $schema_version = 3;
        $schema = array(
            "users" => array(
                'cs_user_id' => 'BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY',
                'userId' => 'VARCHAR(128)',
                'backfillEndTime' => 'BIGINT(20) UNSIGNED',
                'backfillStartTime' => 'BIGINT(20) UNSIGNED',
                'ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                'created_ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP'
            ),
            "tokens" => array(
                'token_id' => 'BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY',
                'cs_user_id' => 'BIGINT(20) UNSIGNED',
                'userAccessToken' => 'VARCHAR(128)',
                'userId' => 'VARCHAR(128)',
                'userAccessTokenSecret' => 'VARCHAR(128)',
                'ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP',
                'created_ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
                'backfillStartTime' => 'BIGINT(20) UNSIGNED',
                'backfillEndTime' => 'BIGINT(20) UNSIGNED'
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
            'backfills' => array(
                'backfill_id' => 'BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY',
                'ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
                'token_id' => 'BIGINT(20) UNSIGNED',
                'summaryStartTimeInSeconds' => 'BIGINT(20) UNSIGNED',
                'summaryEndTimeInSeconds' => 'BIGINT(20) UNSIGNED',
                'backfillEndTime' => 'BIGINT(20) UNSIGNED'
            )
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
        
        $this->status = new StatusCollector($table);

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
                        $token = $info['userAccessToken'];
                        $query = "UPDATE tokens SET userAccessTokenSecret = NULL WHERE userAccessToken = '$token'";
                        if( ! $this->sql->execute_query( $query ) ){
                            $this->status->error( sprintf( 'Sql failed %s (%s)', $query, $this->sql->lasterror ) );
                        }
                    }
                }
            }
        }
        if( $this->status->hasError() ){
            $this->status->record( 'deregistration', $rawdata );
        }
        return $this->status->success();
    }
    
    function register_user( $userAccessToken, $userAccessTokenSecret ){
        $this->ensure_schema();

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
                # If we didn't get a user id from garmin, just die as unauthorized
                # this will avoid people registering random token
                header('HTTP/1.1 401 Unauthorized error');
                die;
            }
            $values['cs_user_id'] = $cs_user_id;
        }

        $this->sql->insert_or_update( 'tokens', $values, array( 'userAccessToken' ) );
        $token_id = $this->sql->insert_id();
        
        $query = sprintf( "SELECT userAccessToken,userId,token_id,cs_user_id FROM tokens WHERE userAccessToken = '%s'", $userAccessToken );

        $rv = $this->sql->query_first_row( $query );

        return $rv;
    }

    function user_info_for_token_id( $token_id ){
        $query = sprintf( "SELECT * FROM tokens WHERE token_id = %d", $token_id );

        $rv = $this->sql->query_first_row( $query );

        return $rv;
    }

    function user_info( $userAccessToken ){
        $query = sprintf( "SELECT * FROM tokens WHERE userAccessToken = '%s'", $userAccessToken );

        $rv = $this->sql->query_first_row( $query );

        return $rv;
    }

    /**
     * Main process function for the API entry point call back from the 
     * garmin service
     *
     * if unique_keys null will use required, but sometimes
     * you wnat to exclude some keys from required to determine uniqueness
     * of the rows, for example skip callbackURL
     *
     */
    function process($table, $required, $unique_keys = NULL ) {
        $this->ensure_schema();
        
        $this->status->clear($table);

        $end_points_fields = array(
            'userId' => 'VARCHAR(1024)',
            'userAccessToken' => 'VARCHAR(512)',
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
            if( $this->status->success() ){
                $command_ids = array();
                foreach( $data as $summary_type => $activities){
                    foreach( $activities as $activity){
                        $row = array();
                    
                        foreach( $end_points_fields as $key => $value ){
                            if( array_key_exists( $key, $activity ) ){
                                $row[ $key ] = $activity[$key];
                            }else{
                                $this->status->error( sprintf( 'Missing end point field %s', $key ) );
                            }
                        }

                        foreach( $required as $key ){
                            if( array_key_exists( $key, $activity ) ) {
                                $row[$key] = $activity[$key];
                            }else{
                                $this->status->error( sprintf( 'Missing required field %s', $key ) );
                            }
                        }
                    
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
                        
                        if( $this->status->success() ){
                            if( ! $this->sql->insert_or_update($table, $row, ($unique_keys != NULL ? $unique_keys : $required) ) ) {
                                $this->status->error( sprintf( 'SQL error %s', $this->sql->lasterror ) );
                            }
                        }
                        if( $this->status->success() ){
                            if( $callbackURL ) {
                                $found = $this->sql->query_first_row( sprintf( 'SELECT file_id FROM `%s` WHERE summaryId = %s', $table, $row['summaryId'] ) );
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
                }
            }
        }
        $rv = $this->status->success();
        #$this->status->error('for debug logging');

        if( $this->status->hasError() ) {
            $this->status->record($this->sql,$rawdata);
        }
        return $rv;
    }

    function exec_backfill_cmd( $token_id, $days, $sleep ){

        if( is_writable( 'tmp' ) ){
            $log = sprintf( 'tmp/backfill_%d_%s', $token_id, strftime( '%Y%m%d_%H%M%S',time() ) );
            $command = sprintf( 'php runbackfill.php %s %s %s > %s.log 2> %s-err.log &', $token_id, $days, $sleep, $log, $log );
        }else{
            $command = sprintf( 'php runbackfill.php %s %s %s > /dev/null 2> /dev/null &', $token_id, $days, $sleep );
        }
        if( $this->verbose ){
            printf( 'Exec %s'.PHP_EOL, $command );
        }
        exec( $command );
    }
    
    function exec_callback_cmd( $table, $command_ids ){
        if( count($command_ids) > 25 ){
            $chunks = array_chunk( $command_ids, (int)ceil(count($command_ids)/5 ) );
        }else{
            $chunks = array( $command_ids );
        }
        foreach( $chunks as $chunk ){
            $file_ids = implode( ' ', $chunk );

            $command_base = sprintf( 'php runcallback.php %s %s', $table, $file_ids );
            if( is_writable( 'tmp' ) ){
                $logfile = str_replace( ' ', '_', sprintf( 'tmp/callback-%s-%s-%s', $table, substr($file_ids,0,10),substr( hash('sha1', $command_base ), 0, 8 ) ) );
                $command = sprintf( '%s > %s.log 2> %s-err.log &', $command_base, $logfile, $logfile );
            }else{
                $command = sprintf( '%s > /dev/null 2> /dev/null', $command_base );
            }
            if( $this->verbose ){
                printf( 'Exec %s'.PHP_EOL, $command );
            }
            exec( $command );
        }
    }
    
    function Authorization_header_for_token_id( $full_url, $token_id ){

        $row = $this->sql->query_first_row( "SELECT * FROM tokens WHERE token_id = $token_id" );
        return $this->Authorization_header( $full_url, $row['userAccessToken'], $row['userAccessTokenSecret'] );
        
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
                $reconstructed = $this->Authorization_header( $full_url, $this->api_config['serviceKey'], $this->api_config['serviceKeySecret'], $maps['oauth_nonce'], $maps['oauth_timestamp'] );
                $reconstructed = str_replace( 'Authorization: ', '', $reconstructed );
                $reconmaps = $this->interpret_authorization_header( $reconstructed );
                // Check if token id is consistent with the token id of the access token

                if( $reconmaps['oauth_signature'] == $maps['oauth_signature'] ){
                    $failed = false;
                }
            }
        }

        if( $failed ){
            header('HTTP/1.1 401 Unauthorized error');
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
        
        $full_url = sprintf( '%s://%s%s', $_SERVER['REQUEST_SCHEME'], $_SERVER['HTTP_HOST'], $_SERVER['REQUEST_URI'] );
        if( isset( apache_request_headers()['Authorization'] ) ){
            $header = apache_request_headers()['Authorization'];

            $maps = $this->interpret_authorization_header( $header );
            if( isset( $maps['oauth_token'] ) && isset( $maps['oauth_nonce'] ) && isset( $maps['oauth_signature'] ) ){
                $userAccessToken = $maps['oauth_token'];
                $row = $this->sql->query_first_row( "SELECT userAccessTokenSecret,token_id FROM tokens WHERE userAccessToken = '$userAccessToken'" );
                if( isset( $row['token_id'] ) ){
                    $reconstructed = $this->Authorization_header( $full_url, $userAccessToken, $row['userAccessTokenSecret'], $maps['oauth_nonce'], $maps['oauth_timestamp'] );
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
            header('HTTP/1.1 401 Unauthorized error');
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
        $headers = [ $this->authorization_header( $url, $userAccessToken, $userAccessTokenSecret ) ];

        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers );

        if( $this->verbose ){
            printf( "CURL: %s".PHP_EOL, $url );
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
    function validate_fit_file( $data ){
        if( strlen($data) < 13 ){
            return false;
        }
        $rv = unpack( 'c3', $data, 9 );
        // Equal to 'fit'
        return ( $rv[1] == 70 && $rv[2] == 73 && $rv[3] == 84 );
    }


    /**
     *  Extract and save information from downloaded fit files
     *  if darkSkyNet key exists, try to download weather
     *
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
                printf( 'gps '.PHP_EOL );
                if( $lat != 0.0 && $lon != 0.0 && isset( $this->api_config['darkSkyKey'] ) ){

                    $api_config = $this->api_config['darkSkyKey'];
                    $url = sprintf( 'https://api.darksky.net/forecast/%s/%f,%f,%d?units=si', $api_config, $lat, $lon, $st);

                    $ch = curl_init();

                    curl_setopt($ch, CURLOPT_URL, $url );
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1 );

                    if( $this->verbose ){
                        printf( "CURL: %s".PHP_EOL, $url );
                    }
                    $data = curl_exec($ch);
                    if( $data === false ) {
                        $this->status->error( sprintf( 'CURL Failed %s', curl_error($ch ) ) );
                    }else{
                        $weather = json_decode( $data, true );
                        $keep_hourly = array();
                        foreach( $weather['hourly']['data'] as $one ){
                            $cur = $one['time'];
                            $inside = ($cur > ($st-3600.0)) && ($cur < ($ts+3600.0) );
                            if( $inside ){
                                array_push( $keep_hourly, $one );
                            }
                        }
                        $weather['hourly']['data'] = $keep_hourly;

                        $this->sql->insert_or_update( 'weather', array( 'cs_user_id' => $cs_user_id, 'file_id' => $file_id, 'json' => json_encode($weather) ), array( 'file_id' ) );
                    }
                    curl_close($ch);
                }
            }
            if( isset( $fit_mesgs['session'] ) ){
                $fitdata = json_encode($fit_mesgs['session'] );
                $this->sql->insert_or_update( 'fitsession', array( 'cs_user_id'=>$cs_user_id, 'file_id'=> $file_id,'json'=>$fitdata ), array( 'file_id' ) );
            }
        }
    }



    /**
     *    This function is called in the background
     */
    function run_file_callback( string $table, array $cbids ){
        $this->ensure_schema();
        foreach( $cbids as $cbid ){
            $this->file_callback_one( $table, $cbid );
        }
    }
    
    function file_callback_one( $table, $cbid ){
        
        $save_to_file = false;
        
        $this->status->clear('assets');
        
        $query = sprintf( "SELECT * FROM %s WHERE file_id = %s", $table, $cbid );
        $row = $this->sql->query_first_row( $query );
        if( ! $row ){
            $this->status->error( sprintf( 'sql error %s', $this->sql->lasterror ) );
        }
        
        if( $this->status->success() ){
            $callback_url = $row[ 'callbackURL' ];
            $callback_info = parse_url( $callback_url );
            $get_params = array();
            parse_str( $callback_info['query'], $get_params );

            $userAccessToken = $row[ 'userAccessToken' ];

            $user = $this->user_info( $userAccessToken );
            if( ! $user ){
                $this->status->error( "unregistered user for $userAccessToken" );
            }

            if( isset( $row['summaryId'] ) ){
                $fnamebase = sprintf( '%s.fit', $row['summaryId'] );
            }else{
                $fnamebase = sprintf( '%s.fit', hash('sha1',sprintf( '%s%s', $userAccessToken, $callback_url ) ) );
            }
            if( isset( $row['userId'] ) ){
                $pathdir = sprintf( "assets/%s/", substr( $row['userId'], 0, 8 ) );
            }else{
                $pathdir = sprintf( "assets/%s/", substr( $row['userAccessToken'], 0, 8 ) );
            }
            $path = sprintf( '%s/%s', $pathdir, $fnamebase );

            if( isset($user['userAccessTokenSecret'] ) ){
                $userAccessTokenSecret = $user['userAccessTokenSecret'];

                if( isset($row['userId']) && !isset($user['userId'])){
                    $this->sql->insert_or_update('tokens', array('userId'=>$row['userId'], 'token_id'=>$user['token_id']), array( 'token_id' ) );
                }
            
                $url = $callback_url;
                $ntries = 3;
                $nextwait = 60;
                $data = false;
                while( $ntries > 0 ){
                    $data = $this->get_url_data( $url, $userAccessToken, $userAccessTokenSecret );
                    if( $data && $this->validate_fit_file($data) ){
                        $ntries = 0;
                    }else{
                        if( $this->verbose ){
                            print( "Error: Failed to get callback data for $cbid, sleeping $nextwait".PHP_EOL );
                        }
                        $ntries-=1;
                        if( $ntries > 0 ){
                            $this->sleep( $nextwait );
                            $nextwait *= 2;
                        }
                    }
                }

                if($data === false ){
                    $this->status->error( 'Failed to get data' );

                    $row = array(
                        'tablename' => $table,
                        'file_id' => $cbid,
                        'message' => 'No Data for callback url',
                        'callbackURL' => $callback_url,
                        'userAccessToken' => $userAccessToken
                    );
                    $this->status->record($this->sql, $row );
                    if( $this->verbose ){
                        print( "Error: Failed repeatedly to get callback data for $cbid, skipping".PHP_EOL );
                    }
                }else{
                    if( $save_to_file ){
                        if( ! file_exists( $pathdir ) ){
                            mkdir( $pathdir, 0777, true );
                        }
                        file_put_contents($fname, $data);

                        $row = array(
                            'tablename' => $table,
                            'file_id' => $cbid,
                            'path' => $fname,
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
                                printf( 'EXECUTE: %s'.PHP_EOL, $query );
                            }
                            $null = NULL;
                            $stmt->bind_param('bis',$null,$cbid,$table );
                            $stmt->send_long_data(0,$data);
                            if (!$stmt->execute()) {
                                $this->status->error(  "Execute failed: (" . $stmt->errno . ") " . $stmt->error );
                                if( $this->verbose ){
                                    printf( 'ERROR: %s [%s] '.PHP_EOL,  $stmt->error, $query);
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

    /**
     *   This function is called from the REST API 
     *   and will trigger a back fromm process from start_year if
     *   not already done
     */
    function backfill_api_entry($token_id, $start_year, $force = false){
        $year = max(intval($start_year),2000);
        $date = mktime(0,0,0,1,1,$year);
        $status = $this->backfill_should_start( $token_id, $date, $force );
        if( $status['start'] == true ){
            // 3 days at a time, every 4 seconds
            // should match the 90 days in 120 seconds throttle
            $this->backfill_start_process( $token_id, $date, 3, 4 );
        }
        return( $status );
        
    }

    /**
     *    This function will check that the back fill from the start date
     *    is necessary by checking if either no back fill were registered
     *    or the date is earlier than the last backfill.
     *    If a backfill is currently running it will also return false to
     *    avoid running multiple backfill at the same time
     */
    function backfill_should_start( $token_id, $start_date, $force = false ){
        $rv = array( 'start' => true, 'status' => 'New account, starting synchronisation with garmin' );
        
        $row = $this->sql->query_first_row( sprintf( 'SELECT UNIX_TIMESTAMP(MAX(ts)),MIN(summaryStartTimeInSeconds),MAX(summaryEndTimeInSeconds),MAX(backfillEndTime) FROM backfills WHERE token_id = %d', $token_id ) );

        # something already
        if( $row && isset( $row['UNIX_TIMESTAMP(MAX(ts))'] ) && $row['UNIX_TIMESTAMP(MAX(ts))'] ){
            // We had one already, no need to start new one
            $rv['start'] = false;
            
            $oldthreshold = time() - (15 * 60 );
            if( $row['UNIX_TIMESTAMP(MAX(ts))'] > $oldthreshold && $row['MAX(summaryEndTimeInSeconds)'] < $row['MAX(backfillEndTime)']){

                $eta = ($row['MAX(backfillEndTime)'] - $row['MAX(summaryEndTimeInSeconds)'])/(90*3600*24)*100;


                $eta_min = intval($eta / 60 );
                $eta_sec = $eta - ( $eta_min * 60.0 );
                
                $rv['status'] = sprintf( 'Synchronisation of new account with garmin still running (completed %s to %s, Estimated remaining time %d:%d)',
                                         strftime( '%Y-%m-%d', $row['MIN(summaryStartTimeInSeconds)'] ),
                                         strftime( '%Y-%m-%d', $row['MAX(summaryEndTimeInSeconds)'] ),
                                         $eta_min, $eta_sec );
            }else{
                $rv['status'] = sprintf( 'Synchronisation from %s to %s completed on %s',
                                         strftime( '%Y-%m-%d', $row['MIN(summaryStartTimeInSeconds)'] ),
                                         strftime( '%Y-%m-%d', $row['MAX(summaryEndTimeInSeconds)'] ),
                                         strftime( '%Y-%m-%d', $row['MAX(backfillEndTime)'] ) );
            }
            // Unless it's an incomplete one for more than 15min, then restart (it should throttle 2min max)
            if( $row['MAX(summaryEndTimeInSeconds)'] < $row['MAX(backfillEndTime)'] && $row['UNIX_TIMESTAMP(MAX(ts))'] < $oldthreshold){
                $rv['start'] = true;

                $rv['status'] = sprintf( 'Previous Synchronisation with Garmin account seems stalled at %s (now %s)', strftime("%Y-%m-%d, %H:%M:%S", $row['UNIX_TIMESTAMP(MAX(ts))']), strftime("%Y-%m-%d, %H:%M:%S", time() ) );
            }

            // or if asked year is before current start
            if( $row['MIN(summaryStartTimeInSeconds)'] > $start_date ){
                $rv['start'] = true;
                $rv['status'] = sprintf( 'Previous s did not go back far enough %s < %s',
                                         strftime("%Y-%m-%d", $start_date ),
                                         strftime("%Y-%m-%d", $row['MIN(summaryStartTimeInSeconds)'] ) );
            }
        }
        if( $force ){
            $rv['start'] = true;
        }
        return $rv;
    }

    /**
     *   Start the backfill process by executing the first
     *   backfill command in the background
     */
    function backfill_start_process( $token_id, $date, $days, $sleep ){
        $this->ensure_schema();

        $this->status->clear('backfills');

        $user = $this->sql->query_first_row( "SELECT userAccessToken,userAccessTokenSecret FROM tokens WHERE token_id = $token_id" );

        if( isset( $user['userAccessToken'] ) && isset( $user['userAccessTokenSecret'] ) ){
            // start and get rid of old one if exists
            $this->sql->execute_query( "DELETE FROM backfills WHERE token_id = $token_id" );
            
            $row = array( 'token_id' => $token_id,

                          'summaryStartTimeInSeconds' => $date,
                          'summaryEndTimeInSeconds' => $date,
                          'backfillEndTime' => time()
            );
            $this->sql->insert_or_update( 'backfills', $row );
            $this->exec_backfill_cmd($token_id, $days, $sleep );
        }
    }
    
    function run_backfill( $token_id, $days, $sleep ){
        # Start from 2010, record request time
        #  move 90 days forward from 2010 until reach request time
        # return true is more to do, false if reach end
        
        $this->ensure_schema();

        $save_to_file = false;
        
        $this->status->clear('backfills');

        $user = $this->sql->query_first_row( "SELECT * FROM tokens WHERE token_id = $token_id" );

        $moreToDo = false;
        
        if( isset( $user['userAccessToken'] ) && isset( $user['userAccessTokenSecret'] ) ){
            $sofar = $this->sql->query_first_row( "SELECT token_id,MIN(summaryStartTimeInSeconds),MAX(summaryEndTimeInSeconds),MAX(backfillEndTime) FROM backfills WHERE token_id = '$token_id' GROUP BY token_id" );
        
            if(isset($sofar['MAX(summaryEndTimeInSeconds)'] ) ){
                $start = $sofar['MAX(summaryEndTimeInSeconds)'];
                $backfillend = $sofar['MAX(backfillEndTime)'];
                $backfillstart = $sofar['MIN(summaryStartTimeInSeconds)'];

                $end = $start + (24*60*60*$days);
                $moreToDo = ($end < $backfillend);
                $next = array( 'token_id' => $token_id,
                               'summaryStartTimeInSeconds' => $start,
                               'summaryEndTimeInSeconds' => $end,
                               'backfillEndTime'=>$backfillend );

                $url = sprintf( $this->api_config['url_backfill_activities'], $next['summaryStartTimeInSeconds'], $next['summaryEndTimeInSeconds'] );
                $data = $this->get_url_data($url, $user['userAccessToken'], $user['userAccessTokenSecret']);
                if( true || $this->status->success() ){
                    $this->sql->insert_or_update( 'backfills', $next );
                }
            }
            if( $moreToDo ){
                if( $this->verbose ){
                    printf( 'Requested %s days, Sleeping %s secs', $days, $sleep );
                }
                $this->sleep($sleep);
                $this->exec_backfill_cmd($token_id, $days, $sleep );
            }else{
                $row = array( 'backfillEndTime' => $backfillend, 'backfillStartTime' => $backfillstart,'token_id' => $token_id );
                $this->sql->insert_or_update( 'tokens', $row, array( 'token_id' ));
                $row = array( 'backfillEndTime' => $backfillend, 'backfillStartTime' => $backfillstart, 'cs_user_id' => $user['cs_user_id'] );
                $this->sql->insert_or_update( 'users', $row, array( 'cs_user_id' ));
            }
        }

        return $moreToDo;
    }

    function sleep( $seconds ){
        sleep( $seconds );
        // After sleep, sql connect likely to time out
        $this->sql = new garmin_sql();
        $this->sql->verbose = $this->verbose;
    }
    
    function maintenance_after_process($table,$row,$json){
        $to_set = array();
        $other_to_set = array();


        // if multi sport sub activity

        if( isset( $json['parentSummaryId'] ) && $table == 'activities' ){
            $query = sprintf( "SELECT activity_id FROM activities WHERE summaryId = '%s'", $json['parentSummaryId'] );
            $parentrow = $this->sql->query_first_row( $query );
            if( isset( $parentrow['activity_id'] ) ){
                $parent_id = $parentrow['activity_id'];
                $this->sql->execute_query( sprintf( 'UPDATE activities SET parent_activity_id = %d WHERE summaryId = %d', $parent_id, $row['summaryId'] ) );
            }
        }

        //
        // First see if we can update users
        //
        $cs_user_id = NULL;
        if( isset( $row['summaryId'] )){
            $fullrow = $this->sql->query_first_row( sprintf( "SELECT * FROM `%s` WHERE summaryId = '%s'", $table, $row['summaryId'] ) );

            if( !$fullrow['cs_user_id'] && isset( $row['userAccessToken'] ) && isset( $row['userId'] ) ){
            
                $userInfo = $this->user_info($row['userAccessToken']);
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
            
            if( $other_table && (isset( $row['userId'] ) && isset( $row['startTimeInSeconds' ] ) ) ){
                $query = sprintf( "SELECT * FROM `%s` WHERE userId = '%s' AND startTimeInSeconds = %d", $other_table, $row['userId'], $row['startTimeInSeconds'] );
                $found = $this->sql->query_as_array( $query );

                if( count( $found ) == 1 ){
                    $found = $found[0];
                    if( ! $found[$table_key] ){
                        if( $fullrow[$table_key] ){
                            $query = sprintf( 'UPDATE `%s` SET %s = %d WHERE %s = %d', $other_table, $table_key, $fullrow[$table_key], $other_key, $found[$other_key] );
                            $this->sql->execute_query( $query );
                        }
                    }
                    if( ! $fullrow[$other_key] ){
                        array_push( $to_set, sprintf( '%s = %d', $other_key, $found[$other_key] ) );
                    }
                }
            }
        
            if( count( $to_set ) ){
                $query = sprintf( "UPDATE `%s` SET %s WHERE summaryId = '%s'".PHP_EOL,  $table, implode( ', ', $to_set), $row['summaryId'] );
                if( ! $this->sql->execute_query( $query ) ){
                    printf( "ERROR %s",  $this->sql->lasterror );
                }
            }
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
    function maintenance_fix_missing_callback($cs_user_id = NULL){
        if( $cs_user_id ){
            $query = sprintf( 'SELECT * FROM fitfiles WHERE ISNULL(asset_id) AND cs_user_id = %d', intval($cs_user_id) );
        }else{
            $query = 'SELECT * FROM fitfiles WHERE ISNULL(asset_id)';
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
                printf( 'No missing callback found'.PHP_EOL );
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
                    printf( 'Missing activity for %s'.PHP_EOL, strftime("%Y-%m-%d, %H:%M:%S", $startTime ) );
                }
                if( $mintime == NULL ||  $startTime < $mintime ){
                    $mintime = $startTime;
                }
                if( $maxtime == NULL || $startTime > $maxtime ){
                    $maxtime = $startTime;
                }
            }
        }

        if( count( $res ) > 0){
            printf( 'Missing %d activities between %s and %s'.PHP_EOL, count( $res ), strftime("%Y-%m-%d", $mintime ), strftime("%Y-%m-%d", $maxtime ) );
            $endtime = $mintime + (24*60*60*90); // 90 is per max throttle from garmin
            $url = sprintf( $this->api_config['url_backfill_activities'], $mintime, $endtime );
            $user = $this->user_info_for_token_id( 1 );
            $data = $this->get_url_data($url, $user['userAccessToken'], $user['userAccessTokenSecret']);
        }
    }

    function maintenance_export_table( $table, $key, $key_start ){
        $done = false;
        if( is_writable( 'tmp' ) ){
            $db = $this->api_config['database'];
            $outfile = sprintf( 'tmp/%s_%s.sql', $table, $key_start );
            $logfile = sprintf( 'tmp/%s_%s.log', $table, $key_start );
            $defaults = sprintf( 'tmp/.%s.cnf', $db );
            file_put_contents( $defaults, sprintf( '[mysqldump]'.PHP_EOL.'password=%s'.PHP_EOL, $this->api_config['db_password'] ) );
            chmod( $defaults, 0600 );
            $limit = '';
            if( $table == 'assets' ){
                // Special case
                $limit = ' LIMIT 250';
            }
            $command = sprintf( 'mysqldump --defaults-file=%s -t --hex-blob --result-file=%s -u %s %s %s --where "%s>%s%s"', $defaults, $outfile, $this->api_config['db_username'], $db, $table, $key, $key_start, $limit );
            if( $this->verbose ){
                printf( 'Exec %s<br />'.PHP_EOL, $command );
            }
            exec( "$command > $logfile 2>&1" );

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
            }
        }
        return $done;
    }

    function maintenance_writable_path($def = 'tmp'){
        if( isset( $this->api_config[$def] ) && is_writable( $this->api_config[$def] ) ){
            return $this->api_config[$def];
        }else{
            return 'tmp';
        }
    }

    function maintenance_backup_table( $table, $key ){
        // optional setting

	    $tmp_path = $this->maintenance_writable_path('tmp');
        $backup_path = $this->maintenance_writable_path('backup_path');

        if( isset( $this->api_config['url_backup_source'] ) && is_writable( $tmp_path ) && is_writable( $backup_path )){
            $last = $this->sql->query_first_row( sprintf( 'SELECT MAX(%s) FROM %s', $key, $table ) );
            $last_key = intval($last[ sprintf( 'MAX(%s)', $key ) ]);
            $database = $this->api_config['database'];
            $url_src = $this->api_config['url_backup_source'];
            $url = sprintf( '%s/api/garmin/backup?database=%s&table=%s&%s=%s', $url_src, $database, $table, $key, $last_key  );
            printf( 'CURL: %s'.PHP_EOL,  $url );
            
            $sql_out = sprintf( '%s/backup_%s_%s.sql', $backup_path, $table, $last_key );
            file_put_contents( $sql_out, $this->get_url_data( $url, $this->api_config['serviceKey'], $this->api_config['serviceKeySecret'] ) );

            $defaults = sprintf( '%s/.%s.cnf', $tmp_path, $database );
            file_put_contents( $defaults, sprintf( '[mysql]'.PHP_EOL.'password=%s'.PHP_EOL, $this->api_config['db_password'] ) );
            chmod( $defaults, 0600 );
            $command = sprintf( 'mysql --defaults-file=%s -u %s -h %s %s < %s', $defaults, $this->api_config['db_username'], $this->api_config['db_host'], $database, $sql_out );
            printf( 'EXEC: %s'.PHP_EOL,  $command );
            system(  $command );
        }
    }

    function query_file( $cs_user_id, $activity_id, $file_id ){
        if( $file_id ){
            $query = "SELECT data FROM assets WHERE file_id = $file_id";
        }else if( $activity_id ){
            $check = $this->sql->query_first_row( "SELECT activity_id,parent_activity_id FROM activities WHERE activity_id = $activity_id" );
            if( isset( $check['parent_activity_id'] ) ){
                $use_activity_id = $check['parent_activity_id'];
            }else{
                $use_activity_id = $activity_id;
            }
                
            $query = "SELECT data FROM activities act, assets ast WHERE act.file_id = ast.file_id AND act.activity_id = $use_activity_id";
        }
        if( $this->sql->verbose ){
            printf( 'EXECUTE: %s'.PHP_EOL, $query );
        }
        $stmt = $this->sql->connection->query($query);
        if( $stmt ){
            $results = $stmt->fetch_array( MYSQLI_ASSOC );
            if( $results ){
                return $results['data'];
            }
        }
        return NULL;
    }

    function query_activities_from_id( $cs_user_id, $from_activity_id, $limit ){
        $to_activity_id = $from_activity_id + $limit;
        $query = "SELECT activity_id,parent_activity_id,json FROM activities WHERE cs_user_id = $cs_user_id and activity_id >= $from_activity_id and activity_id <= $to_activity_id ORDER BY startTimeInSeconds DESC";
        
        $json = $this->query_activities_json($query);
        
        $query = "SELECT COUNT(json) FROM activities WHERE cs_user_id = $cs_user_id";
        $count = $this->sql->query_first_row( $query );

        $rv = array( 'activityList' => $json, 'paging' => array( 'total' => intval( $count['COUNT(json)'] ), 'from_activity_id' => intval($from_activity_id), 'limit' => intval($limit) ));
        
        print( json_encode( $rv ) );

    }
    
    function query_activities( $cs_user_id, $start, $limit ){
        $query = "SELECT activity_id,parent_activity_id,json FROM activities WHERE cs_user_id = $cs_user_id ORDER BY startTimeInSeconds DESC LIMIT $limit OFFSET $start";
        $json = $this->query_activities_json($query);

        $query = "SELECT COUNT(json) FROM activities WHERE cs_user_id = $cs_user_id";
        $count = $this->sql->query_first_row( $query );

        $rv = array( 'activityList' => $json, 'paging' => array( 'total' => intval( $count['COUNT(json)'] ), 'start' => intval($start), 'limit' => intval($limit) ));
        
        print( json_encode( $rv ) );
    }

    // Replicate format of back fill
    function query_backfill( $cs_user_id, $from_activity_id, $start, $limit ){
        if( $from_activity_id == 0 ){
            $query = "SELECT activity_id,json,userId,userAccessToken FROM activities WHERE cs_user_id = $cs_user_id ORDER BY startTimeInSeconds DESC LIMIT $limit OFFSET $start";
        }else{
            $to_activity_id = $from_activity_id + $limit;
            $query = "SELECT activity_id,json,userId,userAccessToken FROM activities WHERE cs_user_id = $cs_user_id and activity_id >= $from_activity_id and activity_id <= $to_activity_id ORDER BY startTimeInSeconds DESC";
        }
        
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
};
    
?>
