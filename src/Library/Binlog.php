<?php namespace Wing\Library;

use Wing\Bin\Auth;
use Wing\Bin\BinlogPacket;
use Wing\Bin\Net;
use Wing\Bin\Packet;
use Wing\Cache\File;

/**
 * Created by PhpStorm.
 * User: yuyi
 * Date: 17/2/10
 * Time: 10:23
 * @property ICache $cache_handler
 */
class Binlog
{
    const HEARTBEAT = 30;
    /**
    * @var PDO|IDb
    */
    public static $db;
    /**
     * @var BinlogPacket
     */
    public $binlogPacket;

    /**
    * mysqlbinlog 命令路径
    * @var string $mysql_binlog
    */
    private $mysql_binlog  = "mysqlbinlog";

    /**
    * @var string $cache_handler
    */
    private $cache_handler;

    /**
    * @var string $current_binlog_file
    */
    private $current_binlog_file = null;

    /**
    * @var string $binlog_file
    */
    private $binlog_file;

    /**
    * @var int $last_pos
    */
    private $last_pos;

    /**
    * @var bool $checksum
    */
    public $checksum;

    /**
     * 写日志点
     * @var bool
     */
    public static $forceWriteLogPos = false;

    /**
     * 构造函数
     *
     * @param IDb $db
     */
    public function __construct(IDb $db)
    {
        $config = load_config("app");
        self::$db  = $db;
        $this->binlogPacket = new BinlogPacket($config['do_db']??'', $config['ig_db']??'');
        $this->mysql_binlog = "mysqlbinlog";
        if (isset($config["mysqlbinlog"])) {
            $this->mysql_binlog = $config["mysqlbinlog"];
        }
        if (!$this->isOpen()) {
            wing_debug("请开启mysql binlog日志");
            exit;
        }
        if ($this->getFormat() != "row") {
            wing_debug("仅支持row格式");
            exit;
        }
        $this->cache_handler = new File(HOME."/cache");
        //初始化，最后操作的binlog文件
        $this->binlog_file = $this->getLastBinLog();
        list(, $this->last_pos) = $this->getLastPosition();
        if (!$this->binlog_file || !$this->last_pos) {
            //当前使用的binlog 文件
            $info = $this->getCurrentLogInfo();

            self::$forceWriteLogPos = true;
            //缓存初始复制点
            $this->setLastBinLog($info["File"]);
            $this->setLastPosition(0, $info["Position"]);
        }
        $start_msg = sprintf("%-12s%-21s%s\r\n", $this->binlog_file, $this->last_pos, "Starting position");
        echo $start_msg;
        wing_log('rotate', $start_msg);
        $this->connect($config);
    }

    //连接mysql、认证连接，初始化Net::$socket
    public function connect($config)
    {
        try {
            //认证
            Auth::execute(
                $config["mysql"]["host"],
                $config["mysql"]["user"],
                $config["mysql"]["password"],
                $config["mysql"]["db_name"],
                $config["mysql"]["port"],
                $config["mysql"]["rec_time_out"]??self::HEARTBEAT
            );

            //注册为slave
            $this->registerSlave($config["slave_server_id"]);
        } catch (\Exception $e) {
            wing_debug($e->getMessage());
            wing_log('error', 'registerSlave fail', $e->getFile().':'.$e->getLine(), $e->getMessage());
        }
    }

    /**
     * @return array|null
     * @throws \Wing\Bin\NetException
     */
    public function getBinlogEvents()
    {
        \set_error_handler(function($code, $msg, $file, $line){
            wing_log('error', "{$file}:{$line}\t{$msg}");
        });
        \set_exception_handler(function($e){
            wing_log('error', $e->getMessage()."\n".'line:'.$e->getLine().', file:'.$e->getFile()."\n".$e->getTraceAsString());
        });
        $pack = Packet::readPacket(true); // 校验数据包格式 Packet::success($pack);
        $res = $this->binlogPacket->parse($pack, $this->checksum);
        \restore_error_handler();
        \restore_exception_handler();

        list($result, $binlog_file, $last_pos) = $res;

        if ($binlog_file) {
            $this->setLastBinLog($binlog_file);
        }

        if ($last_pos) {
            $this->setLastPosition(0, $last_pos);
        }

        return $result;
    }

