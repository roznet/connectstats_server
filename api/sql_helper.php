<?php
/**
 *
 */
 
class sql_helper {
	var $connection;
	var $db_database = NULL;
	var $current_query = NULL;
	var $current_query_str = NULL;
	var $lasterror = NULL;
	
	var $readOnly = 0;	
	var $verbose = false;
	
	var $fieldsInfo = NULL;
	var $tableInfo = NULL;
    //
    //	
    //	Constuctor and setup
    //
    //
    //
	function toString(){
		printf( "connection: %s<br />\n", $this->connection->info );
		printf( "database: %s<br />\n", $this->db_database );
		if( $this->current_query_str ){
			printf( "query str: %s<br />\n", $this->current_query_str );
		}
		if( $this->lasterror ){
			printf( "error: %s<br />\n", $this->lasterror );
		}
	}

	function printError(){
        if( $this->lasterror ){
            print( $this->toString() );
        }
	}

	static function get_instance() {
		static $instance;
		if( ! isset( $instance ) ){
			$instance = new sql_helper();
		}
		return( $instance );
	}
	
	function make_read_only(){
		$this->readOnly = 1;
	}
	function make_read_write(){
		$this->readOnly = 0;
	}
	function __construct( $input, $db_key = 'database' ) {
        if( is_array( $input ) ){
            $api_config = $input;
            $db = $api_config[$db_key];
        }else{
            $db = $input;
            include( 'config.php');
        }

		$this->db_database = $db;
		$this->db_username = $api_config['db_username'];
		$this->db_password = $api_config['db_password'];;
		
		$this->connection = new mysqli($api_config['db_host'], $this->db_username, $this->db_password );
		if( ! $this->connection ){
			die( "Could not connect to the database: <br />".mysqli_error());
		};
		$this->fieldsInfo = NULL;
		$this->tableInfo = NULL;
		$this->connection->select_db( $this->db_database );
		$this->connection->query( "SET NAMES 'utf8' COLLATE 'utf8_unicode_ci'" );
	}
	
	function init_tableInfo( $force = false ){
		if( $this->fieldsInfo == NULL || $force == true ){
			$this->fieldsInfo = array();
			$this->tableInfo = array();
			$result = $this->connection->query( 'show tables' );
			if( $result ){
                while ($row = mysqli_fetch_row($result)) {
                    $this->tableInfo[ $row[0] ] = array();
                }
                foreach( $this->tableInfo as $table => $dummy ){
                    $info_f = $this->connection->query( "DESCRIBE `$table`" );

                    while( $row_f = mysqli_fetch_array( $info_f ) ){
                        $this->fieldsInfo[ $row_f[ 'Field' ] ] = $row_f[ 'Type' ];
                        $this->tableInfo[ $table ][ $row_f[ 'Field' ] ] = $row_f[ 'Type' ];
                    };		
                }
            }else{
                if( $this->verbose ){
                    print( "INFO: empty db".PHP_EOL );
                }
            }
		};
	}
    //
    //	
    //	Values conversions and processing
    //
    //
    //
	function value_to_timestamp( $value ){
		if( gettype( $value ) == 'string' ){
			return( strtotime( $value ) );
		};
		return( $value );
	}
	function value_to_sqldatetime( $value ){
		$timestamp = $value;
		if( gettype( $value ) == "string" ){
			$timestamp = strtotime( $value );
		}
		return( strftime( "%Y-%m-%d %H:%M:%S", $timestamp ) );
	}
	function value_to_sqldate( $value ){
		$timestamp = $value;
		if( gettype( $value ) == "string" ){
			$timestamp = strtotime( $value );
		}
		return( strftime( "%Y-%m-%d", $timestamp ) );
	}
	function format_sqlvalue( $field, $value ){
		$this->init_tableInfo();
		$rv = "";
		$type = $this->fieldsInfo[ $field ];
		$strtype = (strpos( $type , 'varchar' ) !== FALSE);
		if( $type == "date" ){
			$rv = $this->value_to_sqldate( $value );
		}elseif( $type == "datetime" ){
			$rv = $this->value_to_sqldatetime( $value );
		}elseif( $strtype ){
            $rv = $this->connection->real_escape_string( $value );
            $rv = $value;
		}elseif( is_numeric( $value ) ){
			$rv = $value;
		}else{
			$rv = $this->connection->real_escape_string( $value );
		};
		if( !is_numeric( $value ) || $type == "datetime" || $strtype ){
			$rv = "'$rv'";
		};
		return( $rv );
	}
	function fields_equal_values( $table, $row, $id_array = NULL, $separator = ", " ){
		if( $id_array == NULL ){
			$id_array = array_keys( $row );
		};
		$rv_a = array();
		$rv_f = array();
		$this->init_tableInfo();
		foreach( $id_array as $field ){
            if( isset( $this->tableInfo[ $table ][ $field ] ) ){
                array_push( $rv_a, sprintf( "`%s` = %s", $field, $this->format_sqlvalue( $field, $row[ $field ] ) ) );
            }
		}
		return( join( $separator, $rv_a ) );
	}
	function fields_and_values( $table, $row, $id_array = NULL ){
		if( $id_array == NULL ){
			$id_array = array_keys( $row );
		};
		$values_a = array();
		$values_f = array();
		$this->init_tableInfo();

		foreach( $id_array as $field ){
			if( isset( $this->tableInfo[ $table ][ $field ] ) ){
				array_push( $values_a, $this->format_sqlvalue( $field, $row[ $field ] ) );
				array_push( $values_f, sprintf( '`%s`', $field ) );
			};
		}
		return( array( 'fields' => join( ", ", $values_f), 'values' => join( ", ", $values_a ) ) );
	}

