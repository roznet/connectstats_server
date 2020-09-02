<?php

include_once( dirname( __DIR__, 1 ) ) . '/api/sql_helper.php';

class BugReport {

    function __construct(){
				include( 'config_bugreport.php' );

				$this->sql = new sql_helper( $bug_config );
				$this->bug_data_directory = $bug_config['bug_data_directory'];
    }
    
    function links(){
        print '<table class="linktable"><tr><td><a href="list">nonblank</a></td><td><a href="list?needreply=1">need reply</a></td><td><a href="list?all=1">all</a></td><td><a href="list?errors=1">errors</a></td></tr></table>';
    }


    function email_url( $row ){
        $sub = sprintf( "Re: %s BugReport [%d]", $row['applicationName'], $row['id'] );
        $body = sprintf( "\nDescription: %s\nEmail: %s\nVersion: %s\nPlatform: %s\nDate: %s\nId: %d\n", $row['description'], $row['email'], $row['version'], implode( ' ', array( $row['systemName'], $row['systemVersion'], $row['platformString'] ) ), $row['updatetime'], $row['id'] );
        return sprintf( 'mailto:%s?subject=%s&body=%s', $row['email'],rawurlencode($sub),rawurlencode($body) );
    }
    function reply_link($row){
        $url = $this->email_url( $row );
        return sprintf( '<a href="?reply=%d">Reply</a>', $row['id'] );
    }

    function email_link($row){
        $url = $this->email_url( $row );
        return sprintf( '<a href="?reply=%d">%s</a>', $row['id'], $row['email'] );
    }

    function file_path_for_row( $row ){
        $fn = sprintf( '%s/%s', $this->bug_data_directory, $row['filename']);
        return $fn;
    }

    function pretty_file_size( $size ){
        if ($size >= 1073741824)
        {
            $rv = number_format($size / 1073741824, 2) . ' GB';
        }
        elseif ($size >= 1048576)
        {
            $rv = number_format($size / 1048576, 2) . ' MB';
        }
        elseif ($size >= 1024)
        {
            $rv = number_format($size / 1024, 2) . ' KB';
        }
        else
        {
            $rv = $size . ' b';
        }

        return $rv;
    }

    function class_bugreport( $txt ){
        $rv = $txt;

        $line_class = array(
            '[GCAppDelegate application:didFinishLaunchingWithOptions:] ===========' => 'bugreport_start',
            '[GCWebConnect log:stage:]' => 'bugreport_web',
            'W WARN:' => 'bugreport_warn',
            'E ERR :' => 'bugreport_err'
        );

        foreach( $line_class as $def => $class ){
            if( strpos( $txt, $def ) !== false ){
                return $class;
            }
        }
        return 'bugreport_info';
    }

    function bugreport_line_parse( $txt, $is_compact ){
        if( preg_match( '/([0-9]+-[0-9]+-[0-9]+ [:.0-9]+) ([:0-9a-f]+) [-EW] (INFO|ERR |WARN):([A-Za-z0-9.+]+):([0-9]+):([-+]\[[^\]]+\])(.*)/', $txt, $matches ) ){
            $line = array(
                'time' => $matches[1],
                'pid' => $matches[2],
                'level' => $matches[3],
                'filename' => $matches[4],
                'line' => $matches[5],
                'method' => $matches[6],
                'message' => $matches[7]
            );
            if( $is_compact ){
                $prefix = sprintf( '%s', $line['time'] );
                $type   = $line['level'];
                $url = sprintf( 'https://github.com/roznet/connectstats/blob/master/ConnectStats/src/%s#L%s', $line['filename'], $line['line'] );
                $source = sprintf( '<a href="%s" class="method">%s</a>', $url, $line['method'] );
                $class = $this->class_bugreport( $txt );
                return sprintf( '<td class="line_prefix">%s</td><td class="%s">%s</td><td class="line_source">%s</td><td class="%s">%s</td>', $prefix, $class, $type, $source, $class, $line['message'] );
            }else{
                $prefix = sprintf( '%s %s', $line['time'], $line['pid'] );
                $type   = $line['level'];
                $url = sprintf( 'https://github.com/roznet/connectstats/blob/master/ConnectStats/src/%s#L%s', $line['filename'], $line['line'] );
                $source = sprintf( '<a href="%s" class="method">%s:%s</a>', $url, $line['filename'], $line['line'] );
                $class = $this->class_bugreport( $txt );
                return sprintf( '<td class="line_prefix">%s</td> <td class="%s">%s</td> <td class="line_source">%s</td><td class="%s"><div class="method">%s</div> %s</td>', $prefix, $class, $type, $source, $class, $line['method'], $line['message'] );
            }
        };
        return $txt;
    }
    function url_github_code( $txt ){
        if( preg_match( '/(- INFO|E ERR |W WARN):([A-Za-z0-9.+]+):([0-9]+):/', $txt, $matches ) ){
            $filename = $matches[2];
            $number = $matches[3];
						
            $url = sprintf( 'https://github.com/roznet/connectstats/blob/master/ConnectStats/src/%s#L%s', $filename, $number );
            #$url = sprintf( "javascript:github('%s',%s')", $filename, $number );
            $txt = str_replace( sprintf( ':%s:%s:', $filename, $number ), sprintf( ':<a href="%s">%s:%s</a>:', $url, $filename, $number ), $txt );
        }
        return $txt;
    }
		
