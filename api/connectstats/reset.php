<?php
// This script will reset and delete everything from the database
// This will only work on development database, prod database should be unaffected

include_once('../shared.php' );

$process = new GarminProcess();

if( ! $process->reset_schema() ){
    header('HTTP/1.1 401 Unauthorized error');
    die;
}
    
?>
