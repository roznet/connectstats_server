<?php

include_once('../shared.php');

$required_fields = array(
);

$process = new GarminProcess();

if( ! $process->deregister_user() ){
    header('HTTP/1.1 500 Internal Server Error');
}

?>
