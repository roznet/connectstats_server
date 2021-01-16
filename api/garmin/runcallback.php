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
 *   usage: runcallback.php file_id
 *       Arg: file_id should be a file_id from the table fitfiles containing a callbackURL
 *
 *       Description: if necessary and callbackURL present for a file, go and fetch it
 *       if fit file downloaded will add to the queue to execute runfitextract file_id
 */
include_once('../shared.php');

$process = new GarminProcess();
$process->set_verbose(true);

$process->ensure_commandline($argv??NULL);

if( isset( $argv[1] ) ){
    array_shift( $argv ); // get rid of 0
    $table = array_shift( $argv );
    
    $cbids = $argv;
    $process->run_file_callback( $table, $cbids );
}
$process->log( 'EXIT', 'Success' );
exit();
?>
