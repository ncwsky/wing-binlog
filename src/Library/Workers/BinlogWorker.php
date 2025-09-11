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

    public const ERROR_TYPE = [
        E_ERROR => 'E_ERROR',
        E_WARNING => 'E_WARNING',
        E_PARSE => 'E_PARSE',
        E_NOTICE => 'E_NOTICE',
        E_CORE_ERROR => 'E_CORE_ERROR',
        E_CORE_WARNING => 'E_CORE_WARNING',
        E_COMPILE_ERROR => 'E_COMPILE_ERROR',
        E_COMPILE_WARNING => 'E_COMPILE_WARNING',
        E_USER_ERROR => 'E_USER_ERROR',
        E_USER_WARNING => 'E_USER_WARNING',
        E_USER_NOTICE => 'E_USER_NOTICE',
        E_RECOVERABLE_ERROR => 'E_RECOVERABLE_ERROR',
        E_DEPRECATED => 'E_DEPRECATED',
        E_USER_DEPRECATED => 'E_USER_DEPRECATED'
    ];

    /**
     * @var Binlog
     */
    private $binlog;

    public function __construct($daemon)
    {
        $this->daemon = $daemon;
        $this->binlog = new Binlog(new PDO);

        $subscribe = (array)load_config(WING_CONFIG, 'subscribe', []);
        if ($subscribe) {
            foreach ($subscribe as $class => $params) {
                $this->notify[] = new $class($params);
            }
        }
    }

    /**
     * 通知订阅者
     * @param array $result
     */
    protected function notice($result)
    {
        if(!$this->notify) return;
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
        if (!IS_WINDOWS) {
            $process_id = pcntl_fork();

            if ($process_id < 0) {
                wing_echo('创建子进程失败');
                wing_log('run', $process_id, '创建子进程失败');
                exit;
            }
            if ($process_id > 0) { //父进程返回子进程的进程id
                return $process_id;
            }
            //子进程
            if ($this->daemon) {
                reset_std();
            }
        }
        //设置进程标题
        self::$process_title = 'wing_events_' . load_config(WING_CONFIG, 'slave_server_id');
        set_process_title(self::$process_title);

        register_shutdown_function(function (){
            $errorMsg = DIRECTORY_SEPARATOR === '/' ? 'Worker[' . posix_getpid() . '] process terminated' : 'Worker process terminated';
            $errors = error_get_last();
            if ($errors && ($errors['type'] === E_ERROR ||
                    $errors['type'] === E_PARSE ||
                    $errors['type'] === E_CORE_ERROR ||
                    $errors['type'] === E_COMPILE_ERROR ||
                    $errors['type'] === E_RECOVERABLE_ERROR)
            ) {
                $errorMsg .= ' with ERROR: ' . (static::ERROR_TYPE[$errors['type']] ?? $errors['type']) . " \"{$errors['message']} in {$errors['file']} on line {$errors['line']}\"";
            }
            wing_log('error', $errorMsg);
        });
        while (1) {
            try {
                pcntl_signal_dispatch(); //信号处理

                //通知订阅者
                if($result = $this->binlog->getBinlogEvents()){
                    self::$event_times++;
                    $this->notice($result);
                }
            } catch (NetException $e) {
                Net::close();
                sleep((int)load_config(WING_CONFIG, 'retry_connect_sleep', 6));

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