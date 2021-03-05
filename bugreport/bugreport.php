<?php

include_once( dirname( __DIR__, 1 ) ) . '/api/sql_helper.php';

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

    function status(){
        $this->row = array();
        foreach ($this->fields as $field => $type) {
            if (isset($_GET[$field])) {
                if( $this->debug ){
                    printf('DEBUG extracting $row[%s] = %s' . PHP_EOL, $field, $_GET[$field]);
                }
                $this->row[$field] = $_GET[$field];
            }
        }

        if( $this->is_outdated_version() ){
            return [ 'status' => 0, 'message' => $this->minimum_version_message, 'app_minimum_version' => $this->minimum_app_version, 'system_minimum_version' => $this->minimum_system_version ];
        }elseif( $this->disabled ){
            return [ 'status' => 0, 'message' => $this->message, 'date' => $this->message_date ];
        }else{
            return [ 'status' => 1 ];
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


    function saved_file_name( $name ){
       $file_dir = strftime("%Y/%m", $this->update_time);
       return sprintf( '%s/%s', $file_dir, $name );
    }

    function saved_file_full_path( $name ){
        $full_file_path = sprintf('%s/%s', $this->bug_data_directory, $name);
        $upload_dir = dirname( $full_file_path );
        if (!is_dir($upload_dir)) {
            if (!mkdir($upload_dir, 0777, true)) {
                die( 'Internal misconfiguration, cannot save file' );
            }
        }
        return $full_file_path;
    }
    
    function save_bugreport()
    {
        if (is_dir($this->bug_data_directory) && isset($_FILES['file'])) {
            
            $this->sql->ensure_field('gc_bugreports', 'version', 'VARCHAR(256)');
            $this->sql->ensure_field('gc_bugreports', 'commonid', 'VARCHAR(256)');
            $this->sql->ensure_field('gc_bugreports', 'applicationName', 'VARCHAR(256)');
            $this->sql->ensure_field('gc_bugreports', 'filesize', 'INT');

            $this->update_time = time();
            
            $file = $_FILES['file'];
            $error = $file['error'];
            $this->new_id = $this->sql->max_value('gc_bugreports', 'id') + 1;

            if ($error == UPLOAD_ERR_OK) {
                $tmp_name = $file["tmp_name"];
                $saved_file_name = $this->saved_file_name( sprintf("bugreport_%s_%d.zip", strftime("%Y%m%d", $this->update_time), $this->new_id) );
                $v = move_uploaded_file($tmp_name, $this->saved_file_full_path( $saved_file_name ));
                if (!$v) {
                    $saved_file_name = sprintf('ERROR: Failed to save %s to %s', $tmp_name, $saved_file_name);
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
            $row['updatetime'] = $this->sql->value_to_sqldatetime($this->update_time);
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
                $this->update_time =  strtotime( $row['updatetime'] );
                $msg = sprintf(
                    "Description: %s\nEmail: %s\nVersion: %s\nPlatform: %s\n",
                    $row['description'],
                    $row['email'],
                    $row['version'],
                    implode(' ', array($row['systemName'], $row['systemVersion'], $row['platformString']))
                );

                if (isset($row['filename'])) {
                    $saved_file_path = $this->saved_file_full_path( $row['filename'] );
                    if( is_readable( $saved_file_path ) ){
                        $z = new ZipArchive();

                        if ($z->open($saved_file_path)) {
                            if ($z->numFiles > 1) {
                                for ($i = 0; $i < $z->numFiles; $i++) {
                                    $info = $z->statIndex($i);
                                    $fn = $info['name'];
                                    $sz = $info['size'];
                                    if( $sz > 1048576 ){
                                        $sz = number_format( $sz/1048576,2). ' MB';
                                    }else{
                                        $sz = number_format( $sz/1024, 2 ). ' KB';
                                    }
                                    $msg .= sprintf("File: %s ($sz)" . PHP_EOL, $fn, $sz);
                                }
                            }
                        }
                    }
                } else {
                    $msg .= sprintf("File Failed to save %s" . PHP_EOL, $row['filename']);
                }
                $subject = $this->application . " BugReport";
                $headers = 'From: ConnectStats <bugreport@connectstats.app>' . "\r\n";
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
