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

include_once( dirname( __DIR__, 1 ) ) . '/api/sql_helper.php';

class BugReport {

    function __construct(){
				include( 'config_bugreport.php' );

				$this->sql = new sql_helper( $bug_config );
				$this->bug_data_directory = $bug_config['bug_data_directory'];
    }
    
    function links(){
        print '<table class="linktable"><tr><td><a href="list?nonblank=1">nonblank</a></td><td><a href="list?needreply=1">need reply</a></td><td><a href="list?all=1">all</a></td><td><a href="list?errors=1">errors</a></td></tr></table>';
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

    function bugreport_line_parse( $txt ){
        $line = NULL;
        if( preg_match( '/([0-9]+-[0-9]+-[0-9]+ [:.0-9]+) ([:0-9a-f]+) [-EW] (INFO|ERR |WARN):([A-Za-z0-9.+]+):([0-9]+):([^;]+); (.*)/', $txt, $matches ) ){
            $line = array(
                'time' => $matches[1],
                'pid' => $matches[2],
                'level' => $matches[3],
                'filename' => $matches[4],
                'line' => $matches[5],
                'method' => $matches[6],
                'message' => $matches[7],
                'raw' => $txt
            );
        }else if( preg_match( '/([0-9]+-[0-9]+-[0-9]+ [:.0-9]+) ([:0-9a-f]+) [-EW] (INFO|ERR |WARN):([A-Za-z0-9.+]+):([0-9]+):([-+]\[[^\]]+\])(.*)/', $txt, $matches ) ){
            $line = array(
                'time' => $matches[1],
                'pid' => $matches[2],
                'level' => $matches[3],
                'filename' => $matches[4],
                'line' => $matches[5],
                'method' => $matches[6],
                'message' => $matches[7],
                'raw' => $txt
            );
        }
        
        return $line;
    }
    
    function bugreport_line_are_duplicates( $line, $prev_line ){
        if( $line == NULL || $prev_line == NULL ){
            return false;
        }

        return( $line['level'] == $prev_line['level'] && $line['method'] == $prev_line['method'] && $line['message'] == $prev_line['message'] );
    }
    
    function bugreport_line_format( $line, $is_compact ){
        if( $line ){
            if( $is_compact ){
                $prefix = sprintf( '%s', $line['time'] );
                $type   = $line['level'];
                $url = sprintf( 'https://github.com/roznet/connectstats/blob/master/ConnectStats/src/%s#L%s', $line['filename'], $line['line'] );
                $method = $line['method'];
                if( strpos( $method, '[' ) === false ){
                    // objective c method include class, otherwise add file in compact mode
                    $method = sprintf( '%s.%s', pathinfo($line['filename'], PATHINFO_FILENAME), $method );
                }
                $source = sprintf( '<a href="%s" class="method">%s</a>', $url, $method );
                $class = $this->class_bugreport( $line['raw'] );
                return sprintf( '<td class="line_prefix">%s</td><td class="%s">%s</td><td class="line_source">%s</td><td class="%s">%s</td>', $prefix, $class, $type, $source, $class, $line['message'] );
            }else{
                $prefix = sprintf( '%s %s', $line['time'], $line['pid'] );
                $type   = $line['level'];
                $url = sprintf( 'https://github.com/roznet/connectstats/blob/master/ConnectStats/src/%s#L%s', $line['filename'], $line['line'] );
                $source = sprintf( '<a href="%s" class="method">%s:%s</a>', $url, $line['filename'], $line['line'] );
                $class = $this->class_bugreport( $line['raw'] );
                return sprintf( '<td class="line_prefix">%s</td> <td class="%s">%s</td> <td class="line_source">%s</td><td class="%s"><div class="method">%s</div> %s</td>', $prefix, $class, $type, $source, $class, $line['method'], $line['message'] );
            }
        }
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
        print '</td></tr><tr><td><h4>System</h4></td><td>';
        print implode( ' ', array( $row['systemName'], $row['systemVersion'], $row['platformString'] ) );
        print '</td></tr><tr><td><h4>linked errors</h4></td><td>';
        printf ('<a href="list?commonid=%d">%d reports</a>', $row['commonid'], $other['n'] );
        printf( '</td></tr><tr><td><h4>File</h4></td><td><a href="export?id=%d&zip">%s</a>%s',$id,$fn, PHP_EOL);
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
                                        if( substr( $this->bug_data_directory, 0, 1 ) == '/' ){
                                            $phpliteurl = sprintf( '%s/sqlite/%d_%s', $this->bug_data_directory, $id, $file, $file );
                                        }else{
                                            $phpliteurl = sprintf( '../%s/sqlite/%d_%s', $this->bug_data_directory, $id, $file, $file );
                                        }
                                        printf( $phpliteurl.PHP_EOL );
                    printf( '<a href="phpliteadmin/?switchdb=%s&backurl=%s">Open %s</a> [<a href="%s">re-extract</a> %s]'.PHP_EOL, urlencode($phpliteurl),urlencode( $backurl ), $file, $extract_url, $outname );
                    print( '</pre>' );
                }else if( $is_bugreport ){
                    $lines = explode( "\n", $content );
                    if( $is_raw ){
                        print( '<ul class="bugreport_line">'.PHP_EOL );
                    }else{
                        print( '<table class="bugreport_line">'.PHP_EOL );
                    }
                    $prev_line = NULL;
                    $prev_line_count = 0;
                    foreach( $lines as $line ){
                        $raw = htmlspecialchars($line);
                        if( $is_raw ){
                            printf( '<li>%s</li>'. PHP_EOL,  $raw );
                        }else{
                            $line =  $this->bugreport_line_parse( $raw );
                            if( $this->bugreport_line_are_duplicates( $line, $prev_line ) ){
                                $prev_line_count += 1;
                            }else{
                                if( $prev_line_count > 1 && $prev_line){
                                    $prev_line['method'] .= sprintf( ' <b>[REPEATED %d]</b>', $prev_line_count );
                                    $raw = $this->bugreport_line_format($prev_line, $is_compact);
                                    printf('<tr class="bugreport_line">%s</tr>' . PHP_EOL,  $raw);
                                }
                                if ($line) {
                                    $raw = $this->bugreport_line_format($line, $is_compact);
                                    printf('<tr class="bugreport_line">%s</tr>' . PHP_EOL,  $raw);
                                }
                                $prev_line_count = 0;
                            }
                            $prev_line = $line;
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
        $query = $this->summary_query();
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
                    $last_line = NULL;
                    $last_line_message = NULL;
                    $last_line_repeat_count = 0;
                    foreach( $lines as $line ){
                        if( strpos( $line, 'E ERR')!==FALSE) {
                            $line_message = substr( $line, strpos( $line, 'E ERR' ) );
                            if( $last_line_message == $line_message ){
                                $last_line_repeat_count += 1;
                                $last_line = $line; // keep the time stamp going
                            }else{
                                if( $last_line_repeat_count > 1 ){
                                    $errors .= sprintf( '%s [REPEATED %d]'.PHP_EOL, htmlspecialchars($last_line), $last_line_repeat_count );
                                }
                                $errors .= htmlspecialchars( $line ).PHP_EOL;

                                $last_line = $line;
                                $last_line_message = $line_message;
                                $last_line_repeat_count = 0;
                            }
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
                $fn = $this->file_path_for_row($row);
                
                printf( '<table class=sqltable width="90%%"><tr %s><td width="3%%">', $class);
                printf( '<a href="list?id=%d">%d</a>', $one['id'], $one['id']);
                print(  '</td><td>' );
                printf( '<a href="list?commonid=%d&errors=1">%d</a>',  $one['commonid'], $one['commonid']);
                print(  '</td><td>');
                print(  implode( ' ', array( $row['systemName'], $row['systemVersion'], $row['platformString'], $row['version'] ) ) );
                print(  '</td><td>' );
                printf( '%s', $row['updatetime']);
                print(  '</td><td>');
                print(  htmlspecialchars($row['email']));
                if( $row['replied'] ){
                    printf( ' [Replied %s]', $row['replied'] );
                }
                print(  '</td><td>');
                print(  htmlspecialchars($row['description']));
                print(  '</td></tr></table>'.PHP_EOL);
                print(  '<pre>'.$errors.'</pre>');
            }
        }
    }

    function summary_query(){
        if( isset( $this->args['commonid'] ) ){
            $query = sprintf('SELECT * FROM gc_bugreports WHERE commonid=%d ORDER BY updatetime DESC', $this->args['commonid'] );
        }else if(isset($this->args['all'])){
            $query = sprintf( 'SELECT * FROM gc_bugreports ORDER BY updatetime DESC LIMIT %d', $this->limit );;
        }else if(isset($this->args['needreply'])){
            $query = sprintf( "SELECT * FROM gc_bugreports WHERE description != '' AND email != '' AND ISNULL(replied) ORDER BY updatetime DESC LIMIT %d", $this->limit );
        }else{
            $query = sprintf( "SELECT * FROM gc_bugreports WHERE description != '' OR email != '' ORDER BY updatetime DESC LIMIT %d", $this->limit );
        }
        return $query;
    }
    
    function summary_table(){
        $query = $this->summary_query();
        
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
