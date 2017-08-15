<?php namespace Wing\Library;
use Wing\FileSystem\WDir;
use Wing\Net\WebSocket;

/**
 * Created by PhpStorm.
 * User: yuyi
 * Date: 17/8/5
 * Time: 07:45
 */
class WebSocketWorker extends BaseWorker
{
    private $clients   = [];
    //private $processes = [];

    public function __construct()
    {
        $dir = HOME."/cache/websocket";
        $obj_dir = new WDir($dir);
        $obj_dir->mkdir();
        unset($obj_dir, $dir);
    }

    public function start($daemon = false)
    {
        $process_id = pcntl_fork();

        if ($process_id < 0) {
            echo "fork a process fail\r\n";
            exit;
        }

        if ($process_id > 0) {
            return $process_id;
        }

        if ($daemon) {
            reset_std();
        }
        set_process_title("wing php >> websocket service process");

        $config = load_config("app");
        $ip     = isset($config["websocket"]["host"])?$config["websocket"]["host"]:"0.0.0.0";
        $port   = isset($config["websocket"]["port"])?$config["websocket"]["port"]:9998;


        $tcp = new \Wing\Net\WebSocket($ip, $port);

        $tcp->on(WebSocket::WEBSOCKET_ON_CONNECT, function($client) use($tcp) {
            $this->clients[] = $client;
        });

        $tcp->on(WebSocket::ON_TICK,function(){
            ob_start();
            $path[] = HOME . "/cache/websocket/*";
            while (count($path) != 0) {
                $v = array_shift($path);
                foreach (glob($v) as $item) {
                    if (is_file($item)) {
                        $content = file_get_contents($item);
                        foreach ($this->clients as $c) {
                            $c->send($content);
                        }
                        unlink($item);
                    }
                }
            }
            $debug = ob_get_contents();
            ob_end_clean();

            if ($debug) {
                echo $debug;
            }

            unset($debug, $recv_msg);
        });

        $tcp->on(WebSocket::WEBSOCKET_ON_MESSAGE, function($client, $recv_msg) use($tcp){

        });

        $tcp->on(WebSocket::WEBSOCKET_ON_CLOSE, function($client) use($tcp){
            $key = array_search($client, $this->clients);
            unset($this->clients[$key]);
        });

        $tcp->start();
        return 0;
    }
}