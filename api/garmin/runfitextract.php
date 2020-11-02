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


/*
 *   
 *   usage: runfitextract.php file_id
 *       Arg: file_id should be a file_id from the table fitfiles, for which the asset was downloaded
 *
 *       Description: if the corresponding asset from the file_id is a fit file:
 *                            - parse the fit file and extract the session into the table fit_session
 *                            - if gps coordinate exist fetch weather information
 */


include_once( "../shared.php" );
include_once( "../phpFITFileAnalysis.php" );

$process = new GarminProcess();

$process->ensure_commandline( $argv??NULL );

$process->set_verbose(true);

$debug = false;

if( isset( $argv[1] ) ){
    array_shift( $argv ); // get rid of 0
    $cbids = $argv ;
    
    foreach( $cbids as $arg_file_id ){
        $file_id = $process->validate_input_id($arg_file_id);
        //only extract if within 24h
        if( $process->should_fit_extract( $file_id ) ){
            $paging = new Paging( [ 'file_id' => $file_id ], Paging::SYSTEM_TOKEN, $process->sql );
            $data = $process->query_file( $paging );

            if( strlen( $data ) ){
                try {
                    $fit = new adriangibbons\phpFITFileAnalysis( $data, array( 'input_is_data' => true ) );
                    $process->fit_extract( $file_id, $fit->data_mesgs );
                }catch(Exception $e ){
                    $process->log( "ERROR", "can't process fit file for id %d. Error: %s", $file_id, $e->getMessage() );
                }
            }
        }else{
            $process->log( 'INFO', 'skipping extract for %d as appears too old or inactive', $file_id );
        }
    }
}
exit();
?>
