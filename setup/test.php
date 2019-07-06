<?php

include_once( '../api/shared.php' );

class Test {
    function __construct(){
        $this->process = new GarminProcess();
        include( 'test_config.php' );
        $this->config = $test_config;
    }

    function run_sign(){

        $token_id = $this->process->validate_input_id( $this->args[0] );
        $url = $this->process->validate_url( $this->args[1] );

        $this->process->set_verbose( true );

        print( $this->process->authorization_header_for_token_id( $url, $token_id  ) .PHP_EOL );
        
    }

    function run_curl(){

        $token_id = $this->process->validate_input_id( $this->args[0] );
        $url = $this->process->validate_url( $this->args[1] );

        $this->process->set_verbose( true );
        $user = $this->process->user_info_for_token_id( $token_id );

        $data = $this->process->get_url_data( $url, $user['userAccessToken'], $user['userAccessTokenSecret'] );
        print( $data );
    }


    function run_setup_local(){
        $base_url = 'http://localhost/dev';
        $commands = array( 
            sprintf( 'curl  -v "%s/api/connectstats/reset"', $base_url ),
            sprintf( 'curl  -v "%s/api/connectstats/user_register?userAccessToken=testtoken&userAccessTokenSecret=testsecret"', $base_url ),
            # upload some fit files from the simulator
            sprintf( 'curl  -v -H "Content-Type: application/json;charset=utf-8" -d @sample-file-local.json "%s/api/garmin/file"', $base_url ),
            # upload activities
            sprintf( 'curl  -v -H "Content-Type: application/json;charset=utf-8" -d @sample-backfill-activities.json "%s/api/garmin/activities"', $base_url )
        );
        foreach( $commands as $command ){
            printf( 'Starting: %s'.PHP_EOL,  $command );
            exec( "$command ");
        }
        
    }
    
    function run_command($command, $args){
        $this->args = $args;
        $this->command = $command;
        
        switch( $command ){
        case "curl":
            $this->run_curl(  );
            break;
        case "sign":
            $this->run_sign(  );
            break;
            
        case "setuplocal":
            $this->run_setup_local( $args );
            break;
        }

    }
}


if( isset( $argv[1] ) ){
    $command = $argv[1];
    $args = array_slice( $argv, 2 );

    $test = new Test();
    $test->run_command( $command, $args );

}

?>
