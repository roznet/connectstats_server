<?php

include_once('../shared.php');

$process = new GarminProcess();

$process->authenticate_system_call();

$done = false;
if( isset( $_GET['database'] ) && $_GET['table'] ){
    $db = $_GET['database'];
    $table = $_GET['table'];
    $keys = array( 'activities' => 'activity_id',
                   'backfills' => 'backfill_id',
                   'assets' => 'asset_id',
                   'fitfiles' => 'file_id',
                   'tokens' => 'token_id',
                   'users' => 'user_id'
    );

    if( isset( $keys[ $table ] ) && isset( $_GET[ $keys[ $table ] ] ) ){
        $key = $keys[$table];
        $key_start = $_GET[ $keys[$table] ];
        if( is_writable( 'tmp' ) ){
            $outfile = sprintf( 'tmp/%s_%s.sql', $table, $key_start );
            $logfile = sprintf( 'tmp/%s_%s.log', $table, $key_start );
            $defaults = sprintf( 'tmp/.%s.cnf', $db );
            file_put_contents( $defaults, sprintf( '[mysqldump]'.PHP_EOL.'password=%s'.PHP_EOL, $process->api_config['db_password'] ) );
            chmod( $defaults, 0600 );
            $command = sprintf( 'mysqldump --defaults-file=%s -t --result-file=%s -u %s %s %s --where "%s>%s"', $defaults, $outfile, $process->api_config['db_username'], $db, $table, $key, $key_start );
            if( $process->verbose ){
                printf( 'Exec %s<br />'.PHP_EOL, $command );
            }
            exec( "$command > $logfile 2>&1" );

            if( is_readable( $outfile ) ){
                if( $process->verbose ){
                    printf( 'Output: %s (%s bytes)<br />', $outfile, filesize( $outfile ) );
                    print( '<code>' );
                    readfile( $logfile );
                    print( '</code>' );
                }else{
                    header('Content-Type: application/sql');
                    header(sprintf('Content-Disposition: attachment; filename=%s', $outfile ));
                    readfile( $outfile );
                }
                $done = true;
            }
        }
    }
}
if( ! $done ){
    header( 'HTTP/1.1 400 Bad Request' );
    die;
}
?>
