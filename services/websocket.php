#!/usr/bin/env php
<?php
//declare(ticks = 1);
require __DIR__.'/../vendor/autoload.php';

define("HOME", dirname(__DIR__));

date_default_timezone_set("PRC");
use Workerman\Worker;


$host = "0.0.0.0";
$port = 9998;

foreach ($argv as $item) {
    if (strpos($item,"--host") === 0)
        list(,$host) = explode("=",$item);
    if (strpos($item,"--port") === 0)
        list(,$port) = explode("=",$item);
    if (strpos($item,"--workers") === 0)
        list(,$workers) = explode("=",$item);
}

$workers = intval($workers);
if ($workers <= 0)
    $workers = 1;

// Create a Websocket server
$ws_worker = new Worker("websocket://".$host.":".$port);

// 4 processes
$ws_worker->count = 1;//$workers;

// Emitted when new connection come
$ws_worker->onConnect = function($connection)
{
    $connection->maxSendBufferSize = 104857600;
    echo "New connection\n";
};

// Emitted when data received
$ws_worker->onMessage = function($connection, $data) use($ws_worker)
{
    echo "收到消息：", $data, "\r\n";
    static $msg_all;
    static $count = 0;
    $split = "\r\n\r\n\r\n";

    //echo $msg,"\r\n\r\n";
    $msg_all .= $data;
    $temp = explode($split, $msg_all);
    if (count($temp) >= 2) {
        $msg_all = array_pop($temp);
        foreach ($temp as $v) {
            $arr = json_decode($v , true);
            if (!$v) {
                echo "消息为空\r\n";
                continue;
            }
            if (!is_array($arr) || count($arr) <= 0) {
               echo "不是标准数组\r\n";
               echo "=====>".$v."<=====";
                continue;
            }
            $count++;
            echo $v, "\r\n";
            echo "收到消息次数：", $count, "\r\n\r\n";

            foreach ($ws_worker->connections as $c) {
                if ($c == $connection) {
                    echo "当前的连接不发送\r\n";
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
// Emitted when connection closed
$ws_worker->onClose = function($connection)
{
    echo "Connection closed\n";
};
$ws_worker->onWorkerStart = function($ws_worker){
//    \Workerman\Lib\Timer::add(0.001, function() use($ws_worker){
//        ob_start();
//        $path[] = HOME . "/cache/websocket/".$ws_worker->id."/*";
//        while (count($path) != 0) {
//            $v = array_shift($path);
//            foreach (glob($v) as $item) {
//                if (is_file($item)) {
//                    $content = file_get_contents($item);
//                    if (!$content) {
//                        continue;
//                    }
//                    foreach ($ws_worker->connections as $c) {
//                        $c->send($content);
//                    }
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
//
//    });
};

// Run worker
Worker::runAll();
