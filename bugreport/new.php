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
 */


include_once( dirname( __DIR__, 1 ) ) . '/api/sql_helper.php';
include( 'config_bugreport.php' );
include( 'bugreport.php' );

?>
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
		<head>
				<meta http-equiv="Content-Type" content="text/html; charset=utf-8" />
				<meta name="viewport" content="width=320; initial-scale=1.0; maximum-scale=1.0; user-scalable=0;"/>
				<title>ConnectStats Bug Report</title>
				<style type="text/css">
				 .textbox {
						 width: 300px;
				 }
				 .buttonbox {
						 margin-left: 60%;
				 }
				 .notes p {
						 font-family: Verdana, Arial, Helvetica, sans-serif;
						 font-size: small;
						 padding-left: 0.5em;
						 padding-top: 0;
						 padding-bottom: 0;
						 margin-top: 0;
						 margin-bottom: 0;
				 }
				 .notes ul {
						 font-family: Verdana, Arial, Helvetica, sans-serif;
						 font-size: small;
				 }
				 .remark {
						 font-family: Verdana, Arial, Helvetica, sans-serif;
						 font-size: small;
				 }
				</style>
		</head>
		<body>
				<h2>Bug Report</h2>

				<?php 
				//----------OUTPUT Bug description text---------------------------------------//
				function output_bug_description($bugreport){
				?>
						<h4>Your bug description</h4>
						<p><?php print htmlspecialchars( stripcslashes($bugreport->row['description']) )?></p>
				<?php
				}

				//----------OUTPUT Main Message ConnectStats---------------------------------------//
				function output_connectstats_main(){
				?>
						<p class="remark">Please fill in the form below to report your issue.</p>
                    <p class="remark"> You can follow on the <a href="https://ro-z.net/blog">blog</a>, on twitter <a href="https://twitter.com/connectstats">@connectstats</a> or on <a href="https://www.facebook.com/Connectstats/">facebook</a> for updates on new issues.</p>
						<p class="remark">Please also check the <a href="https://ro-z.net/blog/connectstats/troubleshoot/">troubleshooting section</a> of the help.</p>

				<?php   
				}

				//----------OUTPUT Disabled Message ConnectStats---------------------------------------//
				function output_connectstats_disabled($bugreport){
				?>
                    <h3>Attention</h3>
                    <p class="remark"><?php print( $bugreport->message ) ?></p>
                    <p class="remark">You can get updates on the <a href="https://ro-z.net/blog">blog</a>, follow on twitter <a href="https://twitter.com/ConnectStats">@connectstats</a> or on <a href="https://www.facebook.com/Connectstats/">facebook</a>  for updates.</p>
                    <p class="remark">Bug reporting was disabled on <?php print( $bugreport->message_date ) ?> and will be re-enabled when the situation is resolved</p>
				<?php
				}

				//----------OUTPUT Outdated Version ---------------------------------------//
				function output_outdated_version($bugreport){
                    ?>
                    
                    <p class="remark"><b>Attention</b> You are using an outdated version of connectstats <?php print( $bugreport->row['version'] )?></p>
                    <p class="remark">You need to update to a version more recent than <?php print( $bugreport->minimum_app_version ) ?></p>
                    <?php
                    if(  $bugreport->minimum_version_message ){
                        printf( '<p>%s</p>', $bugreport->minimum_version_message );
                    }
						if( isset( $bugreport->row['systemVersion'] ) && version_compare( $bugreport->row['systemVersion'], $bugreport->minimum_system_version ) == -1 ){
                            ?>
                            <p class="remark">Your iOS version <?php print( $bugreport->row['systemVersion'] )?> is also too old for the latest version of ConnectStats.
                            It requires at the minimum version iOS <?php print( $bugreport->minimum_system_version )?> </p>
<?php
						}
						printf( '<p class="remark">You can find more information on the <a href="https://ro-z.net/blog">blog</a></p>' );
				}

				//----------OUTPUT Form---------------------------------------//
				function output_form($bugreport){
				?>
						<form id="form1" name="form1" method="post" action="?id=<?php print $bugreport->new_id?>" onsubmit="document.getElementById('submit_button').disabled = 1;">
								<p>
										<label>Description of the bug</label>
										<textarea class="textbox" name="description" id="textarea" cols="45" rows="5"></textarea>
								</p>
								<p>
										<label>You can provide an optional email if you want some feedback on the bug</label>
										<input name="email" class="textbox" type="email" />
								</p>
								<p>
										<input type="hidden" id="commonid" value="<?php print( $bugreport->common_id )?>" />
										<input type="hidden" name="applicationName" id="applicationName" value="<?php print( $bugreport->application )?>" />
										<input type="submit" class="buttonbox" value="submit" id="submit_button"/>
								</p>
						</form>
<?php
}
//---------- START OF HTML ---------------------------------------//

$bugreport = new BugReport();

$bugreport->process();

if( $bugreport->updated ){
    printf( "<h3>Thank you!</h3>");
    if( !$bugreport->has_valid_email() ){
        print "<p>You don't appear to have included an email so I won't be able to get back to you, even if I am able to to help you.</p>";
    };
    output_bug_description($bugreport);
		$bugreport->send_email_if_necesssary();
}else{
    if( $bugreport->is_outdated_version() ){
        output_outdated_version($bugreport);
    }elseif($bugreport->disabled){
        output_connectstats_disabled($bugreport);
    }else{
        output_connectstats_main();
        output_form($bugreport);
    }
}

?>
						<pre>
						</pre>

		</body>
