<?php namespace Wing\Library;

use Wing\Library\Workers\BaseWorker;
use Wing\Library\Workers\BinlogWorker;

/**
 * 服务进程，处理整体的进程控制和指令控制支持
 *
 * @author yuyi
 * @created 2016/9/23 8:27
 * @email 297341015@qq.com
 */

class Worker
{
    const VERSION = "2.2.0";

    //父进程相关配置
    private $daemon         = false;
    private $workers        = 2;
    private static $pid     = null;
    private $normal_stop    = false;

    //子进程相关信息
    private $event_process_id   = 0;
    private $processes          = [];
    private $start_time         = null;
    private $exit_times         = 0; //子进程退出次数

    /**
     * 构造函数
     *
     * @param array $params 进程参数
     */
    public function __construct($params = [
        "daemon"  => false,
        "workers" => 4
    ])
    {
        $this->start_time = date("Y-m-d H:i:s");
        //默认的pid路径
        self::$pid = HOME."/wing.pid";
        foreach ($params as $key => $value) {
            $this->$key = $value;
        }

        set_error_handler([$this, "onError"]);
        register_shutdown_function(function () {
            $log   = $this->getProcessDisplay()."正常退出";
            $winfo = self::getWorkerProcessInfo();
            $process_id = $winfo["process_id"]??0;
            if (!$this->normal_stop) {
                $log = 'pid:'.$this->getProcessDisplay()."异常退出";
                if (get_current_processid() == $process_id) {
                    $log = 'pid:'.$this->getProcessDisplay()."父进程异常退出";
                }
                $log .= json_encode(error_get_last(), JSON_UNESCAPED_UNICODE);
            }

            if (WING_DEBUG) {
                wing_debug($log);
            }

            wing_log("error", $log);

            //如果父进程异常退出 kill掉所有子进程
            if (get_current_processid() == $process_id && !$this->normal_stop) {
                $log = "父进程异常退出，尝试kill所有子进程". $this->getProcessDisplay();

                if (WING_DEBUG) {
                    wing_debug($log);
                }

                $log .= json_encode(error_get_last(), JSON_UNESCAPED_UNICODE);
                wing_log("error", $log);

                $this->signalHandler(SIGINT);
            }
        });
    }

    /**
     * 获取进程运行时信息
     *
     * @return array
     */
    public static function getWorkerProcessInfo()
    {
        self::$pid = HOME."/wing.pid";
        if(!is_file(self::$pid)) return [];

        $data = file_get_contents(self::$pid);
        list($pid, $daemon, $workers) = json_decode($data, true);

        return [
            "process_id" => $pid,
            "daemon"     => $daemon,
            "workers"    => $workers
        ];
    }
    public static function clearAll(){
        @unlink(self::$pid);
    }

    /**
     * 获取进程的展示名称
     *
     * @return string
     */
    protected function getProcessDisplay()
    {
        $pid = get_current_processid();
        if ($pid == $this->event_process_id) {
            return $pid."事件收集进程";
        }

        return $pid;
    }

    /**
     * 错误回调函数
     */
    public function onError()
    {
        if ($e = error_get_last()) {
            $stack = date('[Y-m-d H:i:s]').'[error] type:'.$e['type'].', line:'.$e['line'].', file:'.$e['file'].', message:'.$e['message']."\n";
        }

        list($errno, $errstr, $errfile, $errline) = func_get_args();
            //throw new ErrorException($errstr, 0, $errno, $errfile, $errline);
        $debugInfo = debug_backtrace();
        $stack = "\n[\n";
        foreach($debugInfo as $key => $val){
            if(array_key_exists("function", $val)){
                $stack .= "function:" . $val["function"];
            }
            if(array_key_exists("file", $val)){
                $stack .= ",file:" . $val["file"];
            }
            if(array_key_exists("line", $val)){
                $stack .= ",line:" . $val["line"];
            }
            $stack .= "\n";
        }
        $stack .= "]";

        wing_log("error", $this->getProcessDisplay()."发生错误：", 'errno:'.$errno.', line:'.$errline.', file:'.$errfile.', message:'.$errstr .$stack);
        if (WING_DEBUG) {
            wing_debug(func_get_args());
        }
    }

