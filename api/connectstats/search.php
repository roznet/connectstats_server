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
 * This entry point should be called to get access to a list of activities for a given user
 *
 * GET Parameters:
 * REQUIRED:
 *   token_id: the token_id returned after the user_register call. the call will have to be authenticated
 *             with the paremeters used to register the token_id
 * OPTIONAL paging parameters:
 *   start: the offset from the latest activities to start the list from.
 *   limit: the maximum number of activities to return
 *   activity_id: the first activity_id to base the start of the list
 * OPTIONAL format parameters:
 *   backfillformat: if set to 1, the output format will match the one of a backfill call from
 *                   the garmin health api. This can be useful for testing.
 *   
 */

include_once('../shared.php' );

$process = new GarminProcess();

if( isset( $_GET['token_id'] ) ){
    $token_id = intval($_GET['token_id']);

    $process->authenticate_header($token_id);

    $from_activity_id = 0;
    $start = 0;
    
    if( isset( $_GET['start'] ) ){
        $start = intval( $_GET['start']);
    }else{
        if( isset( $_GET['from_activity_id' ] ) ){
            $from_activity_id = intval( $_GET['from_activity_id'] );
        }else{
            $start = 0;
        }
    }
    if( isset( $_GET['limit'] ) ){
        $limit = intval($_GET['limit']);
    }else{
        $limit = 5;
    }
    $token = $process->sql->query_first_row( "SELECT cs_user_id FROM tokens WHERE token_id = $token_id" );

    if( isset( $_GET['backfillformat'] ) && $_GET['backfillformat'] == 1 ){
        $process->query_backfill( $token['cs_user_id'], $from_activity_id, $start,  $limit );
    }else{
        if( $from_activity_id == 0 ){
            $process->query_activities( $token['cs_user_id'], $start, $limit );
        }else{
            $process->query_activities_from_id( $token['cs_user_id'], $from_activity_id, $limit );
        }
    }
}
?>
