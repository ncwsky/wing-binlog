#!/usr/bin/env php
<?php
//declare(ticks = 1);
if (!function_exists("socket_create")) {
    exit("Please install php_sockets extension \n");
}

if (array_search('-h', $argv)) {
    echo 'Usage: php ' . basename($_SERVER['SCRIPT_FILENAME']) . ' start|restart|stop|status OPTION
    
    -h          说明
    -d          以守护进程执行
    -m          运行主目录
    -c          配置文件', PHP_EOL;
    exit(0);
}

$daemon = false;
if ($c_key = array_search('-d', $argv)) { //守护模式
    $daemon = true;
    unset($argv[$c_key]);
}
$config = 'app';
$c_key = array_search('-c', $argv); //指定配置文件 不指定默认在主目录config下
if ($c_key && isset($argv[$c_key + 1])) {
    $config = $argv[$c_key + 1];
    unset($argv[$c_key], $argv[$c_key + 1]);
}
$home_dir = '';
$c_key = array_search('-m', $argv); //指定主目录
if ($c_key && isset($argv[$c_key + 1])) {
    $home_dir = $argv[$c_key + 1];
    unset($argv[$c_key], $argv[$c_key + 1]);
}
if (!$home_dir) {
    exit('未指定主目录');
}
$home_dir = realpath($home_dir);
if (!$home_dir) {
    exit('主目录不存在');
}
$argv = array_values($argv);
$action = $argv[1] ?? '';

echo 'run dir: ' . $home_dir . ', config: ' . $config . ', action: ' . $action . ', daemon: ' . ($daemon ? 'Y' : 'N') . PHP_EOL;

define("WING_CONFIG", $config);
define("WING_DEBUG", !$daemon);

//定义时区
date_default_timezone_set("PRC");
const IS_WINDOWS = DIRECTORY_SEPARATOR === '\\';
//根目录
define("HOME", $home_dir);
define("CACHE_DIR", $home_dir . '/cache');
define("CONFIG_DIR", $home_dir . '/config');
define("LOG_DIR", $home_dir . '/logs');
//配置目录
if (!is_dir(CONFIG_DIR)) {
    exit('没有配置目录: ' . CONFIG_DIR);
}
//日志目录
if (!is_dir(LOG_DIR)) {
    mkdir(LOG_DIR);
}
//缓存目录
if (!is_dir(CACHE_DIR)) {
    mkdir(CACHE_DIR);
}

if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    echo "正在尝试安装依赖-composer install", PHP_EOL;
    exec("composer install");
}

require __DIR__ . '/vendor/autoload.php';

if (WING_DEBUG) {
    ini_set("display_errors", "On");
    error_reporting(E_ALL);
}
if (!load_config(WING_CONFIG)) {
    exit("config load fail \n");
}
if (!in_array($action, ['start', 'restart', 'stop', 'status'])) {
    $action = '';
}
$runLock = $home_dir . '/runLock'; //防重复运行
if ($action == 'start') {
    if (file_exists($runLock) && file_get_contents($runLock) == 1) {
        echo 'wing is running!', PHP_EOL;
        exit(0);
    }
} elseif ($action == 'restart') {
    \Wing\Library\Worker::stopAll();
} elseif ($action == 'stop') {
    file_put_contents($runLock, 0);
    \Wing\Library\Worker::stopAll();
    exit(0);
} elseif ($action == 'status') {
    \Wing\Library\Worker::showStatus();
    sleep(1);
    echo file_get_contents(HOME . "/logs/status.log");
    exit(0);
} else {
    echo "执行 php wing start|restart|stop|status, [start|restart]可选参数 -d 以守护进程执行" . PHP_EOL;
    echo "如： php wing start -d -c app|/xx/app.php" . PHP_EOL;
    exit(0);
}

file_put_contents($runLock, 1);
$worker = new \Wing\Library\Worker([
    "daemon" => $daemon
]);
$worker->start();