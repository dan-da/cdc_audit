#!/usr/bin/env php

<?php

exit ( main() );

/**
 * Application main function.  Retrieves cli args and runs engine.
 */
function main() {

    $opt = getopt("D:d:h:u:p:o:v:m:n:c:t:s:xw?");
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
    $config['output_dir'] = get_option( $opt, 'm', './cdc_audit_sync' );
    $config['tables'] = get_option( $opt, 't', null );
    $config['wipe'] = isset( $opt['w'] ) ? true : false;
    $config['stdout'] = STDOUT;
    
    if( isset( $opt['o'] ) ) {
        $fh = fopen( $opt['o'], 'w' );
        if( !$fh ) {
            die( "Could not open {$opt['o']} for writing" );
        }
        $config['stdout'] = $fh;
    }
    
    $engine = new cdc_audit_sync_mysql( $config );
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

   cdc_audit_sync_mysql.php [Options] -d <db> [-h <host> -d <db> -u <user> -p <pass>]
   
   Required:
   -d db              mysql database name
   
   Options:
   
   -h HOST            hostname of machine running mysql.  default = localhost
   -u USER            mysql username                      default = root
   -p PASS            mysql password                      

   -m output_dir      path to write db audit files.       default = ./cdc_audit_sync.
                                                          
   -t tables          comma separated list of tables.      default = generate for all tables
   
   -w                 wipe (delete) all but the very last audit row after syncing.
                      this operation is performed with a truncate and tmp table.
                      
                      Note: this functionality is mostly untested!  dangerous!
   
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
 * This class is the meat of the script.  It reads the source audit tables
 * and syncs any new rows to the target CSV file.
 */
class cdc_audit_sync_mysql {

    private $host;
    private $user;
    private $pass;
    private $db;
    
    private $verbosity = 1;
    private $stdout = STDOUT;

    private $output_dir;
    
    private $tables = null;
    private $wipe = false;
    
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
        $this->output_dir = $config['output_dir'];
        $this->wipe = $config['wipe'];
    
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
            $success = $this->sync_audit_tables();
        }
        
        return $success;
    }
   
   /**
    * Queries mysql information_schema and syncs audit tables to csv files
    */
    private function sync_audit_tables() {
        
        try {
        
            $this->ensure_dir_exists( $this->output_dir );
            
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
                    
                    if( !strstr( $table, '_audit' ) ) {
                        $this->log( sprintf( 'Found table %s.  Does not appears to be an audit table.  skipping', $table ),  __FILE__, __LINE__, self::log_info );
                        continue;
                    }
                    
                    if( is_array( $this->tables ) && !@$this->tables[$table] ) {
                        $this->log( sprintf( 'Found audit table %s.  Not in output list.  skipping', $table ),  __FILE__, __LINE__, self::log_info );
                        continue;
                    }
                                        
                    $this->sync_table( $table );
                }
                
                $this->log( sprintf( 'Successfully synced audit tables to %s', $this->output_dir ),  __FILE__, __LINE__, self::log_warning );
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
     * Syncs audit table to csv file. 
     */
    private function sync_table( $table ) {
        
        $this->log( sprintf( "Processing table %s", $table ),  __FILE__, __LINE__, self::log_info );
        
        $pk_last = $this->get_latest_csv_row_pk( $table );
        $result = mysql_query( sprintf( 'select * from `%s` where audit_pk > %s', $table, $pk_last ) );
        
        $mode = $pk_last == -1 ? 'w' : 'a';
        $fh = fopen( $this->csv_path( $table ), $mode );
        
        if( !$fh ) {
            throw new Exception( sprintf( "Unable to open file %s for writing", $this->csv_path( $table ) ) );
        }
        
        if( $pk_last == -1 ) {
            $this->write_csv_header_row( $fh, $result );
        }

        while ($row = mysql_fetch_array($result, MYSQL_NUM)) {
            fputcsv( $fh, $row );
        }
        
        fclose( $fh );
        
        if( $this->wipe ) {
            $this->wipe_audit_table( $table );
        }
    }

    /**
     * Wipes the audit table of all but the last row.
     *
     * Using delete is slow but plays well with concurrent connections.
     * We use an incremental delete to avoid hitting the DB too hard
     * when wiping a large table.
     *
     * truncate plus tmp table for the last record would be faster but I can't
     * find any way to do that atomically without possibility of causing trouble
     * for another session writing to the table.  Same thing for rename.
     *
     * For most applications, if this incremental wipe is performed during each
     * regular sync, then the table should never grow so large that it becomes
     * a major problem.
     *
     * @TODO:  add option to wipe only older than a specific age.
     */
    private function wipe_audit_table( $table ) {
        
        $this->log( sprintf( 'wiping audit table: %s', $table ), __FILE__, __LINE__, self::log_info );
        
        $incr_amount = 100;

        $loop = 1;        
        do {

            if( $loop ++ > 1 ) {
                sleep(1);
            }
            
            $result = @mysql_query( sprintf( 'select count(audit_pk) as cnt, min(audit_pk) as min, max(audit_pk) as max from `%s`', $table ) );
            $row = @mysql_fetch_assoc( $result );
            
            $cnt = @$row['cnt'];
            $min = @$row['min'];
            $max = @$row['max'];
            
            if( $cnt <= 1 || !$max ) {
                break;
            }

            $delmax = min( $min + $incr_amount, $max );
            $this->log( sprintf( 'wiping audit table rows %s to %s', $min, $delmax ), __FILE__, __LINE__, self::log_info );
            
            $query = sprintf( 'delete from `%s` where audit_pk >= %s and audit_pk < %s', $table, $min, $delmax );
            $result = mysql_query( $query );
            
            if( !$result ) {
                throw new Exception( sprintf( "mysql error while wiping %s rows.  %s", $incr_amount, $query  ) );
            }
            
        } while( true );
    }

    /**
     * given csv fh and mysql result, writes a csv header row with column names
     */
    private function write_csv_header_row( $fh, $result ) {
        
        $cols = array();
        $i = 0;
        while ($i < mysql_num_fields($result)) {
            $meta = mysql_fetch_field($result, $i);
            $cols[] = $meta->name;
            $i ++;
        }
        
        fputcsv( $fh, $cols );
    }


    /**
     * given source table name, primary key value of latest row in csv file, or -1
     */
    private function get_latest_csv_row_pk( $table ) {
        
        $last_pk = -1;
        
        $lastline = $this->get_last_line( $this->csv_path( $table ) );
        
        $row = @str_getcsv( $lastline );
        
        $cnt = count($row);
        
        if( $cnt > 5 ) {
            $tmp = @$row[ $cnt-1 ];  //audit_pk is always last column.
            
            if( is_numeric( $tmp ) ) {
                $last_pk = $tmp;
            }
        }
        return $last_pk;
    }

    /**
     * returns the last line of a file, or empty string.
     */
    private function get_last_line( $filename ) {
        
        if( !file_exists( $filename ) ) {
            return '';
        }
        
        $fp = @fopen( $filename, 'r');

        if( !$fp ) {
            throw new Exception( sprintf( "Unable to open file %s for reading", $filename ) );
        }
        
        $pos = -1; $line = ''; $c = '';
        do {
            $line = $c . $line;
            fseek($fp, $pos--, SEEK_END);
            $c = fgetc($fp);
        } while ( $c !== false && $c != "\n" );
        
        fclose($fp);
        
        return $line;
    }
   
    /**
     * given source table name, returns audit sql filename
     */
    private function csv_path( $table ) {
        return sprintf( "%s/%s.csv", $this->output_dir, $table );
    }

}