    /**
     * 注册成为从库（binlog协议支持）
     * @param int $slave_server_id
     * @return bool
     * @throws \Wing\Bin\NetException
     */
    public function registerSlave($slave_server_id)
    {
        $this->checksum = $this->isCheckSum();
        // checksum
        if ($this->checksum) {
            Net::send(Packet::query("set @master_binlog_checksum=@@global.binlog_checksum"));
        }
        //心跳   master_heartbeat_period is nanoseconds  heartbeat_period >= 0.001 &&  heartbeat_period <= 4294967
        Net::send(Packet::query("set @master_heartbeat_period=".(self::HEARTBEAT*1000000000)));

        //注册
        $data = Packet::registerSlave($slave_server_id);

        if (!Net::send($data)) {
            return false;
        }

        Packet::readPacket(true); #Packet::success(Packet::readPacket());

        //封包
        $data = Packet::binlogDump($this->binlog_file, $this->last_pos, $slave_server_id);

        if (!Net::send($data)) {
            return false;
        }

        //认证
        Packet::readPacket(true); #Packet::success(Packet::readPacket());

        return true;
    }


    public function isCheckSum()
    {
        $res = self::$db->row("SHOW GLOBAL VARIABLES LIKE 'BINLOG_CHECKSUM'");
        return isset($res['Value']) && $res['Value'] !== 'NONE';
    }

    /**
    * 获取所有的logs
    *
    * @return array
    */
    public function getLogs()
    {
        $sql  = 'show binary logs';

        return self::$db->query($sql);
    }

    public function getFormat()
    {
        $sql  = 'select @@binlog_format';

        $data = self::$db->row($sql);
        return strtolower($data["@@binlog_format"]);
    }

    /**
     * 获取当前正在使用的binglog日志文件信息
     *
     * @return array 一维
     *    array(5) {
     *          ["File"] => string(16) "mysql-bin.000005"
     *          ["Position"] => int(8840)
     *          ["Binlog_Do_DB"] => string(0) ""
     *          ["Binlog_Ignore_DB"] => string(0) ""
     *          ["Executed_Gtid_Set"] => string(0) ""
     *    }
     */
    public function getCurrentLogInfo()
    {
        $sql  = 'show master status';

        $data = self::$db->row($sql);
        return $data;
    }

    /**
    * 获取所有的binlog文件
    *
    * @return array
    */
    public function getFiles()
    {
        $logs  = $this->getLogs();
        $sql   = 'select @@log_bin_basename';

        $data  = self::$db->row($sql);
        $path  = pathinfo($data["@@log_bin_basename"], PATHINFO_DIRNAME);
        $files = [];

        foreach ($logs as $line) {
            $files[] = $path.DIRECTORY_SEPARATOR.$line["Log_name"];
        }

        return $files;
    }

    /**
    * 获取当前正在使用的binlog文件路径
    *
    * @return string
    */
    private $start_getCurrentLogFile = null;
    public function getCurrentLogFile()
    {
        if ($this->start_getCurrentLogFile == null) {
            $this->start_getCurrentLogFile = time();
        }

        if ($this->current_binlog_file != null) {
            if ((time() - $this->start_getCurrentLogFile) < 5) {
                return $this->current_binlog_file;
            } else {
                $this->start_getCurrentLogFile = time();
            }
        }

        $sql  = 'select @@log_bin_basename';

        $data = self::$db->row($sql);

        if (!isset($data["@@log_bin_basename"])) {
            return null;
        }

        $file = str_replace("\\", "/", $data["@@log_bin_basename"]);
        $temp = explode("/", $file);

        array_pop($temp);

        $path = implode("/", $temp);
        $info = $this->getCurrentLogInfo();

        if (!isset($info["File"])) {
            return null;
        }

        $path = $path ."/". $info["File"];
        $this->current_binlog_file = $path;
        return $path;
    }

    /**
    * 检测是否已开启binlog功能
    *
    * @return bool
    */
    public function isOpen()
    {
        $sql  = 'select @@sql_log_bin';

        $data = self::$db->row($sql);
        return isset($data["@@sql_log_bin"]) && $data["@@sql_log_bin"] == 1;
    }


