<?php
header('Content-Type: application/json');
$status = array(
    'status' => 1,
    //'redirect' => 'https://connectstats.app/prod'
);
print( json_encode( $status ) );
?>
