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

class BugReport
{

    function __construct()
    {
        include('config_bugreport.php');

        $this->sql = new sql_helper($bug_config);
        $this->bug_data_directory = $bug_config['bug_data_directory'];
        if (isset($bug_config['email_bug_to'])) {
            $this->email_bug_to = $bug_config['email_bug_to'];
        } else {
            $this->email_bug_to = NULL;
        }

        $this->debug = isset($_GET['debug']);
        if ($this->debug) {
            $this->sql->verbose = true;
            print('<pre>DEBUG START' . PHP_EOL);
        }

        if (!$this->sql->table_exists('gc_app_status')) {
            $this->sql->create_or_alter('gc_app_status', array(
                'status_id' => 'BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY',
                'ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
                'state' => "ENUM( 'ok', 'disabled', 'message' )",
                'message' => 'TEXT',
            ));
            $this->sql->create_or_alter('gc_minimum_version', array(
                'version_id' => 'BIGINT(20) UNSIGNED AUTO_INCREMENT PRIMARY KEY',
                'ts' => 'TIMESTAMP DEFAULT CURRENT_TIMESTAMP',
                'app_minimum_version' => 'VARCHAR(32)',
                'system_minimum_version' => 'VARCHAR(32)',
                'message' => 'TEXT',
            ));
        }
        $row = $this->sql->query_first_row('SELECT * FROM gc_minimum_version ORDER BY version_id DESC LIMIT 1');

        if( isset( $row['app_minimum_version'] ) ){
            $this->minimum_app_version = $row['app_minimum_version'];
            $this->minimum_system_version = $row['system_minimum_version'];
            $this->minimum_version_message = $row['message'];
        }else{
            $this->minimum_app_version = '1.0';
            $this->minimum_system_version = '1.0';
            $this->minimum_version_message = NULL;
        }
        $row = $this->sql->query_first_row('SELECT * FROM gc_app_status ORDER BY status_id DESC LIMIT 1');

        if( isset( $row['state'] ) ){
            $this->disabled = ($row['state'] == 'disabled');
            $this->message  = $row['message'];
            $this->state    = $row['state'];
            $this->message_date = $row['ts'];
        }else{
            $this->disabled = false;
            $this->message = NULL;
            $this->state = 'ok';
        }
        $this->new_id = NULL;
        $this->common_id = -1;
        $this->application = $this->get_or_post('applicationName', 'ConnectStats');

        $this->error = NULL;

        $this->fields = array(
            'id' => 'INT',
            'filename' => 'VARCHAR(256)',
            'platformString' => 'VARCHAR(256)',
            'applicationName' => 'VARCHAR(256)',
            'systemName' => 'VARCHAR(256)',
            'systemVersion' => 'VARCHAR(256)',
            'description' => 'TEXT',
            'version' => 'VARCHAR(256)',
            'email' => 'VARCHAR(256)',
            'commonid' => 'VARCHAR(256)',
            'filesize' => 'INT',
            'updatetime' => 'DATETIME'
        );

        if (!$this->sql->table_exists('gc_bugreports')) {
            $this->sql->create_or_alter('gc_bugreports', $this->fields, true);
            $this->sql->ensure_field('gc_bugreports', 'replied', 'DATETIME');
        }
        $this->list_url = sprintf('https://%s/%s', $_SERVER['HTTP_HOST'], str_replace('new.php', 'list.php', $_SERVER['REQUEST_URI']));
        $this->updated = false;

        if ($this->debug) {
            $this->sql->verbose = true;
            print('DEBUG END</pre>' . PHP_EOL);
        }
    }

    function process()
    {
        if ($this->debug) {
            $this->build_debug_row();
        }

        if (isset($_FILES['file'])) {
            // this is the first stage when bug report is send with the bug report files
            $this->save_bugreport();
        } else if (isset($_GET['id'])) {
            // This is the second stage, when the form is submitted with the existing id and text information
            $this->update_bugreport();
        }
    }

    function get_or_post($key, $default = NULL)
    {
        if (isset($_POST[$key])) {
            return ($_POST[$key]);
        } else if (isset($_GET[$key])) {
            return ($_GET[$key]);
        }
        return $default;
    }


    function update_bugreport()
    {
        // Display control
        if (isset($_GET['id'])) {
            $this->new_id = $_GET['id'];
            if (isset($_POST['description']) && $_POST['description']) {

                $this->row = array('description' =>  $this->sql->connection->real_escape_string($_POST['description']), 'id' => $this->new_id);
                if (isset($_POST['email']) && $_POST['email']) {
                    $this->row['email'] = $_POST['email'];
                }
                $this->sql->insert_or_update('gc_bugreports', $this->row, array('id'));
                $this->updated = true;
            }
        }
    }


    function is_outdated_version()
    {
        return (isset($this->row['version']) && version_compare($this->row['version'], $this->minimum_app_version) == -1);
    }