    /**
     * 设置存储最后操作的binlog名称--游标，请勿删除mysql.last
     * @param $binlog
     * @return mixed
     */
    public function setLastBinLog($binlog)
    {
        $this->binlog_file = $binlog;
        return $this->cache_handler->set("mysql.last", $binlog);
    }
    /**
     * 设置最后的读取位置--游标，请勿删除mysql.pos
     * @param $start_pos
     * @param $end_pos
     * @return bool
     */
    public function setLastPosition($start_pos, $end_pos)
    {
        static $lastWriteTime = 0; //上次写入时间
        $this->last_pos = $end_pos;
        $force = false;
        if (self::$forceWriteLogPos) {
            $force = self::$forceWriteLogPos;
            self::$forceWriteLogPos = false;
            $lastWriteTime = time();
        }

        if (false === $force) { //每隔10秒写一次
            $time = time();
            if (($lastWriteTime + 10) < $time) {
                $lastWriteTime = $time;
                $force = true;
            }
        }

        if ($force) {
            wing_debug('----------------------------------------- wirte pos ' . $end_pos.'------------------------');
            $this->cache_handler->set("mysql.pos", [$start_pos, $end_pos]);
        }

        return $force;
    }

    /**
     * 获取最后操作的binlog文件名称
     * @return mixed
     */
    public function getLastBinLog()
    {
        return $this->cache_handler->get("mysql.last");
    }
    /**
     * 获取最后的读取位置
     * @return mixed
     */
    public function getLastPosition()
    {
        return $this->cache_handler->get("mysql.pos");
    }

    /**
     * 获取binlog事件，请只在意第一第二个参数
     * @param string $current_binlog
     * @param int $last_end_pos
     * @param int $limit
     * @return array
     */
    public function getEvents($current_binlog, $last_end_pos, $limit = 10000)
    {
        if (!$last_end_pos) {
            $last_end_pos = 0;
        }

        $sql   = 'show binlog events in "' . $current_binlog . '" from ' . $last_end_pos.' limit '.$limit;
        $datas = self::$db->query($sql);

        return $datas;
    }

    /**
    * 获取session元数据--直接存储于cache_file
    *
     * @param int $start_pos
     * @param int $end_pos
    * @return string 缓存文件路径
    */
    public function getSessions($start_pos, $end_pos)
    {
        //当前使用的binlog文件路径
        $current_binlog_file = $this->getCurrentLogFile();
        if (!$current_binlog_file) {
            //$error = "get current binlog path error => ".$current_binlog_file;
            //if (WING_DEBUG)
            //echo $error,"\r\n";
          // Context::instance()->logger->error($error);
            return null;
        }

//        $str1 = md5(rand(0,999999));
//        $str2 = md5(rand(0,999999));
//        $str3 = md5(rand(0,999999));
//        $dir = HOME."/cache/binfile";
//            (new WDir($dir))->mkdir();

//            $file_name = time().
//               substr($str1,rand(0,strlen($str1)-16),8).
//               substr($str2,rand(0,strlen($str2)-16),8).
//               substr($str3,rand(0,strlen($str3)-16),8);

      // $cache_file  = $dir."/".$file_name;

        //unset($str1,$str2,$str3);

        //mysqlbinlog -uroot -proot -h127.0.0.1 -P3306
        // --read-from-remote-server mysql-bin.000001
        // --base64-output=decode-rows -v > 1
        /*$command    = $this->mysqlbinlog .
            " -u".$this->user.
            " -p\"".$this->password."\"".
            " -h".$this->host.
            " -P".$this->port.
            //" --read-from-remote-server".
            " -R --base64-output=DECODE-ROWS -v". //-vv
            " --start-position=" . $start_pos .
            " --stop-position=" . $end_pos .
            "  \"" . $current_binlog_file . "\" > ".$cache_file;
      */
      // //echo preg_replace("/\-p[\s\S]{1,}?\s/","-p****** ",$command,1),"\r\n";
        $command    =
            $this->mysql_binlog .
            " --base64-output=DECODE-ROWS -v".
            " --start-position=" . $start_pos .
            " --stop-position=" . $end_pos . "  \"" . $current_binlog_file . "\"";//.$cache_file ;
//
//        if (WING_DEBUG) {
//            //echo $command,"\r\n\r\n";
//        }

        unset($current_binlog_file);

        exec($command, $out);

        unset($command);
        return implode("\n", $out);
    }
}