    //
    //
    //	Query Functions
    //
    //
	function query_as_structure( $query, $byfield, $allowmultiple = 0 ){
		$rv = array();
		$this->query_init( $query );
		while( $row = $this->query_next() ){
			if( ! isset( $row[ $byfield ] ) ){
				die( "can't index by $byfield , fields are: ".join(", ", array_keys( $row ) ) );
			};
			if( isset( $rv[ $row[ $byfield ] ] ) ){
                if( !$allowmultiple ){
					die( "can't index by $byfield , multiple values ".$row[ $byfield ] );
                }else{
                    array_push( $rv[ $row[ $byfield ] ],  $row );
                }
			}else{
                if($allowmultiple ){
                    $rv[ $row[ $byfield ] ] = array( $row );
                }else{
                    $rv[ $row[ $byfield ] ] = $row;
                }
			}
		};
		return( $rv );
	}
	function query_as_key_value( $query, $byfield, $valfield, $allowmultiple = 0 ){
		$rv = array();
		$this->query_init( $query );
		while( $row = $this->query_next() ){
			if( ! isset( $row[ $byfield ] ) ){
				die( "can't index by $byfield , fields are: ".join(", ", array_keys( $row ) ) );
			};
			if( isset( $rv[ $row[ $byfield ] ] ) ){
				if( !$allowmultiple )
					die( "can't index by $byfield , multiple values ".$row[ $byfield ] );
			}else{
				$rv[ $row[ $byfield ] ] = $row[ $valfield]; 
			}
		};
		return( $rv );
	}
	function query_as_html_table( $query, $links = NULL ){
		$rv = "<table class=sqltable>\n";
		$this->query_init( $query );
		$titledone = false;
		$i=0;
		while( $row = $this->query_next() ){
			if( ! $titledone ){
                $rv .= sprintf( "<tr><th>%s</th></tr>\n", join( '</th><th>', array_keys( $row ) ) );
                $titledone = true;
			}
		 	$rowVal = array_values( $row );
		  	$rowKey = array_keys( $row );
		  	for( $k = 0; $k < count( $rowVal ); $k++ ){
                $rowVal[ $k ] = htmlspecialchars( $rowVal[ $k ] );
                if( isset( $links[ $rowKey[ $k ] ] ) ){
			  		$href = sprintf( $links[ $rowKey[ $k ] ], $rowVal[$k] );
                    $rowVal[ $k ] = sprintf( '<a href="%s">%s</a>', $href , $rowVal[$k] );
                }
		 	}
		  	if( $i % 2 == 0 ){
                $rv .= sprintf( "<tr><td>%s</td></tr>\n", join( '</td><td>', $rowVal ) );
		  	}else{
                $rv .= sprintf( "<tr class=odd><td>%s</td></tr>\n", join( '</td><td>', $rowVal ) );
		  	};
		  	$i++;
		}
		$rv .= "</table>\n";
		return( $rv );
	}

