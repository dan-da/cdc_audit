#!/usr/bin/env php

<?php

exit ( main() );

/**
 * Application main function.  Retrieves cli args and runs engine.
 */
function main() {

    $opt = getopt("D:d:h:u:p:o:v:m:n:c:t:s:x?");
    if( @$opt['?'] || !@$opt['d'] ){ 
        print_help();
        return -1;
    }
    
    $config = array( );
    $config['db'] = get_option( $opt, 'd' );
    $config['host'] = get_option( $opt, 'h', 'localhost' );
    $config['user'] = get_option( $opt, 'u', 'root' );
    $config['pass'] = get_option( $opt, 'p', '' );
    $config['namespace_prefix'] = get_option( $opt, 'n', '' );
    $config['verbosity'] = get_option( $opt, 'v', 1 );
    $config['audit_dir'] = get_option( $opt, 'm', './cdc_audit_gen' );
    $config['tables'] = get_option( $opt, 't', null );
    $config['stdout'] = STDOUT;
    
    if( isset( $opt['o'] ) ) {
        $fh = fopen( $opt['o'], 'w' );
        if( !$fh ) {
            die( "Could not open {$opt['o']} for writing" );
        }
        $config['stdout'] = $fh;
    }
    
    $engine = new cdc_audit_gen_mysql( $config );
    $success = $engine->run();
   
    fclose( $config['stdout'] );
    return $success ? 0 : -1;
}

/**
 * Utility function for getting cli arg with default
 */
function get_option($opt, $key, $default=null) {
    return isset( $opt[$key ]) ? $opt[$key] : $default;
}

/**
 * Prints CLI usage information
 */
