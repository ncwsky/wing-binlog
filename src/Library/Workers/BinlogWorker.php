<?php
namespace Wing\Library\Workers;

use Wing\Bin\Net;
use Wing\Bin\NetException;
use \Wing\Library\Binlog;
use Wing\Library\ISubscribe;
use \Wing\Library\PDO;
use Wing\Library\Worker;

/**
 * BinlogWorker.php
 * User: huangxiaoan
 * Created: 2017/8/4 12:26
 * Email: huangxiaoan@xunlei.com
 */
class BinlogWorker
{
    public static $event_times   = 0;
    public static $process_title = '';

    private $notify = [];
    private $daemon;

    /**
     * @var Binlog
     */
    private $binlog;
    private $is_notice = false;

    public function __construct($daemon)
    {
        $config = load_config(WING_CONFIG);

        $this->daemon = $daemon;
        $this->binlog = new Binlog(new PDO);

        if (isset($config["subscribe"]) && is_array($config["subscribe"])) {
            foreach ($config["subscribe"] as $class => $params) {
                $this->notify[] = new $class($params);
                $this->is_notice = true;
            }
        }
    }

    /**
     * 通知订阅者
     * @param array $result
     */
    protected function notice($result)
    {
        if(false===$this->is_notice) return;
        if($result['event']=='xid') return; //xid事件

        $data = $result["data"];
        if(!is_array($data)) { //query事件
            //if($data=='BEGIN') return;
            $data = [$data];
        }
        foreach ($data as $row) {
            $result["data"] = $row;
            foreach ($this->notify as $notify) {
                /**
                 * @var ISubscribe $notify
                 */
                $notify->onchange($result);
            }
        }

    }

    public function start()
    {
        $daemon = $this->daemon;

        if (!IS_WINDOWS) {
            $process_id = pcntl_fork();

            if ($process_id < 0) {
                wing_echo('创建子进程失败');
                wing_log('run', $process_id, '创建子进程失败');
                exit;
            }
            if ($process_id > 0) {
                return $process_id;
            }

            if ($daemon) {
                reset_std();
            }
        }

        $process_name        = "wing php >> events collector process";
        self::$process_title = $process_name;

        //设置进程标题 mac 会有warning 直接忽略
        set_process_title($process_name);

        while (1) {
            try {
                pcntl_signal_dispatch();

                //通知订阅者
                if($result = $this->binlog->getBinlogEvents()){
                    $this->notice($result);
                }
            } catch (NetException $e) {
                Net::close();
                sleep(load_config(WING_CONFIG)['retry_connect_sleep']??6);

                if($e->getCode()!=4 && $e->getMessage()!='Interrupted system call'){ // 0 Success 连接断开
                    wing_echo('retry binlog connect:'.$e->getMessage());
                    //wing_log('retry', 'retry binlog connect', $e->getFile().':'.$e->getLine(), $e->getMessage(), $e->getTraceAsString());
                }
                $this->binlog->connect(load_config(WING_CONFIG));
            } catch (\Exception $e) {
                Net::close();
                wing_echo($e->getMessage());
                wing_log('error', 'binlog fail',$e->getFile().':'.$e->getLine(), $e->getMessage(), $e->getTraceAsString());
                //binlog定位无效
                if(strpos($e->getMessage(), 'Could not find first log file')!==false){
                    //todo notice通知
                    Worker::stopAll();
                    //throw new \Exception($e->getMessage());
                }
            }
        }
    }
}