    /**
     * signal handler 信号回调函数
     *
     * @param int $signal
     */
    public function signalHandler($signal)
    {
        if(IS_WINDOWS) return;

        $winfo     = self::getWorkerProcessInfo();;
        $server_id = $winfo["process_id"];

        switch ($signal) {
            //stop all
            case SIGINT:
                $this->normal_stop = true;
                $current_processid = get_current_processid();
                if ($server_id == $current_processid) {
                    #self::clearAll();

                    foreach ($this->processes as $id => $pid) {
                        posix_kill($pid, SIGINT);
                    }

                    $start = time();
                    $max   = 1;

                    while (1) {
                        $pid = pcntl_wait($status, WNOHANG);//WUNTRACED);
                        if ($pid > 0) {
                            if ($pid == $this->event_process_id) {
                                wing_debug($pid, "事件进程退出");
                                wing_log('run', $pid, "事件进程退出");
                            }

                            $id = array_search($pid, $this->processes);
                            unset($this->processes[$id]);
                        }

                        if (!$this->processes || count($this->processes) <= 0) {
                            break;
                        }

                        if ((time() - $start) >= $max) {
                            foreach ($this->processes as $id => $pid) {
                                posix_kill($pid, SIGINT);
                            }
                            $max++;
                        }

                        if ((time() - $start) >= 5) {
                            wing_debug("退出进程超时");
                            wing_log('run', $current_processid, "退出进程超时");
                            break;
                        }
                    }
                    wing_debug("父进程退出");
                    wing_log('run', $current_processid, "父进程退出");
                }else{
                    wing_debug($current_processid, "收到退出信号");
                    wing_log('run', $current_processid, "收到退出信号");
                }
                exit(0);
            //restart
            case SIGUSR1:
                $worker_info = Worker::getWorkerProcessInfo();
                $daemon      = $worker_info["daemon"];
                $workers     = $worker_info["workers"];

                Worker::stopAll();

                $worker = new Worker([
                    "daemon"  => (bool)$daemon,
                    "workers" => $workers
                ]);
                $worker->start();
                break;
            case SIGUSR2:
                //echo get_current_processid()," show status\r\n";

                if ($server_id == get_current_processid()) {
                    $str  = "\r\n".'wing-binlog, version: ' . self::VERSION .' auth: yuyi email: 297341015@qq.com'."\r\n";
                    $str .= "----------------------------------------------------------------------------------------------------------\r\n";
                    $str .= sprintf(
                        "%-12s%-14s%-21s%-36s%s\r\n",
                        "process_id",
                        "events_times",
                        "start_time",
                        "running_time_len",
                        "process_name"
                    );
                    $str .= "----------------------------------------------------------------------------------------------------------\r\n";
                    $str .= sprintf(
                        "%-12s%-14s%-21s%-36s%s\r\n",
                        $server_id,
                        $this->exit_times,
                        $this->start_time,
                        timelen_format(time() - strtotime($this->start_time)),
                        "wing php >> master process"
                    );

                    file_put_contents(HOME."/logs/status.log", $str);

                    foreach ($this->processes as $id => $pid) {
                        posix_kill($pid, SIGUSR2);
                    }
                } else {
                    //子进程
                    //file_put_contents(HOME."/logs/".get_current_processid()."_get_status", 1);
                    //sprintf("","进程id 事件次数 运行时间 进程名称");
                    $current_processid = get_current_processid();
                    $str = sprintf(
                        "%-12s%-14s%-21s%-36s%s\r\n",
                        $current_processid,
                        BaseWorker::$event_times,
                        $this->start_time,
                        timelen_format(time() - strtotime($this->start_time)),
                        BaseWorker::$process_title
                    );

                    file_put_contents(HOME."/logs/status.log", $str, FILE_APPEND);
                }
                break;
        }
    }

