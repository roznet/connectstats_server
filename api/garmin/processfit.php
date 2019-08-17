<?php
include_once( "../shared.php" );
include_once( "../phpFITFileAnalysis.php" );

$process = new GarminProcess();

$process->ensure_commandline( $argv??NULL );

$process->set_verbose(true);

$debug = false;

if( isset( $argv[1] ) && isset( $argv[2] ) ){
    $token_id = $argv[1];
    $file_id = $argv[2];
    $data = $process->query_file( $token_id, NULL, $file_id );
    file_put_contents('t.fit', $data );

    if( strlen( $data ) ){
        $fit = new adriangibbons\phpFITFileAnalysis( $data, array( 'input_is_data' => true ) );
        if( isset( $fit->data_mesgs['session']['start_position_lat'] ) &&
            isset( $fit->data_mesgs['session']['start_position_long'] ) &&
            isset( $fit->data_mesgs['session']['timestamp'] ) &&
            isset( $fit->data_mesgs['session']['start_time'] ) ){
            $lat = $fit->data_mesgs['session']['start_position_lat'] ;
            $lon = $fit->data_mesgs['session']['start_position_long'] ;
            $ts =  $fit->data_mesgs['session']['timestamp'];
            $st = $fit->data_mesgs['session']['start_time'];

            if( $debug ){
                $data = file_get_contents( 't.json' );
            }else{
                if( $lat != 0.0 && $lon != 0.0 ){
                    $api_config = $process->api_config['darkSkyKey'];
                    $url = sprintf( 'https://api.darksky.net/forecast/%s/%f,%f,%d?units=si', $api_config, $lat, $lon, $st);

                    $ch = curl_init();

                    curl_setopt($ch, CURLOPT_URL, $url );
                    curl_setopt($ch, CURLOPT_RETURNTRANSFER, 1 );

                    if( $process->verbose ){
                        printf( "CURL: %s".PHP_EOL, $url );
                    }
                    $data = curl_exec($ch);
                    if( $data === false ) {
                        #$this->status->error( sprintf( 'CURL Failed %s', curl_error($ch ) ) );
                    }
                    curl_close($ch);
                }
            }
        }
        $weather = json_decode( $data, true );
        $keep_hourly = array();
        foreach( $weather['hourly']['data'] as $one ){
            $cur = $one['time'];
            $inside = ($cur > ($st-3600.0)) && ($cur < ($ts+3600.0) );
            if( $inside ){
                array_push( $keep_hourly, $one );
            }
        }
        $weather['hourly']['data'] = $keep_hourly;

        file_put_contents( 'w.json', json_encode( $weather ) );
    }
}
?>
