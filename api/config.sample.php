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

    // The url to use for call back to the Garmin Health API
    // These are parameter to a test server can use alternative url
	'url_user_id' => 'https://healthapi.garmin.com/wellness-api/rest/user/id',
	'url_backfill_activities' => 'https://healthapi.garmin.com/wellness-api/rest/backfill/activities?summaryStartTimeInSeconds=%s&summaryEndTimeInSeconds=%s'

    // OPTIONAL Config
    // A path to a tmp directory to use, in case the default directory can't be used
    // For permissions issues
    //'tmp' => '',

    // Key to the dark Sky Net API, so weather can be downloaded for
    // activities
    //'darkSkyKey' => '',

    // Url to the server to do an incremental backup of
    //'url_backup_source' => '',
);
?>