    /**
     * 停止所有的进程
     */
    public static function stopAll()
    {
        $winfo     = self::getWorkerProcessInfo();
        if($winfo){
            $server_id = $winfo["process_id"];
            if(IS_WINDOWS){
                $handle = @popen("taskkill /F /pid ".$server_id,"r");
                if ($handle) {
                    $read = fread($handle, 2096);
                    echo $read,PHP_EOL;
                    if(strpos($read, 'SUCCESS')!==false || strpos($read,'成功')!==false){
                        self::clearAll();
                    }
                    pclose($handle);
                }
            }else{
                posix_kill($server_id, SIGINT);
            }
        }else{
            echo 'worker is not running',PHP_EOL;
        }
    }

    /**
     * 展示所有的进程状态信息
     */
    public static function showStatus()
    {
        $winfo     = self::getWorkerProcessInfo();
        $server_id = $winfo["process_id"];
        posix_kill($server_id, SIGUSR2);
    }

    /**
     * 启动进程 入口函数
     * @throws \Exception
     */
    public function start()
    {
        global $argv;
        $action = isset($argv[1]) ? $argv[1] : '';

        pcntl_signal(SIGINT, [$this, 'signalHandler'], false);
        pcntl_signal(SIGUSR1, [$this, 'signalHandler'], false);
        pcntl_signal(SIGUSR2, [$this, 'signalHandler'], false);
        pcntl_signal(SIGPIPE, SIG_IGN, false);

        if ($this->daemon) {
            $this->normal_stop = true;
            enable_deamon();
        }

        $format = "%-12s%-21s%s\r\n";
        $str    = "\r\n".'wing-binlog, version: ' .self::VERSION.
            ' auth: yuyi email: 297341015@qq.com'."\r\n";
        $str   .= "-----------------------------------------------------------------------\r\n";
        $str   .=sprintf($format, "process_id", "start_time", "process_name");
        $str   .= "-----------------------------------------------------------------------\r\n";

        $str   .= sprintf(
            $format,
            get_current_processid(),
            $this->start_time,
            "wing php >> master process"
        );

        echo $str;
        unset($str, $format);

        file_put_contents(
            self::$pid,
            json_encode([
                get_current_processid(),
                $this->daemon,
                $this->workers
            ])
        );
        set_process_title("wing php >> master process");

        $action=='restart' && sleep(2); //延迟

        $worker = new BinlogWorker($this->daemon, $this->workers);
        $this->event_process_id = $worker->start();
        unset($worker);
        $this->processes[] = $this->event_process_id;

        echo sprintf(
            "%-12s%-21s%s\r\n",
            $this->event_process_id,
            $this->start_time,
            "wing php >> events collector process"
        );

        while (1) {
            pcntl_signal_dispatch();

            try {
                ob_start();
                $pid = pcntl_wait($status, WNOHANG);

                if ($pid > 0) {
                    wing_debug($pid, "进程退出");
                    wing_log('run', $pid, "进程退出");
                    $this->exit_times++;
                    do {
                        $id = array_search($pid, $this->processes);
                        unset($this->processes[$id]);

                        if ($pid == $this->event_process_id) { //重新运行子进程
                            $worker = new BinlogWorker($this->daemon, $this->workers);
                            $this->event_process_id = $worker->start();
                            unset($worker);
                            $this->processes[] = $this->event_process_id;
                            break;
                        }
                    } while (0);
                }
                $content = ob_get_contents();
                ob_end_clean();

                if ($content) {
                    wing_log('exception', 'worker-start:'.$content);
                    wing_debug($content);
                }
            } catch (\Exception $e) {
                wing_log('exception', $e->getFile().':'.$e->getLine(), $e->getMessage(), $e->getTraceAsString());
            }
            sleep(1);
        }
        wing_log('exception', 'master服务异常退出');
        wing_debug("master服务异常退出");
    }
}
