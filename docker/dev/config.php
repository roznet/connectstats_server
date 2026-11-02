<?php
/**
 *  Local Docker development config (docker-compose.yml mounts it as api/config.php).
 *  Dummy secrets only: the DB is local and Garmin's user-id endpoint is mocked.
 *  Never use this file on a server. Production config is mounted from outside the repo.
 *
 *  Keep each value on one line as 'key' => 'value', : setup/connectstats_test/config.py
 *  parses this file with a regex.
 */

$api_config = array(
    'database' => 'connectstats_dev',
    'db_queue' => 'connectstats_queue',

    'db_host' => 'db',
    'db_username' => 'connectstats',
    'db_password' => 'devpassword',

    'consumerKey' => 'local-consumer-key',
    'consumerSecret' => 'local-consumer-secret',
    'serviceKey' => 'local-service-key',
    'serviceKeySecret' => 'local-service-secret',

    // Mock of Garmin's user id endpoint, served by the app container (docker/dev/mock).
    'url_user_id' => 'http://app/mock/garmin/user_id.php',
    'url_user_registration' => 'http://app/mock/garmin/user_registration.php',

    'tmp' => '/var/www/tmp',
    'log' => '/var/www/log',
    's3_cache_local' => '/var/www/tmp/s3_cache',

    // FIT files stored on local disk instead of S3.
    'save_to_s3_bucket' => 'localhost:/var/www/data/assets',

    // Workers are owned by the worker container, never spawned by web requests.
    'queue_autostart' => false,
    'queue_count' => 2,

    // No push notifications, weather queries or S3 writes.
    'sandbox' => true,

    'ignore_activities_months_threshold' => 12,
    'ignore_fitextract_hours_threshold' => 24*5,
);
?>
