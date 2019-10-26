<?php

include_once( '../api/shared.php' );

$process = new GarminProcess();
$process->set_verbose( true );

$process->ensure_schema();

?>
