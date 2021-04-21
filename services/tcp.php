#!/usr/bin/env php
<?php
//declare(ticks = 1);
require __DIR__.'/../vendor/autoload.php';

define("HOME", dirname(__DIR__));

date_default_timezone_set("PRC");
use Workerman\Worker;
$send_count  = 0;
$send_count2 = 0;

$host = "0.0.0.0";
$port = 9997;
$workers = 1;

foreach ($argv as $item) {
    if (strpos($item,"--host") === 0)
        list(,$host) = explode("=",$item);
    if (strpos($item,"--port") === 0)
        list(,$port) = explode("=",$item);
    if (strpos($item,"--workers") === 0)
        list(,$workers) = explode("=",$item);
}

$workers = 1;//intval($workers);
if ($workers <= 0)
    $workers = 1;

// Create a Websocket server
$ws_worker = new Worker("tcp://".$host.":".$port);

// 4 processes
$ws_worker->count = $workers;

// Emitted when new connection come
$ws_worker->onConnect = function($connection)
{
    $connection->maxSendBufferSize = 104857600;
    echo "New connection\n";
};

$ws_worker->onWorkerStart = function($ws_worker)
{
    echo "onWorkerStart";
    // 只在id编号为0的进程上设置定时器，其它1、2、3号进程不设置定时器
   // if($worker->id === 0)
    {
//        \Workerman\Lib\Timer::add(0.001, function() use($ws_worker){
//            ob_start();
//            $path[] = HOME . "/cache/tcp/".$ws_worker->id."/*";
//            while (count($path) != 0) {
//                $v = array_shift($path);
//                foreach (glob($v) as $item) {
//                    if (is_file($item)) {
//                        //$send_count++;
//                        $content = file_get_contents($item);
//                        if (!$content) {
//                            continue;
//                        }
//                        foreach ($ws_worker->connections as $c) {
//                            echo "发送tcp消息：", $content,"\r\n";
//                            $res = $c->send($content."\r\n\r\n\r\n");
//                            if ($res) {
//                                echo "成功\r\n";
//                            } else {
//                                echo "失败\r\n";
//                            }
//                           // $send_count2++;
//                        }
//
//                        //echo $send_count.":".$send_count2,"\r\n\r\n";
//                        //file_put_contents(HOME."/logs/tcp", $send_count.":".$send_count2);
//
//                        unlink($item);
//                    }
//                }
//            }
//            $debug = ob_get_contents();
//            ob_end_clean();
//
//            if ($debug) {
//                echo $debug;
//            }
//        });
    }
};


$ws_worker->onError = function ()
{
    echo "发生错误".json_encode(func_get_args(), JSON_UNESCAPED_UNICODE), "\r\n";
};
$ws_worker->onBufferFull = function()
{
    echo "发送缓冲区满".json_encode(func_get_args(), JSON_UNESCAPED_UNICODE), "\r\n";
};
$ws_worker->onBufferDrain = function()
{
    echo "发送缓冲可以继续".json_encode(func_get_args(), JSON_UNESCAPED_UNICODE), "\r\n";
};

//$msg_all = "";

// Emitted when data received
$ws_worker->onMessage = function($connection, $data) use($ws_worker)
{

   // global $ws_worker;
    static $msg_all;
    static $count = 0;
    $split = "\r\n\r\n\r\n";
    //while ($msg = socket_read($socket, 10240))
    {
        //echo $msg,"\r\n\r\n";
        $msg_all .= $data;
        $temp = explode($split, $msg_all);
        if (count($temp) >= 2) {
            $msg_all = array_pop($temp);
            foreach ($temp as $v) {
                $arr = json_decode($v , true);
                if (!$v || !is_array($arr) || count($arr) <= 0) {
                    echo $v,"格式不正确\r\n";
                    continue;
                }
                $count++;
                echo $v, "\r\n";
                echo "收到消息次数：", $count, "\r\n\r\n";

                foreach ($ws_worker->connections as $c) {
                    if ($c == $connection) {
                        echo "当前链接不发送\r\n";
                        continue;
                    }
                  //  echo "发送tcp消息：", $content,"\r\n";
                    $res = $c->send($v."\r\n\r\n\r\n");
                    if ($res) {
                        echo "成功\r\n";
                    } else {
                        echo "失败\r\n";
                    }
                    // $send_count2++;
                }



            }
        }
        unset($temp);
    }


   // \Workerman\Lib\Timer::add(0.001, function() use($ws_worker){
//        ob_start();
//        $path[] = HOME . "/cache/tcp/".$ws_worker->id."/*";
//        while (count($path) != 0) {
//            $v = array_shift($path);
//            foreach (glob($v) as $item) {
//                if (is_file($item)) {
//                    //$send_count++;
//                    $content = file_get_contents($item);
//                    if (!$content) {
//                        continue;
//                    }
//                    foreach ($ws_worker->connections as $c) {
//                        echo "发送tcp消息：", $content,"\r\n";
//                        $res = $c->send($content."\r\n\r\n\r\n");
//                        if ($res) {
//                            echo "成功\r\n";
//                        } else {
//                            echo "失败\r\n";
//                        }
//                        // $send_count2++;
//                    }
//
//                    //echo $send_count.":".$send_count2,"\r\n\r\n";
//                    //file_put_contents(HOME."/logs/tcp", $send_count.":".$send_count2);
//
//                    unlink($item);
//                }
//            }
//        }
//        $debug = ob_get_contents();
//        ob_end_clean();
//
//        if ($debug) {
//            echo $debug;
//        }
   // });


    // global $ws_worker,$send_count,$send_count2;
    //$current_process_id = get_current_processid();

    // Send hello $data
    //$connection->send('hello ' . $data);
//    ob_start();
//    $path[] = HOME . "/cache/tcp/*";
//    while (count($path) != 0) {
//        $v = array_shift($path);
//        foreach (glob($v) as $item) {
//            if (is_file($item)) {
//                $send_count++;
//                $content = file_get_contents($item);
//                if (!$content) {
//                    continue;
//                }
//                foreach ($ws_worker->connections as $c) {
//                    echo "发送tcp消息：", $content,"\r\n";
//                    $res = $c->send($content."\r\n\r\n\r\n");
//                    if ($res) {
//                        echo "成功\r\n";
//                    } else {
//                        echo "失败\r\n";
//                    }
//                    $send_count2++;
//                }
//
//                echo $send_count.":".$send_count2,"\r\n\r\n";
//                file_put_contents(HOME."/logs/tcp", $send_count.":".$send_count2);
//
//                unlink($item);
//            }
//        }
//    }
//    $debug = ob_get_contents();
//    ob_end_clean();
//
//    if ($debug) {
//        echo $debug;
//    }

   // unset($debug, $recv_msg);
};

// Emitted when connection closed
$ws_worker->onClose = function($connection)
{
    echo "Connection closed\n";
};

//$ws_worker->onWorkerStart = function(){
//    //file_put_contents(HOME."/tcp.pid",get_current_processid()."   ", FILE_APPEND);
//};
// Run worker
Worker::runAll();
