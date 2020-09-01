<?php
include( '../config.php' );
header('Content-Type: application/json');
$status = array(
    'status' => 1,
);

# This should be full url of the base of redirect api, for example 'https://myserver.example.com/dev'
if( isset( $api_config['api_redirect_url'] ) ){
    $status[ 'redirect' ] = $api_config['api_redirect_url'];
}
print( json_encode( $status ) );
?>
