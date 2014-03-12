cdc_audit - Software to automate change data capture via triggers for mysql.

cdc_audit presently consists of two scripts:
 - a script to auto generate mysql audit tables and triggers.
 - a script to auto sync new rows in audit tables to a CSV file.

EXPERIMENTAL!
=============

This software is still in an early prototype form.  It has not yet been
used on production systems and is not recommended for that purpose.


What problem does cdc_audit solve?
==================================

This software was written to solve a particular use-case, but may be useful for
various change data capture tasks.

In our case terabytes of legacy data stored in multiple mysql databases.
Users are demanding better and faster reporting and analytics capabilities than
can be achieved with the transactional databases. Map-R has been installed and a
fancy data analytics package has been chosen that enables end users to interact
directly with the hadoop data and perform queries and visualizations as if they
were working in a spreadsheet. However this data must regularly be synced from
the mysql database that is collecting it to the Map-R filesystem. That's where
cdc_audit can help.

We have a few requirements:

 1) We need the sync process to be efficient.  Only new/updated/deleted rows
    should be synced.
    
 2) We prefer not to be modifying the source tables by hand, eg to add insert
    and update timestamps.  We are looking for a tool that can automate this
    process for us.
    
 3) We would prefer that the solution can accomodate deletes in addition to
    insert and update. Timestamping in the source table can't handle real
    deletes, only logical deletes, which requires custom logic in the
    application.
    
 4) We have a goal not to require any modifications to the existing applications
    that use the source database tables.
    
 5) We have some existing mysql triggers and cannot break or remove them.
 
    
How does cdc_audit meet these requirements?
===========================================

cdc_audit breaks the CDC into three automated pieces:

 a) creation of audit tables for every source table we are interested in.
    The audit tables contain all the columns of the source table plus audit
    columns: audit_event, audit_timestamp, and audit_pk. The audit_event column
    indicates the type of change to the source row: insert, update, or delete.
    The audit_pk column provides a unique id for each audit row, so we can
    easily identify new (unsynced) audit rows. audit_timestamp is unsuitable for
    this purpose because mysql only stores date/time columns to 1 second
    granularity.
    
 b) creation of triggers on the source table(s) to insert rows into the audit
    table(s) whenever a row is inserted, updated, or deleted.  This provides
    a complete audit history of all changes to the source table over time.
    Pre-existing trigger statements are included in the new trigger.
    
 c) a script to sync new rows from the source audit table to a CSV file in
    the target (Map-R) filesystem.
    
 The script cdc_audit_gen_mysql.php implements a) and b).
 
 The script cdc_audit_sync_mysql.php implements c).


Features
========

 - automates generation of audit tables
 - automates generation of triggers to populate audit tables
 - automates syncing of new rows in audit tables to .csv files.
 - Reads mysql information_schema to automatically determine tables and columns.
 - Can generate tables + triggers for all database tables, or a specified list.
 - Can sync audit tables for all database tables, or a specified list.
 - Retains pre-existing trigger logic, if any, when generating AFTER triggers.
 - sync script option to delete all but last audit row, to keep source DB small.
 

Requirements
============

 - PHP 5.3 or greater
 - mysql 5.1 or greater


Usage
=====

 $ ./cdc_audit_gen_mysql.php 

   cdc_audit_gen_mysql.php [Options] -d <db> [-h <host> -d <db> -u <user> -p <pass>]
   
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


 $ ./cdc_audit_sync_mysql.php 


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


Usage Examples
==============

 First, unzip download package to a local directory anywhere.

 
 To generate audit tables and triggers for all tables in a database:
 
    php cdc_audit_gen_mysql.php -d <db> [-h <host> -d <db> -u <user> -p <pass>]
    
 SQL file(s) will be generated in ./cdc_audit_gen.
 They can be applied to your database using the mysql command-line client, eg:
 
 $ mysql -u root <database> < ./cdc_audit_gen/table1.sql

 
 To generate audit tables and triggers for a list of specific tables only:
 
    php cdc_audit_gen_mysql.php -d <db> -t table1,table2,table3 [-h <host> -d <db> -u <user> -p <pass>]


 To sync all audit tables in a database:
 
    php cdc_audit_sync_mysql.php -d <db> [-h <host> -d <db> -u <user> -p <pass>]


 To sync two specific audit tables in a database:
 
    php cdc_audit_sync_mysql.php -d <db> -t table2_audit,table2_audit [-h <host> -d <db> -u <user> -p <pass>]

    
 Once the sync process is running correctly, the command would typically be
 added to a unix crontab schduler in order to run it regularly.



Development
===========

Development is taking place on github.  Patches are welcome.

   http://github.org/dan-da/cdc_audit


Known Issues
==============

 - If you make a change to the source table schema then the audit table and
   trigger will not reflect the change.  You will need to alter the audit table
   manually then re-run cdc_audit_gen_mysql to recreate the triggers.
   
 - no locking is performed on the target CSV file at present.  This could
   cause file corruption.
 
 - column header row should be written to .CSV file.
 
 
Todos
=====

 - Use a lockfile to protect .CSV file.  Map-R nfs does not support flock().
 
 - Write column header row to CSV file.  Also, check the header row when initiating
   sync to ensure that # of columns is unchanged and audit_pk column is correct.
   
 - Auto-Detect schema changes to source table and apply to audit table.
 
 - Enable creation of the audit tables in a separate database if desired.

