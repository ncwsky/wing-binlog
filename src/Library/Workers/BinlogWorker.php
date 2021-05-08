<?php
namespace Wing\Library\Workers;

use Wing\Bin\NetCloseException;
use \Wing\Library\Binlog;
use Wing\Library\ISubscribe;
use \Wing\Library\PDO;

/**
 * BinlogWorker.php
 * User: huangxiaoan
 * Created: 2017/8/4 12:26
 * Email: huangxiaoan@xunlei.com
 */
class BinlogWorker extends BaseWorker
{

    private $notify = [];
    private $daemon;

    /**
     * @var \Wing\Library\Binlog
     */
    private $binlog;

    public function __construct($daemon, $workers)
    {
        $config = load_config("app");

        $this->binlog = new Binlog(new PDO);

        if (isset($config["subscribe"]) && is_array($config["subscribe"])) {
            foreach ($config["subscribe"] as $class => $params) {
                $params["daemon"]  = $daemon;
                $params["workers"] = $workers;
                $this->notify[] = new $class($params);
            }
        }
    }

    /**
     * @param array $result
     */
    protected function notice($result)
    {
        //通知订阅者
        if (is_array($this->notify) && count($this->notify) > 0) {
            $data = $result["data"];
            if($result['event']=='xid') return; //xid事件
            if(!is_array($data)) { //query事件
                if($data=='BEGIN') return;
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
    }

    public function start()
    {
        $daemon = $this->daemon;

        if (!IS_WINDOWS) {
            $process_id = pcntl_fork();

            if ($process_id < 0) {
                wing_debug("创建子进程失败");
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

        $times = 0;
        while (1) {
            ob_start();

            try {
                pcntl_signal_dispatch();
                do {
                    $result = $this->binlog->getBinlogEvents();

                    if (!$result) {
                        break;
                    }

                    $times += (is_array($result["data"]) ? count($result["data"]) : 1);

                    wing_debug($times. '次');

                    //通知订阅者
                    $this->notice($result);
                } while (0);
            } catch (NetCloseException $e) {
                usleep(500000);
                $this->binlog->connect(load_config("app"));
                wing_log('retry', $e->getLine(), $e->getFile(), $e->getMessage(), $e->getTraceAsString());
            } catch (\Exception $e) {
                wing_debug($e->getMessage());
                wing_log('exception', $e->getLine(), $e->getFile(), $e->getMessage(), $e->getTraceAsString());
                unset($e);
            }

            $output = ob_get_contents();
            ob_end_clean();

            if ($output && WING_DEBUG) {
                wing_debug($output);
            }
            unset($output);
            usleep(self::USLEEP);
        }

        return 0;
    }
}