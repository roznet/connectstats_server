<?php

include_once( '../api/shared.php' );

$keys = array( 'activities' => 'activity_id',
               'backfills' => 'backfill_id',
               'assets' => 'asset_id',
               'fitfiles' => 'file_id',
               'tokens' => 'token_id',
               'users' => 'user_id'
);

$process = new GarminProcess();

$database = 'garmin';
$table = 'activities';
$key = $keys[$table];

$last = $process->sql->query_first_row( sprintf( 'SELECT MAX(%s) FROM %s', $key, $table ) );
$last_key = $last[ sprintf( 'MAX(%s)', $key ) ];
print_r( $last );

$url = sprintf( 'http://localhost/prod/api/garmin/backup?database=%s&table=%s&%s=%s', $database, $table, $key, $last_key -5 );
print( $url .PHP_EOL);

print( $process->get_url_data( $url, $process->api_config['serviceKey'], $process->api_config['serviceKeySecret'] ) );

?>
