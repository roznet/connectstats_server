<?php
/**
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
 * This entry point will trigger if necessary a backfill from the Garmin Health API
 *
 * It will respect the throttling constraint from the API
 */
include_once('../shared.php');

$process = new GarminProcess();

if( isset( $_GET['token_id'] ) && isset( $_GET['start_year'] ) ){
    $token_id = intval($_GET['token_id']);

    $process->authenticate_header($token_id);
    
    $force = false;
    if( isset( $_GET['force'] ) && $_GET['force'] == 1 ){
        $force = true;
    }

    $rv = $process->backfill_api_entry( $token_id, intval($_GET['start_year']), $force );
    print( json_encode( $rv ) );
}
?>



