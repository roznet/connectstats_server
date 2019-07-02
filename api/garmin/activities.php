<?php

/*
 * Tight script to save pings from garmin
 * Expected to be called a lot, so should be lightweight and robust

 */

include_once('../shared.php');

$required_fields = array(
    'summaryId', 'startTimeInSeconds'
);

$process = new GarminProcess();

if( ! $process->process('activities', $required_fields ) ) {
    header('HTTP/1.1 500 Internal Server Error');
}
   
?>
