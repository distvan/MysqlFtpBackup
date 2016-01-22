<?php
namespace Distvan;

use Monolog\Logger;
use Monolog\Handler\StreamHandler;
use FtpClient\FtpClient;
use FtpClient\FtpException;
use Ifsnop\Mysqldump\Mysqldump;
use wapmorgan\UnifiedArchive\UnifiedArchive;
use DateTime;

/*
  Backup Class

  The class helps to make a zipped database backup and save the dump file to a remote filesystem using ftp.
  You can set a maximum space and the Backup class check it and delete some oldest file to store a new dump.

  @author Istvan Dobrentei
  @url http://dobrenteiistvan.hu
*/
class Backup extends FtpClient{

  const DATABASE_DUMPFILE_NAME = 'dump.sql';

  private $_archiveName;
  private $_dbHost;
  private $_dbNames;
  private $_dbUser;
  private $_dbPass;
  private $_ftpHost;
  private $_ftpUser;
  private $_ftpPass;
  private $_ftpRemoteDir;   //directory of remote files
  private $_ftpLocalDir;    //directory of local dump file default is the 'temp' of the current directory
  private $_maxSize;        //Mbyte
  private $_log;

  public function __construct($config){
    $this->_log = new Logger('logger');
    $this->_log->pushHandler(new StreamHandler('log/backup.log', Logger::ERROR));
    //set ftp size for backup, default size is 100 Mbyte
    $this->_maxSize = isset($config['max_backup_size']) ? (int)$config['max_backup_size'] * 1024 * 1024 : 104857600;
    /* Database settings */
    $this->_dbHost = isset($config['db_host']) ? $config['db_host'] : '';
    $this->_dbNames = isset($config['db_name']) ? $config['db_name'] : array();
    $this->_dbUser = isset($config['db_user']) ? $config['db_user'] : '';
    $this->_dbPass = isset($config['db_pass']) ? $config['db_pass'] : '';
    /* Ftp settings */
    $this->_ftpHost = isset($config['ftp_host']) ? $config['ftp_host'] : '';
    $this->_ftpUser = isset($config['ftp_user']) ? $config['ftp_user'] : '';
    $this->_ftpPass = isset($config['ftp_pass']) ? $config['ftp_pass'] : '';
    $this->_ftpRemoteDir = isset($config['ftp_remote_dir']) ? $config['ftp_remote_dir'] : '';
    $this->_ftpLocalDir = isset($config['ftp_local_dir']) ? $config['ftp_local_dir'] : 'temp';

    try{
      parent::__construct();
      $this->connect($this->_ftpHost);
      $this->login($this->_ftpUser, $this->_ftpPass);
      $this->_archiveName = 'db_' . date('Y-m-d_His') . '.zip';
      //clean temp directory
      array_map('unlink', glob($this->_ftpLocalDir . "/*.*"));
      //create remote directory if not exists
      if(!$this->isDir($this->_ftpRemoteDir)){
        $this->mkdir($this->_ftpRemoteDir);
      }
      $ftpSize = $this->dirSize($this->_ftpRemoteDir);
      //dump database to a file and gzip content
      $pckgFileSize = 0;
      if($this->_dbNames){
        foreach($this->_dbNames as $dbName){
          $dump = new Mysqldump('mysql:host=' . $this->_dbHost . ';dbname=' . $dbName, $this->_dbUser, $this->_dbPass);
          $dump->start($this->_ftpLocalDir . DIRECTORY_SEPARATOR . $dbName . '_' . self::DATABASE_DUMPFILE_NAME);
        }
      }
      //add dump(s) to the archive
      UnifiedArchive::archiveNodes($this->_ftpLocalDir, $this->_ftpLocalDir . DIRECTORY_SEPARATOR . $this->_archiveName);
      //remove dump file(s)
      if($this->_dbNames){
        foreach($this->_dbNames as $dbName){
          unlink($this->_ftpLocalDir . DIRECTORY_SEPARATOR . $dbName . '_' . self::DATABASE_DUMPFILE_NAME);
        }
      }
      $pckgFileSize = filesize(realpath(dirname(dirname(__FILE__))) .DIRECTORY_SEPARATOR . $this->_ftpLocalDir . DIRECTORY_SEPARATOR . $this->_archiveName);
      //check available space, delete oldest backup
      if(($ftpSize + $pckgFileSize) > $this->_maxSize){
          $this->makeEnoughSpace($pckgFileSize);
      }
      //upload file
      $this->putAll($this->_ftpLocalDir . DIRECTORY_SEPARATOR, $this->_ftpRemoteDir);
    }
    catch(Exception $e){
        $this->_log->addError($e->getMessage());
    }
  }

  /*
    Delete oldest file(s) to save the current file
    watch max size of backup directory
  */
  private function makeEnoughSpace($currentSize){
      try{
        $sumSize = 0;
        $list = array();
        $rawList = $this->rawList($this->_ftpRemoteDir, false);
        $result = $this->parseRawList($rawList);
        if($result){
          foreach($result as $item){
            $temp = explode(DIRECTORY_SEPARATOR, $item['name']);
            $dateTimeStr = rtrim(ltrim(end($temp), 'db_'), '.zip');
            $t = explode('_', $dateTimeStr);
            if(count($t)==2){
              $date = $t[0];
              $time = $t[1][0].$t[1][1].':'.$t[1][2].$t[1][3].':'.$t[1][4].$t[1][5];
              $dt = new DateTime($date . ' ' . $time);
              $i = array(
                'name' => end($temp),
                'time' => $dt,
                'size' => $item['size']
              );
              $sumSize += $item['size'];
              array_push($list, $i);
            }
          }
        }
        //sort list by datetime object first the oldest, last the youngest
        usort($list, array(self, 'dateSort'));
        $deletable = array();
        foreach($list as $item){
          $sumSize = $sumSize - $item['size'];
          array_push($deletable, $item['name']);
          if(($sumSize + $currentSize) <= $this->_maxSize){
            break;
          }
        }
        //delete remote files
        if($deletable){
          foreach($deletable as $item){
            $this->remove($this->_ftpRemoteDir . DIRECTORY_SEPARATOR . $item);
          }
        }
      }
      catch(Exception $e){
        $this->_log->addError($e->getMessage());
      }
  }

  private static function dateSort($a, $b)
  {
      return $a['time'] > $b['time'];
  }
}
?>
