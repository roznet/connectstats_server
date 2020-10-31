<?php
/*
 *  MIT Licence
 *
 *  Copyright (c) 2019 Brice Rosenzweig.
 *
 *  Permission is hereby granted, free of charge, to any person obtaining a copy
 *  of this software and associated documentation files (the "Software"), to deal
 *  in the Software without restriction, including without limitation the rights
 *  to use, copy, modify, merge, publish, distribute, sublicense, and/or sell
 *  copies of the Software, and to permit persons to whom the Software is
 *  furnished to do so, subject to the following conditions:
 *  
 *  The above copyright notice and this permission notice shall be included in all
 *  copies or substantial portions of the Software.
 *  
 *  THE SOFTWARE IS PROVIDED "AS IS", WITHOUT WARRANTY OF ANY KIND, EXPRESS OR
 *  IMPLIED, INCLUDING BUT NOT LIMITED TO THE WARRANTIES OF MERCHANTABILITY,
 *  FITNESS FOR A PARTICULAR PURPOSE AND NONINFRINGEMENT. IN NO EVENT SHALL THE
 *  AUTHORS OR COPYRIGHT HOLDERS BE LIABLE FOR ANY CLAIM, DAMAGES OR OTHER
 *  LIABILITY, WHETHER IN AN ACTION OF CONTRACT, TORT OR OTHERWISE, ARISING FROM,
 *  OUT OF OR IN CONNECTION WITH THE SOFTWARE OR THE USE OR OTHER DEALINGS IN THE
 *  SOFTWARE.
 *  
 *
 */


error_reporting(E_ALL);

include_once( '../api/shared.php' );

$process = new GarminProcess();

$process->ensure_commandline($argv);
$process->ensure_schema();

$file_id = $argv[1];

$process->set_verbose( true );

$row = $process->sql->query_first_row( sprintf( 'SELECT `json` FROM weather WHERE file_id = %d', intval( $file_id ) ) );
$res  = json_decode( $row['json'], true );

$lat = $res['darkSky']['latitude'];
$lon = $res['darkSky']['longitude'];

$st = $res['darkSky']['currently']['time'];
$ts = $st + 3600.0;
$key = $process->api_config['visualCrossingKey'];

$params = array(
    'aggregateHours'=>1,
    'combinationMethod'=>'aggregate',
    'collectStationContributions'=>'false',
    'maxStations'=>-1,
    'maxDistance'=>100000,
    'includeNormals'=>'false',
    'contentType'=>'json',
    'unitGroup'=>'metric',
    'locationMode'=>'single',
    'locations'=>sprintf( '%f,%f', $lat, $lon ),
    'startDateTime'=>$st,
    'endDateTime'=>$ts,
    'key'=>$key,
    'iconSet'=>'icons1'
);
print_r( $params );
$url = sprintf('https://weather.visualcrossing.com/VisualCrossingWebServices/rest/services/weatherdata/history?%s',http_build_query( $params ) );
print( $url.PHP_EOL );
$web = $process->get_url_data( $url, NULL, NULL );
file_put_contents( 't.json', $web );
