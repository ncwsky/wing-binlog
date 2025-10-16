#!/usr/bin/env php
<?php
//declare(ticks = 1);
if (!function_exists("socket_create")) {
    exit("Please install php_sockets extension \n");
}

/**
 * 简单的命令行参数解析
 * @param string $options 参数名称 间字符选项、长选项同时支持使用|分隔: -c|--config
 * @param mixed $def
 * @param bool $optNoVal 选项不接受值
 * @return mixed|string
 */
function parseCmd(string $options, $def = null, bool $optNoVal = false)
{
    global $argv;
    $val = $optNoVal ? false : $def;
    $names = explode('|', $options);
    /* todo 识别处理 -c app|--config=app
    foreach ($argv as $k => $v){
      if($k==0) continue;

    }*/
    foreach ($names as $name) {
        $k = array_search($name, $argv);
        if ($k) {
            if ($optNoVal) {
                $val = true;
            } else {
                if (isset($argv[$k + 1])) {
                    $val = $argv[$k + 1];
                    unset($argv[$k + 1]);
                }
            }
            unset($argv[$k]);
            break;
        }
    }
    return $val;
}

if (parseCmd('-h', null, true)) {
    echo 'Usage: php ' . basename($_SERVER['SCRIPT_FILENAME']) . ' start|restart|stop|status OPTION
    
    -h          说明
    --debug     调试模式
    -d          以守护进程执行
    -m          运行主目录
    -c          配置文件', PHP_EOL;
    exit(0);
}

$daemon = parseCmd('-d', null, true); //守护模式
$config = parseCmd('-c|--config', 'app');
$home_dir = parseCmd('-m|--dir');
if (!$home_dir) {
    exit('未指定主目录');
}
$home_dir = realpath($home_dir);
if (!$home_dir) {
    exit('主目录不存在');
}
$argv = array_values($argv);
$action = $argv[1] ?? '';

echo 'run dir: ' . $home_dir . ', config: ' . $config . ', action: ' . $action . ', daemon: ' . ($daemon ? 'Y' : 'N') . PHP_EOL, PHP_EOL;

const IS_WINDOWS = DIRECTORY_SEPARATOR === '\\';

define('WING_CONFIG', $config);
define('WING_DEBUG', IS_WINDOWS ? IS_WINDOWS : parseCmd('--debug', null, true));

//定义时区
date_default_timezone_set('PRC');
//根目录
define('HOME', $home_dir);
define('CACHE_DIR', $home_dir . '/cache');
define('CONFIG_DIR', $home_dir . '/config');
define('LOG_DIR', $home_dir . '/logs');
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
if (!in_array($action, ['start', 'restart', 'stop', 'status', 'recover'])) {
    $action = '';
}
$runLock = $home_dir . '/runLock'; //防重复运行
if ($action == 'start') {
    if (!IS_WINDOWS && file_exists($runLock) && file_get_contents($runLock) == 1) {
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
} elseif ($action == 'recover') {
    $config = load_config(WING_CONFIG);
    $recover = false;
    if (isset($config["subscribe"]) && is_array($config["subscribe"])) {
        foreach ($config["subscribe"] as $class => $params) {
            $sync = new $class($params);
            if (method_exists($sync, 'recover')) {
                $recover = true;
                $sync->recover();
                break;
            }
        }
    }
    if ($recover) {
        echo "恢复执行成功", PHP_EOL;
    } else {
        echo "没有需要恢复的订阅任务", PHP_EOL;
    }
    exit(0);
} else {
    echo "执行 php wing start|restart|stop|status|recover -m指定主目录 -c指定配置文件, 可选参数 -d 以守护进程执行" . PHP_EOL;
    echo "如： php wing start -d -c app|/xx/app.php" . PHP_EOL;
    exit(0);
}

file_put_contents($runLock, 1);
$worker = new \Wing\Library\Worker((bool)$daemon);
$worker->start();