	function query_as_array( $query ){
		$rv = array();
		$this->query_init( $query );
		while( $row = $this->query_next() ){
			array_push( $rv, $row );
		};
		return( $rv );
	}
	function query_field_as_array( $query, $field ){
		$rv = array();
		$this->query_init( $query );
		while( $row = $this->query_next() ){
            if( isset( $row[ $field ] ) ){
                array_push( $rv, $row[ $field ] );
            }else{
                array_push( $rv, NULL );
            }
		};
		return( $rv );
	}
	function query_first_row( $query ){
		$this->query_init( $query );
        $rv = $this->query_next();
        $this->query_close();
        return $rv;
	}
	function query_init( $query ){
		$this->current_query_str = $query; // for ref
		$this->lasterror = NULL;
		$this->current_query = $this->connection->query( $query );
        if( $this->verbose ){
            printf( "EXECUTE: %s".PHP_EOL, $query );
        }
        if( $this->verbose ){
            if( ! $this->current_query ){
                printf( "ERROR: %s">PHP_EOL, $this->connection->error );
            }
			$this->lasterror = $this->connection->error;
		};
		return( $this->current_query );
	}
	function query_next(){
		$rv = NULL;
		if( $this->current_query ){
			$rv = $this->current_query->fetch_array( MYSQLI_ASSOC );
			if( ! $rv ){
				$this->current_query = NULL;
			}else{
				foreach( $rv as $k => $v ){
					if( is_string( $v ) ) {
						$rv[$k] = stripslashes( $v );
					};
				}
			}
		};
		return( $rv );
	}
    function query_close(){
        if( $this->current_query ){
            $this->current_query->close();
            $this->current_query = NULL;
        }
    }
    //
    //
    // Update and modify data
    //
    //
	function create_or_alter( $table, $defs, $rebuild = false, $temporary = false ){
        $this->init_tableInfo();
        $create = false;
        if( isset( $this->tableInfo[ $table ] ) ) {
            if( $rebuild == true  ){
                $query = sprintf( 'DROP TABLE `%s`', $table );
                $this->execute_query( $query );
                $create = true;
            };
        }else{
            $create = true;
        }

        if( $create ){
            $fulldefs = array();
            foreach( $defs as $col => $def ){
                array_push( $fulldefs, sprintf( '%s %s', $col, $def ) );
            }
            if( $temporary ){
                $query = sprintf( 'CREATE TEMPORARY TABLE `%s` (%s) DEFAULT CHARSET=utf8', $table, join( ',', $fulldefs ) );
            }else{
                $query = sprintf( 'CREATE TABLE `%s` (%s) ENGINE=INNODB DEFAULT CHARSET=utf8', $table, join( ',', $fulldefs ) );
            }
            $this->execute_query( $query );
        }else{
            foreach( $defs as $col => $def ){
                if( isset( $this->tableInfo[ $table ][ $col ] ) ){
                    $existing = strtoupper( $this->tableInfo[ $table ][ $col ] );
                    $candidate = strtoupper( str_replace( ' PRIMARY KEY', '', $def ) );
                    $candidate = strtoupper( str_replace( ' AUTO_INCREMENT', '', $candidate ) );
                    if( $existing != $candidate ){
                        if( $this->verbose ){

                            printf( "INFO: %s.%s: [%s] != [%s]\n", $table, $col, $existing, $candidate );
                        }
                        $query = sprintf( 'ALTER TABLE `%s` CHANGE COLUMN `%s` `%s` %s', $table, $col, $col, $candidate );
                        $this->execute_query( $query );
                    }
                }else{
                    $query = sprintf( 'ALTER TABLE `%s` ADD COLUMN `%s` %s', $table, $col, $def );
                    $this->execute_query( $query );
                }
            }
        }
        $this->init_tableInfo( true ); //reload fields as it could have changed

	}