    function save_bugreport()
    {
        if (is_dir($this->bug_data_directory) && isset($_FILES['file'])) {
            $this->sql->ensure_field('gc_bugreports', 'version', 'VARCHAR(256)');
            $this->sql->ensure_field('gc_bugreports', 'commonid', 'VARCHAR(256)');
            $this->sql->ensure_field('gc_bugreports', 'applicationName', 'VARCHAR(256)');
            $this->sql->ensure_field('gc_bugreports', 'filesize', 'INT');

            $file_dir = strftime("%Y/%m", time());
            $uploads_dir = sprintf('%s/%s', $this->bug_data_directory, $file_dir);
            if (!is_dir($uploads_dir)) {
                if (!mkdir($uploads_dir, 0777, true)) {
                    $upload_dir = $this->bug_data_directory;
                }
            }
            $file = $_FILES['file'];
            $error = $file['error'];
            $this->new_id = $this->sql->max_value('gc_bugreports', 'id') + 1;

            $saved_file_name = NULL;
            if ($error == UPLOAD_ERR_OK) {
                $tmp_name = $file["tmp_name"];
                $name = $file["name"];
                $name = sprintf("bugreport_%s_%d.zip", strftime("%Y%m%d", time()), $this->new_id);
                $v = move_uploaded_file($tmp_name, "$uploads_dir/$name");
                if ($v) {
                    $saved_file_name = "$file_dir/$name";
                } else {
                    $saved_file_name = sprintf('ERROR: Failed to save %s', $tmp_name);
                }
            } else {
                $saved_file_name = sprintf('ERROR: Failed to upload (error=%s)', $error);
            }

            $row = array('id' => $this->new_id);
            foreach ($this->fields as $field => $type) {
                if (isset($_POST[$field])) {
                    $row[$field] = $_POST[$field];
                } else {
                    if ($field == 'filename') {
                        if ($saved_file_name) {
                            $row[$field] = $saved_file_name;
                        }
                    } elseif ($field == 'applicationName') {
                        $row[$field] = 'ConnectStats';
                    }
                }
            }
            $row['updatetime'] = $this->sql->value_to_sqldatetime(time());
            if (!isset($row['commonid']) || $row['commonid'] == -1) {
                $this->common_id = $this->new_id;
                $row['commonid'] = $this->common_id;
            } else {
                $this->common_id = $row['commonid'];
            }
            $this->sql->insert_or_update('gc_bugreports', $row);
            $this->row = $row;
        }
    }

    function has_valid_email()
    {
        return (isset($this->row['email']) && strpos($this->row['email'], '@') !== false);
    }

    function send_email_if_necesssary()
    {
        // Report/email control
        if ($this->updated) {
            try {
                $row = $this->sql->query_first_row(sprintf("SELECT * FROM gc_bugreports WHERE id = %d", intval($this->new_id)));

                $msg = sprintf(
                    "Description: %s\nEmail: %s\nVersion: %s\nPlatform: %s\n",
                    $row['description'],
                    $row['email'],
                    $row['version'],
                    implode(' ', array($row['systemName'], $row['systemVersion'], $row['platformString']))
                );

                if (isset($row['filename']) && file_exists($row['filename'])) {
                    $z = new ZipArchive();

                    if ($z->open($row['filename'])) {
                        if ($z->numFiles > 1) {
                            for ($i = 0; $i < $z->numFiles; $i++) {
                                $info = $z->statIndex($i);
                                $fn = $info['name'];
                                $msg .= sprintf("File: %s" . PHP_EOL, $fn);
                            }
                        }
                    }
                } else {
                    $msg .= sprintf("File Failed to save %s" . PHP_EOL, $row['filename']);
                }
                $subject = $this->application . " BugReport";
                $headers = 'From: ConnectStats <connectstats@ro-z.net>' . "\r\n";
                if (strpos($row['email'], '@') === false) {
                    $subject = "$this->application Anonymous BugReport";
                } else {
                    $headers .= 'Reply-To: ' . $row['email'] . "\r\n";
                }
                $listurl = sprintf('https://%s/%s', $_SERVER['HTTP_HOST'], str_replace('bugreport/new', 'bugreport/list', $_SERVER['REQUEST_URI']));
                $msg .= sprintf('Bug report: %s', $listurl, PHP_EOL);
                if ($this->email_bug_to) {
                    if (!mail($this->email_bug_to, $subject, $msg, $headers)) {
                        print('<p>Failed to email!, please go to the <a href="https://ro-z.net">web site</a> or twitter <a href="https://twitter.com/connectstats">@connectstats</a> to report</p>' . PHP_EOL);
                    } else {
                        print('<h3>Email sent!</h3>');
                    }
                }
            } catch (Exception $e) {
                print ' ';
            }
        }
    }

    function build_debug_row()
    {
        if ($this->debug) {
            $this->row = array();
            foreach ($this->fields as $field => $type) {
                if (isset($_GET[$field])) {
                    printf( 'DEBUG extracting $row[%s] = %s'.PHP_EOL, $field, $_GET[$field] );
                    $this->row[$field] = $_GET[$field];
                }
            }
        }
    }
}
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
