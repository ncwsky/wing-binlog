<?php
namespace Wing\Library;

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
    const VERSION = "3.1.0";

    //父进程相关配置
    private $daemon = false;
    private $normal_stop = false;

    //子进程相关信息
    private $event_process_id = 0;
    private $processes = [];
    private $start_time = '';
    private $exit_times = 0; //子进程退出次数

    /**
     * 构造函数
     * @param bool $daemon
     */
    public function __construct(bool $daemon = false)
    {
        $this->start_time = date("Y-m-d H:i:s");
        $this->daemon = $daemon;

        register_shutdown_function(function () {
            $log = $this->getProcessDisplay() . " 正常退出";
            $process_id = self::getWorkerProcessInfo()["process_id"] ?? 0;
            $curr_process_id = get_current_processid();
            if (!$this->normal_stop) {
                $log = $this->getProcessDisplay() . " 异常退出";
                if ($curr_process_id == $process_id) {
                    $log = $this->getProcessDisplay() . " 父进程异常退出";
                }
                if ($e = error_get_last()) {
                    $log .= ' ' . json_encode($e);
                }
            }

            wing_debug($log);
            wing_log("run", $log);

            if ($curr_process_id == $process_id && !$this->normal_stop) {
                $log = "父进程异常退出，尝试kill所有子进程" . $this->getProcessDisplay();

                wing_debug($log);
                if ($e = error_get_last()) {
                    $log .= ' ' . json_encode($e);
                }
                wing_log("run", $log);

                $this->signalHandler(SIGINT); //直接触发结束服务信号
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
        if (!is_file(self::pidFile())) return [];

        //[$pid, $daemon]
        $data = explode(' ', file_get_contents(self::pidFile()));
        return [
            "process_id" => (int)$data[0],
            "daemon" => (bool)$data[1]
        ];
    }

    public static function clearAll()
    {
        @unlink(self::pidFile());
    }

    public static function pidFile(){
        return HOME . '/wing.pid';
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
            return $pid . '事件收集进程';
        }

        return $pid;
    }

    /**
     * signal handler 信号回调函数
     *
     * @param int $signal
     * @throws \Exception
     */
    public function signalHandler($signal)
    {
        if (IS_WINDOWS) return;

        $server_id = self::getWorkerProcessInfo()["process_id"] ?? 0;
        switch ($signal) {
            case SIGINT: //stop
                $this->normal_stop = true;
                $curr_process_id = get_current_processid();
                if ($server_id == $curr_process_id) {
                    #self::clearAll();

                    foreach ($this->processes as $id => $pid) {
                        posix_kill($pid, SIGINT);
                    }

                    $start = time();
                    $max = 1;

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
                            wing_log('run', $curr_process_id, "退出进程超时");
                            break;
                        }
                    }
                    wing_debug($curr_process_id, "父进程退出");
                    wing_log('run', $curr_process_id, "父进程退出");
                } else {
                    wing_debug($curr_process_id, "收到退出信号");
                    wing_log('run', $curr_process_id, "收到退出信号");
                }
                exit(0);
            //restart
            case SIGUSR1:
                $daemon = self::getWorkerProcessInfo()["daemon"] ?? false;

                self::stopAll();

                $worker = new Worker((bool)$daemon);
                $worker->start();
                break;
            case SIGUSR2: //生成status信息
                //echo get_current_processid()," show status\r\n";

                if ($server_id == get_current_processid()) {
                    $str = "\r\n" . 'wing-binlog, version: ' . self::VERSION . "\r\n";
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
                        'wing_master'
                    );

                    file_put_contents(LOG_DIR . "/status.log", $str);

                    foreach ($this->processes as $pid) {
                        posix_kill($pid, SIGUSR2);
                    }
                } else {
                    //子进程
                    //file_put_contents(LOG_DIR."/".get_current_processid()."_get_status", 1);
                    //sprintf("","进程id 事件次数 运行时间 进程名称");
                    $curr_process_id = get_current_processid();
                    $str = sprintf(
                        "%-12s%-14s%-21s%-36s%s\r\n",
                        $curr_process_id,
                        BinlogWorker::$event_times,
                        $this->start_time,
                        timelen_format(time() - strtotime($this->start_time)),
                        BinlogWorker::$process_title
                    );

                    file_put_contents(LOG_DIR . "/status.log", $str, FILE_APPEND);
                }
                break;
        }
    }

    /**
     * 停止所有的进程
     */
    public static function stopAll()
    {
        $server_id = self::getWorkerProcessInfo()['process_id'] ?? 0;
        if ($server_id) {
            if (IS_WINDOWS) {
                $handle = @popen("taskkill /F /pid " . $server_id, "r");
                if ($handle) {
                    $read = fread($handle, 2096);
                    echo $read, PHP_EOL;
                    if (strpos($read, 'SUCCESS') !== false || strpos($read, '成功') !== false) {
                        self::clearAll();
                    }
                    pclose($handle);
                }
            } else {
                posix_kill($server_id, SIGINT);
            }
        } else {
            echo 'worker is not running', PHP_EOL;
        }
    }

    /**
     * 展示所有的进程状态信息
     */
    public static function showStatus()
    {
        $process_id = self::getWorkerProcessInfo()["process_id"] ?? 0;
        if ($process_id) {
            posix_kill($process_id, SIGUSR2);
        } else {
            echo 'no pid info', PHP_EOL;
        }
    }

    /**
     * 启动进程 入口函数
     * @throws \Exception
     */
    public function start()
    {
        global $argv;
        $action = $argv[1] ?? '';

        pcntl_signal(SIGINT, [$this, 'signalHandler'], false); //结束服务
        pcntl_signal(SIGUSR1, [$this, 'signalHandler'], false); //重启服务
        pcntl_signal(SIGUSR2, [$this, 'signalHandler'], false); //status信息
        pcntl_signal(SIGPIPE, SIG_IGN, false); //忽略Broken pipe

        if ($this->daemon) {
            $this->normal_stop = true;
            self::daemonize();
        }
        $curr_process_id = get_current_processid();

        $format = "%-12s%-21s%s\r\n";
        $str = "\r\n" . 'wing-binlog, version: ' . self::VERSION  .", cfg:".WING_CONFIG. "\r\n";
        $str .= "-----------------------------------------------------------------------\r\n";
        $str .= sprintf($format, "process_id", "start_time", "process_name");
        $str .= "-----------------------------------------------------------------------\r\n";

        $str .= sprintf(
            $format,
            $curr_process_id,
            $this->start_time,
            'wing_master'
        );

        //记录进程信息
        file_put_contents(self::pidFile(), sprintf("%s %d", $curr_process_id, $this->daemon));
        set_process_title('wing_master_' . load_config(WING_CONFIG, 'slave_server_id'));

        $action == 'restart' && sleep(2); //延迟

        $worker = new BinlogWorker($this->daemon);
        $this->event_process_id = $worker->start();
        wing_log('run', $this->event_process_id, '生成子进程');
        unset($worker);
        $this->processes[] = $this->event_process_id;

        $str .= sprintf(
            "%-12s%-21s%s\r\n",
            $this->event_process_id,
            $this->start_time,
            'wing_events_' . load_config(WING_CONFIG, 'slave_server_id')
        );
        echo $str;

        while (1) {
            pcntl_signal_dispatch(); //信号处理

            try {
                ob_start();
                $status = 0;
                $pid = pcntl_wait($status, WNOHANG);

                if ($pid > 0) {
                    wing_debug($pid, "进程退出");
                    wing_log('run', $pid, "进程退出");
                    $this->exit_times++;
                    do {
                        $id = array_search($pid, $this->processes);
                        unset($this->processes[$id]);

                        if ($pid == $this->event_process_id) {
                            if (BinlogWorker::$event_times) {
                                wing_log('run', $this->event_process_id, '子进程结束时事件次数', BinlogWorker::$event_times);
                            }
                            BinlogWorker::$event_times = 0;
                            $worker = new BinlogWorker($this->daemon);
                            $this->event_process_id = $worker->start();
                            wing_log('run', $this->event_process_id, '生成新子进程');
                            unset($worker);
                            $this->processes[] = $this->event_process_id;
                            break;
                        }
                    } while (0);
                }
                $content = ob_get_contents();
                ob_end_clean();

                if ($content) {
                    wing_log('run', 'worker-start:' . $content);
                    wing_debug($content);
                }
            } catch (\Exception $e) {
                wing_log('error', $e->getFile() . ':' . $e->getLine(), $e->getMessage(), $e->getTraceAsString());
            }
            sleep(1);
        }
    }

    // 守护进程初始化函数
    public static function daemonize()
    {
        if (DIRECTORY_SEPARATOR !== '/') {
            return;
        }
        //修改掩码
        umask(0);
        //创建进程
        $pid = pcntl_fork();
        if (-1 === $pid) {
            throw new RuntimeException('Fork fail');
        } elseif ($pid > 0) {
            //父进程直接退出
            exit(0);
        }
        //创建进程会话 使当前进程成为会话的主进程
        if (-1 === posix_setsid()) {
            throw new RuntimeException("Setsid fail");
        }
        /*
        // Fork again avoid SVR4 system regain the control of terminal.
        $pid = pcntl_fork();
        if (-1 === $pid) {
            throw new RuntimeException("Fork fail");
        } elseif (0 !== $pid) {
            exit(0);
        }*/
    }
}