	function insert_or_update( $table, $row, $id_array = array() ){
        $result = true;
        
		if( count( $id_array ) > 0 ){
			$exists = $this->row_exists( $table, $row, $id_array );
		}else{
			$exists = 0;
		}
		if( $exists ){
			if( $this->row_needs_update( $table, $row, $id_array ) ){
				$query = sprintf( "UPDATE `%s` SET %s WHERE %s LIMIT 1;", 
                                  $table,
                                  $this->fields_equal_values( $table, $row ), 
                                  $this->fields_equal_values( $table, $row, $id_array, " AND " ) );
			};
		}else{
			$split = $this->fields_and_values( $table, $row );
			$query = sprintf( 'INSERT INTO `%s` (%s) VALUES (%s);', $table, $split[ 'fields' ], $split[ 'values' ] );			
		};
		if( isset( $query ) ){
			$result = $this->execute_query( $query );
		};
        return $result;
	}

    function insert_id() {
        return $this->connection->insert_id;
    }
	function execute_query( $query ){
		$result = true;
		if( $this->readOnly ){
			printf( "READONLY: %s\n", $query );
		}else{
			if( $this->verbose ){
				printf( "EXECUTE: %s\n", $query );
			};
			$this->current_query_str = $query;
			$result = $this->connection->query( $query );
			if( ! $result ) {
				$this->lasterror = $this->connection->error;
				if( $this->verbose ){
                    printf( "ERROR: %s\n", $this->lasterror );
				}
			};
		}
		return( $result );
	}

	function ensure_field( $db, $field, $def ){
		if( !$this->query_init( sprintf('select %s from %s', $field, $db ) ) ){
			$this->execute_query( sprintf( 'ALTER TABLE %s ADD %s %s', $db, $field, $def ) );
		}
	}


    //
    //
    // Helper to query info about data
    //
    //
	function row_exists( $table, $row, $id_array = array() ){
		$query = sprintf( "SELECT * FROM %s WHERE %s", $table, $this->fields_equal_values( $table, $row, $id_array, " AND " ) );
		$exists = $this->query_as_array( $query );
		return( isset( $exists ) && count( $exists ) > 0 );
	}
	function row_needs_update( $table, $row, $id_array = array() ){
		$query = sprintf( "SELECT * FROM %s WHERE %s", $table, $this->fields_equal_values( $table, $row, $id_array, " AND " ) );
		$rows = $this->query_as_array( $query );
		$needs_update = false;
		if( isset( $rows ) && count( $rows ) > 0 ){
			foreach( $rows as $db_row ){
				foreach( $db_row as $key => $val ){
					if( isset( $row[ $key ] ) && $row[ $key ] != $val ){
						$needs_update = true;
					}
				}
			}
		}else{
			$needs_update = true;
		}
		return(  $needs_update );
	}

	function min_value( $table, $field, $where ="" ){
		$maxfield = sprintf( "min(%s)", $field );
		$query = sprintf( "SELECT %s FROM %s %s LIMIT 1", $maxfield, $table, $where ) ;
		$res = $this->query_first_row( $query );
		return( $res[ $maxfield ] );
	}
	function max_value( $table, $field, $where ="" ){
		$maxfield = sprintf( "max(%s)", $field );
		$query = sprintf( "SELECT %s FROM %s %s LIMIT 1", $maxfield, $table, $where ) ;
		$res = $this->query_first_row( $query );
		return( $res[ $maxfield ] );
	}
	function count_rows( $table, $where = "" ){
		$countfield = "count(*)";
		$query = sprintf( "SELECT %s FROM %s %s LIMIT 1", $countfield, $table, $where ) ;
		$res = $this->query_first_row( $query );
		return( $res[ $countfield ] );
	}
	function table_exists( $table ){
        $rv = false;
		$query = sprintf( "SHOW TABLE STATUS like '%s'", $table );
		$stmt = $this->connection->prepare( $query );
        if( $stmt ){
            $stmt->execute();
            $stmt->store_result();
            $rv = $stmt->num_rows()==1;
            $stmt->close();
        }else{
            if( $this->verbose ){
                printf( "ERROR: $query %s", $this->connection->error );
            }
        }
        return $rv;
	}
};
?>
