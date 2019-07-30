<?php

include_once( "../shared.php" );

$process = new GarminProcess();
$process->set_verbose(true);
$process->ensure_schema();
$process->maintenance_fix_missing_callback();
$process->maintenance_link_activity_files();
?>