    function show_one_for_id($id){
        $row = $this->sql->query_first_row( "SELECT * FROM gc_bugreports WHERE id = '$id'" );
        $other = $this->sql->query_first_row( sprintf( "SELECT COUNT(*) as n FROM gc_bugreports WHERE commonid=%d", $row['commonid'] ));
        print '<table>';
        print '<tr><td><h4>Application</h4></td><td>';
        print htmlspecialchars($row['applicationName']);
        print '</td></tr><tr><td><h4>Description</h4></td><td>';
        print htmlspecialchars($row['description']);
        print '</td></tr><tr><td><h4>Email</h4></td><td>';
        print $this->email_link($row);
        if( isset( $row['replied'] ) ){
            printf( ' replied %s', $row['replied'] );
        }
        $fn = $this->file_path_for_row( $row );
				print( $fn );
        print '</td></tr><tr><td><h4>System</h4></td><td>';
        print implode( ' ', array( $row['systemName'], $row['systemVersion'], $row['platformString'] ) );
        print '</td></tr><tr><td><h4>linked errors</h4></td><td>';
        printf ('<a href="list?commonid=%d">%d reports</a>', $row['commonid'], $other['n'] );
        printf( '</td></tr><tr><td><h4>File</h4></td><td><a href="%s">%s</a>%s',$fn,$fn, PHP_EOL);
        print '</td></tr></table>';
        $content = '';
        if( file_exists( $fn ) ){
            $z = new ZipArchive($fn);
            if ($z->open($fn)) {
                if($z->numFiles > 1 ){
                    printf( '<table class="list_files">'.PHP_EOL );
                    for($i=0;$i<$z->numFiles;$i++){
                        $info=$z->statIndex($i); 
                        $fn = $info['name'];
                        $size = $info['size'];
												
                        printf('<tr><td><a href="?id=%d&file=%s">%s</a></td><td>[%s]</td></tr>'.PHP_EOL,$id,$fn,$fn,$this->pretty_file_size($size));
                    }
                    printf( '</table>'.PHP_EOL );
                }

								
                $file = 'bugreport.log';
                if(isset($_GET['file'])){
                    $file = $_GET['file'];
                }
								
                $ext = pathinfo($file, PATHINFO_EXTENSION);
                $is_db = ($ext == 'db' );
                $is_bugreport = ($file == 'bugreport.log');
                $is_raw = isset( $_GET['raw'] );
                $is_compact = !isset( $_GET['full'] );
                if( $is_bugreport ){
                    printf( '<table class="linktable">'.PHP_EOL.'<tr><td><h3>%s</h3></td>'.PHP_EOL, $file );
                    if( $is_raw ){
                        printf( '<td><a href="?id=%d&file=%s">color</a></td>',$id, $file );
                    }else{
                        printf( '<td><a href="?id=%d&file=%s&raw=1">raw</a></td>',$id, $file );
                        if( $is_compact ){
                            printf( '<td><a href="?id=%d&file=%s&full=1">full</a></td>',$id, $file );
                        }else{
                            printf( '<td><a href="?id=%d&file=%s">compact</a></td>',$id, $file );
                        }
                    }
                    printf( '</tr></table>'.PHP_EOL);
                }else{
                    printf( '<h3>%s</h3>'.PHP_EOL,$file);
                }
								
                if($is_db){
                    $outname = sprintf( '%s/sqlite/%d_%s', $this->bug_data_directory, $id, $file );
                    if( ! file_exists( $outname ) || isset($_GET['force_extract'])){
                        $fo = fopen( $outname, 'w');
                        $fp = $z->getStream($file);

                        if(!$fp) exit("failed\n");
                        while (!feof($fp)) {
                            fwrite( $fo, fread($fp, 2) );
                        }
												
                        fclose($fp);
                    }
                }else{
                    $fp = $z->getStream($file);

                    if(!$fp) exit("failed\n");
                    while (!feof($fp)) {
                        $txt = fread($fp, 2);
                        $content .= $txt;
                    }
										
                    fclose($fp);

                }
                if($is_db){
                    $extract_url = sprintf( '?id=%d&file=%s&force_extract=1', $id, $file );
                    print( '<pre>' );
										$backurl =  $_SERVER['REQUEST_URI'];
										$phpliteurl = sprintf( '../%s/sqlite/%d_%s', $this->bug_data_directory, $id, $file, $file );
                    printf( '<a href="phpliteadmin/?switchdb=%s&backurl=%s">Open %s</a> [<a href="%s">re-extract</a> %s]'.PHP_EOL, urlencode($phpliteurl),urlencode( $backurl ), $file, $extract_url, $outname );
                    print( '</pre>' );
                }else if( $is_bugreport ){
                    $lines = explode( "\n", $content );
                    if( $is_raw ){
                        print( '<ul class="bugreport_line">'.PHP_EOL );
                    }else{
                        print( '<table class="bugreport_line">'.PHP_EOL );
                    }
                    foreach( $lines as $line ){
                        $raw = htmlspecialchars($line);
                        if( $is_raw ){
                            printf( '<li>%s</li>'. PHP_EOL,  $raw );
                        }else{
                            $raw =  $this->bugreport_line_parse( $raw, $is_compact );
                            printf( '<tr class="bugreport_line">%s</tr>'. PHP_EOL,  $raw );
                        }
                    }
                }else{
                    print( '<pre>' );
                    print( htmlspecialchars($content) );
                    print( '</pre>' );
                }
            }else{
                printf("Failed to open report file %s",$row['filename']);
            }
        }else{
            printf("Missing report file %s",$row['filename']);
        }
    }

