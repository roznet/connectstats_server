<?php

include_once('../shared.php');

$required_fields = array(
    'callbackURL', 'startTimeInSeconds', 'summaryId'
);
$unique_keys = array( 'startTimeInSeconds', 'summaryId' );

$process = new GarminProcess();

if( ! $process->process('fitfiles', $required_fields, $unique_keys ) ){
    header('HTTP/1.1 500 Internal Server Error');
}

?>
