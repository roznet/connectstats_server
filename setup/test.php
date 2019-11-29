<?php

include_once( '../api/shared.php' );
include_once( "../api/phpFITFileAnalysis.php" );

class ServerTest {
    function __construct(){
        $this->process = new GarminProcess();
        include( 'test_config.php' );
        $this->config = $test_config;
    }

    function assert( $true_or_false, $message ){
        if( $true_or_false ){
            printf( 'SUCCESS: %s'.PHP_EOL, $message );
        }else{
            printf( 'FAILURE: %s'.PHP_EOL, $message );
        }
    }

    function validate_activities(){
        $reloaded = json_decode( file_get_contents( 't.json' ), true );
        $sent = json_decode( file_get_contents( 'sample-backfill-activities.json' ), true );

        $this->assert( count( $reloaded['activityList'] ) == count( $sent['activities'] ), sprintf( 'backfill sent %d and recovered %d activities',count( $sent['activities'] ),count( $reloaded['activityList'] ) ) );
        
        $this->cache_reloaded = array();
        $this->cache_cs_id = array();
        foreach( $reloaded['activityList'] as $one ){
            $this->cache_reloaded[ $one['summaryId'] ] = $one;
            $this->cache_cs_id[ $one['cs_activity_id'] ] = $one;
        }

        $this->cache_sent = array();
        foreach( $sent['activities'] as $one ){
            $summaryId = $one['summaryId'];
            if( isset( $this->cache_sent[ $summaryId ] ) ){
                printf( 'Duplicate %s'.PHP_EOL, $summaryId );
            }

            $this->cache_sent[ $summaryId ] = $one;
            if( ! isset( $this->cache_reloaded[ $summaryId ] ) ){
                printf( 'Missing %s'.PHP_EOL, $summaryId );
            }
        }
        foreach( $this->cache_cs_id as $one ){
            if( isset( $one['cs_parent_activity_id' ] )){
                $this->assert( isset( $this->cache_cs_id[ $one['cs_parent_activity_id'] ] ), sprintf( 'parent %s of %s exists', $one['cs_parent_activity_id'], $one['cs_activity_id'] ) );
            }

            if( isset( $one['isParent'] ) ){
                $found = 0;
                foreach( $this->cache_cs_id as $sub ){
                    if( isset( $sub['cs_parent_activity_id'] ) && $sub['cs_parent_activity_id'] == $one['cs_activity_id'] ){
                        $found += 1;
                    }
                }
                $this->assert( $found > 0, sprintf( 'activity %s found %d children which is more than 0', $one['cs_activity_id'],  $found ) );
            }
        }

    }

    function validate_fit_file(){
        $fit = new adriangibbons\phpFITFileAnalysis( 't.fit' );
        $start_time = $fit->data_mesgs['session']['start_time'];

        $json = json_decode( file_get_contents( 'f.json' ), true );
        $this->assert( count( $json['fitsession'] ) > 0, sprintf( 'got %d fit sessions which is more than 0', count( $json['fitsession'] ) ) );
        $extract = $json['fitsession'][0];
        
        $fit_activity_id = intval( $extract['activity_id'] );

        $summary = $this->cache_cs_id[$fit_activity_id];

        $this->assert( $summary['startTimeInSeconds'] == $start_time, sprintf( 'start time recorded %s matches fit file %s', $summary['startTimeInSeconds'], $start_time ) );
    }
}


if( isset( $argv[1] ) ){
    $command = $argv[1];
    $args = array_slice( $argv, 2 );

    $test = new ServerTest();
    $test->validate_activities();
    $test->validate_fit_file();
}

?>
