<?php
/**
 * Created by PhpStorm.
 * User: yuyi
 * Date: 17/8/17
 * Time: 07:52
 *
 * windows兼容
 */
defined('SIGTERM') || define('SIGTERM', 15); //中止服务
defined('SIGINT') || define('SIGINT', 2); //结束服务
defined('SIGUSR1') || define('SIGUSR1', 10); //重启服务
defined('SIGUSR2') || define('SIGUSR2', 12); //服务状态
defined('SIGPIPE') || define('SIGPIPE', 13);
defined('SIG_IGN') || define('SIG_IGN', 5); //忽略信号处理程序

if (!function_exists("pcntl_signal")) {
    function pcntl_signal($a = null, $b = null, $c = null, $d = null)
    {
    }
}
if (!function_exists("posix_kill")) {
    function posix_kill($a = null, $b = null, $c = null)
    {
    }
}

if (!function_exists("pcntl_signal_dispatch")) {
    function pcntl_signal_dispatch($a = null, $b = null, $c = null)
    {
    }
}

if (!function_exists("pcntl_wait")) {
    function pcntl_wait($a = null, $b = null, $c = null)
    {
        return 0;
    }
}