    function summary_with_errors(){
        $query = sprintf( "SELECT * FROM gc_bugreports WHERE description != '' OR email != '' ORDER BY updatetime DESC LIMIT %d", $this->limit );
        $all = $this->sql->query_as_array($query);
        $i=0;
				
        foreach( $all as $one){
            $row = $one;
            $content = '';
            $errors = '';
            $fn = $this->file_path_for_row($row);
            if( file_exists( $fn ) ){
                $z = new ZipArchive();
                if ($z->open($fn)) {
                    $file = 'bugreport.log';
                    if(isset($getargs['file'])){
                        $file = $getargs['file'];
                    }
										
                    $fp = $z->getStream($file);
                    if(!$fp) exit("failed\n");
										
                    while (!feof($fp)) {
                        $content .= fread($fp, 2);
                    }
										
                    fclose($fp);
                    $lines = explode( "\n", $content);
                    foreach( $lines as $line ){
                        if( strpos( $line, 'X EXCP') !==FALSE || strpos( $line, 'E ERR')!==FALSE){
                            $errors .= $line.PHP_EOL;
                        }
                    }
                }
            }
            if($errors){
                $class="";
                if($i %2==0){
                    $class="class=odd";
                }
                $i++;
                printf( '<table class=sqltable width="90%%"><tr %s><td width="3%%">', $class);
                printf( '<a href="list?id=%d">%d</a>', $one['id'], $one['id']);
                print '</td><td width="20%">';
                print implode( ' ', array( $row['systemName'], $row['systemVersion'], $row['platformString'], $row['version'] ) );
                print '</td><td width="20%">';
                $fn = $this->file_path_for_row($row);
                printf( '<a href="%s">%s</a>%s', $fn,$fn, PHP_EOL);
                print '</td><td width="20%">';
                print htmlspecialchars($row['email']);
                print '</td><td>';
                print htmlspecialchars($row['description']);
                print '</td></tr></table>'.PHP_EOL;
                print '<pre>'.$errors.'</pre>';
            }
        }

    }

