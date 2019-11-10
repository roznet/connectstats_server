<?php

include_once( "../api/phpFITFileAnalysis.php" );
try {
    $fit = new adriangibbons\phpFITFileAnalysis( 't.fit' );
    print_r( $fit->data_mesgs );
}catch(Exception $e ){
    printf( "ERROR: can't process fit file for id %d. Error: %s", $file_id, $e->getMessage() );
}


?>
