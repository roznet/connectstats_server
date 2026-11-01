<?php   

$bug_config = array(
    'database' => '***',
    'db_host' => '***',

    'db_username' => '***',
    'db_password' => '***',

    'bug_data_directory' => 'bugs',

    // OPTIONAL email notification of new reports
    //'email_bug_to' => '***',
    // OPTIONAL send through the Resend HTTP API instead of PHP mail() (needed in Docker).
    // The email_from domain must be verified in Resend.
    //'resend_api_key' => '***',
    //'email_from' => 'ConnectStats <bugreport@connectstats.app>',

);
?>