function print_help() {
   
   echo <<< END

   gen_mysql_triggers.php [Options] -d <db> [-h <host> -d <db> -u <user> -p <pass>
   
   Required:
   -d db              mysql database name
   
   Options:
   
   -h HOST            hostname of machine running mysql.  default = localhost
   -u USER            mysql username                      default = root
   -p PASS            mysql password                      

   -m audit_dir       path to write db audit files.       default = ./cdc_audit_gen.
                                                          
   -t tables         comma separated list of tables.      default = generate for all tables
   
   -n namespace      a prefix that will be pre-pended to all classnames.  This makes it
                     possible to use the generated classes multiple times in the same project.
                                                          default = no prefix.

   -o file            Send all output to FILE
   -v <number>        Verbosity level.  default = 1
                        0 = silent except fatal error.
                        1 = silent except warnings.
                        2 = informational
                        3 = debug. ( includes extra logging and source line numbers )
                        
    -?                Print this help.


END;

}


/**
 * This class is the meat of the script.  It generates the audit tables
 * and triggers
 */
class cdc_audit_gen_mysql {

    private $host;
    private $user;
    private $pass;
    private $db;
    
    private $verbosity = 1;
    private $stdout = STDOUT;

    /**
     * Set this value to prefix all classes/tables/files.  This
     * helps avoid namespace collisions when using multiple
     * db_audit in a single app.  I recommend using the form
     * 'xxx_' as the prefix.
     *
     * note: needs to be re-thought.
     */
    private $namespace_prefix = '';
    
    private $output_dir = './db_audit';
    
    private $tables = null;
    
    const log_error = 0;
    const log_warning = 1;
    const log_info = 2;
    const log_debug = 3;

    /**
     * Class constructor.  Requires a keyval config array.
     */
    public function __construct( $config ) {
       
        $this->host = $config['host'];
        $this->user = $config['user'];
        $this->pass = $config['pass'];
        $this->db = $config['db'];
        $this->output_dir = $config['audit_dir'];
        $this->namespace_prefix = $config['namespace_prefix'];
    
        $tables = @$config['tables'] ? explode( ',', @$config['tables'] ) : null;
        if( $tables ) {
            $this->tables = array();
            foreach( $tables as $t ) {
               $this->tables[trim($t)] = 1;
            }
        }
        
        $this->verbosity = $config['verbosity'];
        $this->stdout = $config['stdout'];
       
    }

    /**
     * Executes the engine
     */
    public function run() {
       
        $success = true;
        if( $this->output_dir && $this->output_dir != '=NONE=' ) {
            $success = $this->create_db_audit();
        }
        
        return $success;
    }
   
   /**
    * Queries mysql information_schema and creates audit tables and triggers.
    */
    private function create_db_audit() {
        
        try {
        
            $this->ensure_dir_exists( $this->output_dir );
            
            $this->log( sprintf( 'deleting audit table definition files in %s', $this->output_dir ),  __FILE__, __LINE__, self::log_debug );
            $files = glob( $this->output_dir . '/*.audit.sql');
            foreach( $files as $file ) {
                
                if( is_array( $this->tables ) ) {
                    $tname = explode( '.', $file);  $tname = $tname[0];
                    if( !@$this->tables[$tname] ) {
                        continue;
                    }
                }
                
                $rc = @unlink( $file );
                if( !$rc ) {
                    throw new Exception( "Cannot unlink old file " . $file );
                }
                $this->log( sprintf( 'deleted %s', $file ),  __FILE__, __LINE__, self::log_debug );
            }
            $this->log( sprintf( 'deleted audit table definition files in %s', $this->output_dir ),  __FILE__, __LINE__, self::log_info );
    
            // $this->write_dao_base_file();
            
            // Connect to the MySQL server
            $this->log( sprintf( 'Connecting to mysql. host = %s, user = %s, pass = %s ', $this->host, $this->user, $this->pass ),  __FILE__, __LINE__, self::log_debug );
            $link = @mysql_connect($this->host,$this->user,$this->pass);
            if ($link){
                $this->log( 'Connected to mysql.  Getting tables.',  __FILE__, __LINE__, self::log_info );
                
                  // Select the database
                if( !mysql_selectdb($this->db,$link) ) {
                    throw new Exception( "Unable to select database {$this->db}");
                }
                
                // Get all tables
                $result = mysql_query('SHOW TABLES');
                while ($row = mysql_fetch_array($result, MYSQL_NUM)) {
                    // Get table name
                    $table = $row[0]  ;
                    
                    if( is_array( $this->tables ) && !@$this->tables[$table] ) {
                        $this->log( sprintf( 'Found table %s.  Not in output list.  skipping', $table ),  __FILE__, __LINE__, self::log_info );
                        continue;
                    }
                    
                    if( strstr( $table, '_audit' ) ) {
                        $this->log( sprintf( 'Found table %s.  Appears to be an audit table.  skipping', $table ),  __FILE__, __LINE__, self::log_info );
                        continue;
                    }
                    
                    // Get table info
                    $sort_clause = '';  // default is unsorted.
                    $struct = mysql_query("select Column_name as Field, Column_Type as Type, Is_Nullable as `Null`, Column_Key as `Key`, Column_Default as `Default`, Extra, Column_Comment as Comment from INFORMATION_SCHEMA.COLUMNS where TABLE_SCHEMA = '{$this->db}' and TABLE_NAME = '$table' $sort_clause");
            
                    $data = array();
                    while ($row2 = mysql_fetch_array($struct, MYSQL_ASSOC)) {
                        $data[] = $row2;
                    }
                    
                    // Get triggers associated with table
                    $struct = mysql_query("select trigger_name, EVENT_MANIPULATION, ACTION_STATEMENT from INFORMATION_SCHEMA.TRIGGERS where EVENT_OBJECT_TABLE = '$table' and ACTION_TIMING = 'AFTER'");
            
                    $triggers = array();
                    while ($row2 = mysql_fetch_array($struct, MYSQL_ASSOC)) {
                        $triggers[] = $row2;
                    }
                    
                    $this->write_table( $table, $data, $triggers );
                }
                
                $this->log( sprintf( 'Successfully Generated Audit Tables + Triggers in %s', $this->output_dir ),  __FILE__, __LINE__, self::log_warning );
            }
            else {
                throw new Exception( "Unable to connect to mysql" );
            }
        }
        catch( Exception $e ) {
            $this->log( $e->getMessage(), $e->getFile(), $e->getLine(), self::log_error );
            return false;
        }
        return true;
    }

    /**
     * Log a message (or not) depending on loglevel
     */
    private function log( $msg, $file, $line, $level ) {
        if( $level >= self::log_debug && $level <= $this->verbosity ) {
            fprintf( $this->stdout, "%s  -- %s : %s\n", $msg, $file, $line );            
        }
        else if( $level <= $this->verbosity ) {
            fprintf( $this->stdout, "%s\n", $msg );
        }
    }
    
    /**
     * Ensure that given directory exists. throws exception if cannot be created.
     */
    private function ensure_dir_exists( $path ) {
        $this->log( sprintf( 'checking if path exists: %s', $path ), __FILE__, __LINE__, self::log_debug );
        if( !is_dir( $path )) {
            $this->log( sprintf( 'path does not exist.  creating: %s', $path ), __FILE__, __LINE__, self::log_debug );
            $rc = @mkdir( $path );
            if( !$rc ) {
                throw new Exception( "Cannot mkdir " . $path );
            }
            $this->log( sprintf( 'path created: %s', $path ), __FILE__, __LINE__, self::log_info );
        }
    }
    
    /**
     * Writes audit table and triggers to file.  one file per table.
     */
    private function write_table( $table, $info, $triggers ) {
        
        $this->log( sprintf( "Processing table %s", $table ),  __FILE__, __LINE__, self::log_info );
    
        $this->write_audit_table( $table, $info );
        $this->write_audit_triggers( $table, $info, $triggers );

    }

    /**
     * Writes the audit table to file.
     */
    private function write_audit_table( $table, $info ) {

        $mask = '

/**
 * Audit table for table (%1$s).
 *
 * !!! DO NOT MODIFY THIS FILE MANUALLY !!!
 *
 * This file is auto-generated and is NOT intended
 * for manual modifications/extensions.
 *
 * For additional documentation, see:
 * https://github.com/dan-da/cdc_audit
 *
 */
';
        $buf = sprintf( $mask, $table );

        $var_mask = <<< 'END'
  `%1$s` %3$s %11$s %10$s %12$s %13$s comment '%14$s'
END;

        $index_mask = <<< 'END'
   index (%1$s)
END;

        $table_mask = <<< 'END'
create table if not exists `%2$s` (
%3$s
);
END;
            
        $table_body = '';
        $table_audit = $this->table_audit( $table );
        
        $info[] = array( 'Field' => 'audit_insert_timestamp', 'Type' => 'timestamp', 'Null' => true, 'Comment' => 'Will be non-null when the record is inserted into source table' );
        $info[] = array( 'Field' => 'audit_update_timestamp', 'Type' => 'timestamp', 'Null' => true, 'Comment' => 'Will be non-null when the record is updated in source table' );
        $info[] = array( 'Field' => 'audit_delete_timestamp', 'Type' => 'timestamp', 'Null' => true, 'Comment' => 'Will be non-null when the record is deleted in source table' );
        $info[] = array( 'Field' => 'audit_change_timestamp', 'Type' => 'timestamp', 'Null' => false, 'Comment' => 'Always non-null.  Updated when record is inserted, updated or deleted in source table' );
        $info[] = array( 'Field' => 'audit_pk', 'Type' => 'int(11)', 'Null' => false, 'Comment' => 'Audit table primary key, useful for sorting since mysql time data types are only granular to second level.' );
      
        $pk_cols = array();
        foreach( $info as $table_column ) {
    
            $php_safe_field = null;
            $dto_type = null;
            
            $comment = @$table_column['Comment'];
            if( @$table_column['Key'] == 'PRI' ) {
                $comment = 'Primary key in source table ' . $table;
            }
     
            $lines[] = sprintf( $var_mask,
                                 $table_column['Field'],
                                 @$table_column['Comment'],
                                 $table_column['Type'],
                                 $table_column['Null'],
                                 @$table_column['Key'],
                                 @$table_column['Default'],
                                 @$table_column['Extra'],
                                 $php_safe_field,
                                 $dto_type,
                                 null,
                                 $table_column['Null'] == 'YES' ? 'null' : 'not null',
                                 $table_column['Field'] == 'audit_pk' ? 'primary key' : '',
                                 $table_column['Field'] == 'audit_pk' ? 'auto_increment' : '',
                                 str_replace( "'", "''", $comment )
                               );
            if( @$table_column['Key'] == 'PRI' ) {
                $pk_cols[] = sprintf( '`%s`', $table_column['Field'] );
            }
        }
    
        $lines[] = sprintf( $index_mask, implode( ', ', $pk_cols ) );
        $lines[] = sprintf( $index_mask, '`audit_change_timestamp`' );
              
        $table_body = implode( ",\n", $lines );
        
        $buf .= sprintf( $table_mask, $table, $table_audit, $table_body );
        
        $filename_table = $this->table_filename( $table );
        $pathname_table = $this->output_dir . '/' . $filename_table;
        $this->log( sprintf( "Writing %s", $pathname_table ),  __FILE__, __LINE__, self::log_info );
        $rc = @file_put_contents( $pathname_table, $buf );
        if( !$rc ) {
            throw new Exception( "Error writing file " . $pathname_table );
        }
    }
    

    /**
     * Writes audit triggers to SQL file. appends to existing file.
     */
    private function write_audit_triggers( $table, $info, $triggers ) {

        $mask = '

/**
 * Audit triggers for table (%1$s).
 *
 * For additional documentation, see:
 * https://github.com/dan-da/cdc_audit
 *
 */
';
        $buf = sprintf( $mask, $table );
        
        $drop_trigger_mask = <<< 'END'
      
DROP TRIGGER IF EXISTS %1$s;

END;

        $triggers_mask = <<< 'END'
-- %1$s after INSERT trigger.
DELIMITER @@
CREATE TRIGGER %1$s_after_insert AFTER INSERT ON %1$s
 FOR EACH ROW BEGIN
  insert into %2$s(%3$s) values(%4$s);

%7$s
 END;
@@

-- %1$s after UPDATE trigger.      
DELIMITER @@
CREATE TRIGGER %1$s_after_update AFTER UPDATE ON %1$s
 FOR EACH ROW BEGIN
  insert into %2$s(%3$s) values(%5$s);

%8$s
 END;
@@

-- %1$s after DELETE trigger.
DELIMITER @@
CREATE TRIGGER %1$s_after_delete AFTER DELETE ON %1$s
 FOR EACH ROW BEGIN
  insert into %2$s(%3$s) values(%6$s);

%9$s
 END;
@@
END;

        $table_audit = $this->table_audit( $table );

        // Drop existing AFTER triggers for this table.
        $tg_map = array();
        foreach( $triggers as $tg_old ) {
            $tgname = @$tg_old['trigger_name'];
            if( $tgname ) {
                $buf .= sprintf( $drop_trigger_mask, $tgname );            
            }
            $event = @strtolower($tg_old['EVENT_MANIPULATION']);
            $statement = @trim( $tg_old['ACTION_STATEMENT'] );
            if( $event && $statement ) {
               
                $needle = 'begin';
                if( strtolower( substr( $statement, 0, strlen($needle) ) ) == $needle ) {
                    $statement = substr( $statement, strlen($needle) );
                }
                $needle = 'end';
                if( strtolower( substr( $statement, - strlen($needle) ) ) == $needle ) {
                    $statement = substr( $statement, 0, - strlen($needle) );
                }
                
                // remove audit statements if present by removing lines containing $table_audit.
                // note that we cannot rely on comments for markers because mysql CLI client strips comments out by default.
                $lines = explode( "\n", $statement );
                $newlines = array();
                foreach( $lines as $line ) {
                    if( !strstr( $line, $table_audit ) ) {
                        $newlines[] = $line;
                    }
                }
                
                $tg_map[$event] = trim( implode( "\n", $newlines ) );
            }
        }
        $buf .= "\n";
        
        $triggers_body = '';
        
        $cols = array();
        $new_vals = array();
        $old_vals = array();
        foreach( $info as $table_column ) {
            $cols[] = $table_column['Field'];
            $new_vals[] = sprintf( 'NEW.%s', $table_column['Field'] );
            $old_vals[] = sprintf( 'OLD.%s', $table_column['Field'] );
        }
        
        $insert_vals = $new_vals;
        $update_vals = $new_vals;
        $delete_vals = $old_vals;
        
        $cols[] = 'audit_insert_timestamp';
        $insert_vals[] = 'CURRENT_TIMESTAMP';
        $update_vals[] = $delete_vals[] = 'null';
        
        $cols[] = 'audit_update_timestamp';
        $update_vals[] = 'CURRENT_TIMESTAMP';
        $insert_vals[] = $delete_vals[] = 'null';      
        
        $cols[] = 'audit_delete_timestamp';
        $delete_vals[] = 'CURRENT_TIMESTAMP';
        $insert_vals[] = $update_vals[] = 'null';      
        
        $cols[] = 'audit_change_timestamp';
        $insert_vals[] = $update_vals[] = $delete_vals[] = 'CURRENT_TIMESTAMP';
        
        foreach( $cols as &$col ) {
            $col = sprintf( '`%s`', $col );
        }
        
        $colnames = implode( ', ', $cols );
        $insert_vals = implode( ', ', $insert_vals );
        $update_vals = implode( ', ', $update_vals );
        $delete_vals = implode( ', ', $delete_vals );
        
        $buf .= sprintf( $triggers_mask,
                         $table,
                         $this->table_audit( $table ),
                         $colnames,
                         $insert_vals,
                         $update_vals,
                         $delete_vals,
                         @$tg_map['insert'],
                         @$tg_map['update'],
                         @$tg_map['delete']
                         );
        
        $filename_table = $this->table_filename( $table );
        $pathname_table = $this->output_dir . '/' . $filename_table;
        $this->log( sprintf( "Writing triggers to %s", $pathname_table ),  __FILE__, __LINE__, self::log_info );
        $rc = @file_put_contents( $pathname_table, $buf, FILE_APPEND );
        if( !$rc ) {
            throw new Exception( "Error writing file " . $pathname_table );
        }
    }

    /**
     * given source table name, returns audit table name
     */
    private function table_audit( $table ) {
        return sprintf( "%s%s_audit", $this->namespace_prefix,  $table );
    }
   
    /**
     * given source table name, returns audit sql filename
     */
    private function table_filename( $table ) {
        return sprintf( "%s%s.audit.sql", $this->namespace_prefix,  $table );
    }

}
