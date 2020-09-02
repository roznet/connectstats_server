<?php
/**
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
 *  Configuration to use for the server
 */

$api_config = array(
    // The name of the database to use in mysql server
    'database' => '',

    // The Consumer Key and Secret provided by Garmin Heatlh API
    'consumerKey' => '',
    'consumerSecret' => '',

    // A Service Key and Secret that need to be consistent between
    // The server and a client used to issue maintenance/system call
	'serviceKey' => '',
	'serviceKeySecret' => '',

    // The configuration to access the mysql server
	'db_host' => '',
	'db_username' => '',
	'db_password' => '',

    // The database to use to manage the queue
    'db_queue' => '',

    // The url to use for call back to the Garmin Health API
    // These are parameter to a test server can use alternative url
	'url_user_id' => 'https://healthapi.garmin.com/wellness-api/rest/user/id',

    // OPTIONAL Config
    // A path to a tmp directory to use, in case the default directory can't be used
    // For permissions issues. by default save in 'tmp'  in current dir
    'tmp' => 'tmp',
    // OPTIONAL Config for keeping log files
    'log' => 'log',

    // OPTIONAL Key to the dark Sky Net API or other weather provider, so weather can be downloaded for
    // activities
    //'darkSkyKey' => '',
    //'visualCrossingKey' => '',
    //'openWeatherMapKey' => '',


    // OPTIONAL Url to the server to do an incremental backup of
    //'url_backup_source' => '',
		
    // OPTIONAL name of the s3 bucket to save data into
    //          if of the form 'localhost:/path/to/dir', the data will be saved on a local disk
    //          if not provided the data will be saved in the mysql db (not recommended)
    'save_to_s3_bucket' => '',

    // OPTIONAL name of an s3 bucket to backup data from. This is used by backup script in setup
    //'backup_from_s3_bucket' => '',

    // Keys for S3 acccess
    's3_access_key' => '',
    's3_secret_key'=> '',
    's3_region'=>'',

    // Threshold to ignore activities when getting data from server
    'ignore_activities_months_threshold' => 12,
    // Threshold to ignore fit extraction, weather queries
    'ignore_fitextract_hours_threshold' => 24*5,
    // OPTIONAL a date to ignore any activities before that date in any processing
    //'ignore_activities_date_threshold' => 1575158293,
    
);
?>
