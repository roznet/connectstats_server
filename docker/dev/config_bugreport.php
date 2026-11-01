<?php
// Local Docker development config for bugreport/ (dummy credentials, local DB only).
$bug_config = array(
    'database' => 'connectstats_bugs',
    'db_host' => 'db',
    'db_username' => 'connectstats',
    'db_password' => 'devpassword',
    'bug_data_directory' => '/var/www/bugs',
);
?>
