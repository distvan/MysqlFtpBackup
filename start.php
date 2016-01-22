<?php
require 'vendor/autoload.php';
use Distvan\Backup;

$config = array(
  'db_host' => '127.0.0.1', //database host
  'db_name' => array('database1', 'databas2', 'database3'), //list of databases to dump
  'db_user' => '', //database username
  'db_pass' => '', //database password
  'ftp_host' => 'ftp.domain.com',
  'ftp_user' => '', //ftp username
  'ftp_pass' => '', //ftp password
  'ftp_remote_dir' => 'remote-folder-name',
  'ftp_local_dir' => 'temp',
  'max_backup_size' => 4 //Mbyte
);
$backup = new Backup($config);
?>