    function summary_table(){
        if( isset( $this->args['commonid'] ) ){
            $query = sprintf('SELECT * FROM gc_bugreports WHERE commonid=%d ORDER BY updatetime DESC', $this->args['commonid'] );
        }else if(isset($this->args['all'])){
            $query = sprintf( 'SELECT * FROM gc_bugreports ORDER BY updatetime DESC LIMIT %d', $this->limit );;
        }else if(isset($this->args['needreply'])){
            $query = sprintf( "SELECT * FROM gc_bugreports WHERE description != '' AND email != '' AND ISNULL(replied) ORDER BY updatetime DESC LIMIT %d", $this->limit );
        }else{
            $query = sprintf( "SELECT * FROM gc_bugreports WHERE description != '' OR email != '' ORDER BY updatetime DESC LIMIT %d", $this->limit );
        }
        $order = array( 'id', 'commonid', 'updatetime', 'replied', 'version', 'email', 'description', 'systemVersion', 'platformString' );
        $email_fn = function($row){
            return $this->email_link( $row );
        };
        print $this->sql->query_as_html_table( $query, array( 'email'=>$email_fn, 'id'=>'list?id=%d', 'commonid'=>'list?commonid=%d' ), $order );
    }

    function redirect_reply(int $id){
        if( $id > 0 ){
            $row = $this->sql->query_first_row( "SELECT * FROM gc_bugreports WHERE id = $id" );
            if( $row ){

                $link = $this->email_url($row);
                if( $link ){
                    $this->sql->execute_query( sprintf( 'UPDATE gc_bugreports SET replied = NOW() WHERE id = %d', $id ) );
                }
                header( sprintf( 'location: %s', $link ) );
                die;
            }
        }
    }
		
    function run( $getargs ){
        $this->args = $getargs;
				
        $this->links();

        if( isset( $getargs['limit'] ) ){
            $this->limit = intval( $getargs['limit'] );
        }else{
            $this->limit = 64;
        }
				
        if(isset($getargs['id'])){
            $id = $getargs['id'];
            $this->show_one_for_id($id);
        }else if(isset($getargs['errors'])){
            $this->summary_with_errors();
        }else{
            $this->summary_table();
        }
    }
}

$bugreport = new BugReport();

if( isset( $_GET['reply'] ) ){
    $bugreport->redirect_reply(intval($_GET['reply']));
}
?>

<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml">
		<head>
				<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
				<title>Bug Reports</title>
				<style>
				 body {
						 font-family: Verdana, Arial, Helvetica, sans-serif;
						 font-size: small;
				 }
				 h4 {
						 margin-top:0;margin-bottom:0
				 }
				 p {
						 margin-left:20px;
				 }
				 table.bugreport_line  { list-style-type:none; padding: 0; font-family: monospace; overflow-y: hidden; white-space: nowrap;  }
				 ul.bugreport_line { list-style-type:none; padding: 0; font-family: monospace; overflow-y: hidden; white-space: nowrap;  }
				 td.bugreport_info { color: black; font-family: monospace; }
				 td.bugreport_warn { color: green;  font-family: monospace; }
				 td.bugreport_start { color: black; font-family: monospace; font-weight: bold; }
				 td.bugreport_err  { color: red;  font-family: monospace; font-weight: bold; }
				 td.bugreport_web  { color: blue;  font-family: monospace; }
				 td.line_prefix { color: darkgray;  font-family: monospace; }
				 td.line_source { color: darkgray;  font-family: monospace; }
				 td.list_files { font-family: monospace; }
				 .method { display: inline; color: slategray; }
				 .method  a:any-link { color: slategray; }
				 .linktable a:any-link { color: blue; }
				 /****************************************************************
					*	sql tables
					****************************************************************/
				 table.sqltable th, table.sqltable td, table.sqltable{
						 border-collapse: collapse;
						 border-color:gray;
						 border-style:solid;
						 border-width: 1px 1px 1px 1px;
						 padding-bottom:1px;
						 font-size:x-small;
				 }
				 table.sqltable{
						 margin-bottom: 0.5em;
				 }
				 table.sqltable th {
						 background-color:#336666;
						 color:#66FFFF;
				 }
				 table.sqltable tr {
 						 background-color:#33FFCC;
				 }
				 table.sqltable tr.odd {
						 background-color:#33CCCC; 
				 }
				</style>
		</head>

		<body>
				<?php

				$bugreport->run($_GET);

				?>
		</body>
</html>
