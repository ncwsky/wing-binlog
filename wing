#!/usr/bin/env php
<?php
//declare(ticks = 1);

// Only for cli.
use Wing\Library\Worker;

if (PHP_SAPI !== 'cli') {
    exit("Only run in command line mode \n");
}
if (!function_exists("socket_create")) {
    exit("Please install php_sockets extension \n");
}

$action = isset($argv[1]) ? $argv[1] : '';
$daemon = array_search('-d', $argv) ? true : false;
$config = 'app';
$home_dir = __DIR__;
$c_key = array_search('-c', $argv); //指定配置文件 不指定默认在主目录config下
if($c_key && isset($argv[$c_key+1])){
    $config = $argv[$c_key+1];
}
$c_key = array_search('-m', $argv); //指定主目录
if($c_key && isset($argv[$c_key+1])){
    $home_dir = $argv[$c_key+1];
}
define("WING_CONFIG", $config);
define("WING_DEBUG", !$daemon);

//定义时区
date_default_timezone_set("PRC");
define('IS_WINDOWS', DIRECTORY_SEPARATOR === '\\');
//根目录
define("HOME", $home_dir);
define("CACHE_DIR", $home_dir.'/cache');
define("CONFIG_DIR", $home_dir.'/config');
define("LOG_DIR", $home_dir.'/logs');
//配置目录
if (!is_dir(CONFIG_DIR)) {
    exit('没有配置目录');
}
//日志目录
if (!is_dir(LOG_DIR)) {
    mkdir(LOG_DIR);
}
//缓存目录
if (!is_dir(CACHE_DIR)) {
    mkdir(CACHE_DIR);
}

//初始化命令行参数 $argc — 传递给脚本的参数数目
$str_argv = '';
for ($i = 1; $i < $argc; $i++) {
    $str_argv .= ' ' . $argv[$i];
}

$command_line = 'php ' . basename(__FILE__) . ' ' . $str_argv;
define("WING_COMMAND_LINE", $command_line);

if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    echo "正在尝试安装依赖-composer install", PHP_EOL;
    exec("composer install");
}

require __DIR__ . '/vendor/autoload.php';

if (WING_DEBUG) {
    ini_set("display_errors", "On");
    error_reporting(E_ALL);
}
if(!load_config(WING_CONFIG)){
    exit("config load fail \n");
}
if (!in_array($action, ['start', 'restart', 'stop', 'status'])) {
    $action = '';
}
$runLock = __DIR__ . '/runLock'; //防重复运行
if ($action == 'start') {
    if (is_file($runLock) && file_get_contents($runLock) == 1) {
        echo 'wing is running!', PHP_EOL;
        exit(0);
    }
} elseif ($action == 'restart') {
    Worker::stopAll();
} elseif ($action == 'stop') {
    file_put_contents($runLock, 0);
    Worker::stopAll();
    exit(0);
} elseif ($action == 'status') {
    Worker::showStatus();
    sleep(1);
    echo file_get_contents(HOME . "/logs/status.log");
    exit(0);
} else {
    echo "执行 php wing start|restart|stop|status, [start|restart]可选参数 -d 以守护进程执行" . PHP_EOL;
    echo "如： php wing start -d -c app|/xx/app.php" . PHP_EOL;
    exit(0);
}

file_put_contents($runLock, 1);
$worker = new Worker([
    "daemon" => $daemon
]);
$worker->start();