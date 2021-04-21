<?php
/**
 * @author yuyi
 * @created 2016/12/4 9:13
 * @email 297341015@qq.com
 */

if (!function_exists("set_process_title")) {
    /**
     * 设置进程名称
     * 此api仅在linux以及unix相关系统支持，windows不支持
     * @param string $title
     */
    function set_process_title($title)
    {
        if (IS_WINDOWS) {
            return null;
        }
        if (function_exists("setproctitle")) {
            return setproctitle($title);
        }
        if (function_exists("cli_set_process_title")) {
            return cli_set_process_title($title);
        }
        return null;
    }
}

if (!function_exists("get_process_title")) {
    /**
     * 获取进程名称
     * linux以及unix相关系统直接返回进程名称
     * windows下返回程序的启动命令
     * @return string
     */
    function get_process_title()
    {
        if (function_exists("cli_get_process_title")) {
            $title =  cli_get_process_title();
            if ($title) {
                return $title;
            }
        }
        return WING_COMMAND_LINE;
    }
}

if (!function_exists("get_current_processid")) {
    /**
     * 获取当前进程id
     * @return int
     */
    function get_current_processid()
    {
        if (function_exists("getmypid")) {
            return getmypid();
        }
        if (function_exists("posix_getpid")) {
            return posix_getpid();
        }
        return 0;
    }
}

if (!function_exists("enable_deamon")) {
    /**
     * 启用守护进程模式
     */
    function enable_deamon()
    {
        if (!function_exists("pcntl_fork")) {
            return;
        }
        //修改掩码
        umask(0);
        //创建进程
        $pid = pcntl_fork();
        if (-1 === $pid) {
            throw new \Exception('fork fail');
        } elseif ($pid > 0) {
            //父进程直接退出
            exit(0);
        }
        //创建进程会话 使当前进程成为会话的主进程
        if (-1 === posix_setsid()) {
            throw new \Exception("setsid fail");
        }
    }
}

if (!function_exists("reset_std")) {
    /**
     * 设置输出重定向到文件日志
     */
    function reset_std()
    {
        $os_name = php_uname('s');
        $short_os_name = substr($os_name, 0, 3);
        $short_os_name = strtolower($short_os_name);
        if ($short_os_name== "win") {
            return;
        }
        global $STDOUT, $STDERR;
        $file       = HOME."/logs/wing.log";
        $obj_file   = new \Wing\FileSystem\WFile($file);
        $obj_file->touch();
        @fclose(STDOUT);
        @fclose(STDERR);
        $STDOUT = fopen($file, "a+");
        $STDERR = fopen($file, "a+");
    }
}


if (!function_exists("load_config")) {
    static $all_configs = [];
    /**
     * 加载配置文件
     * @param string $name 文件名称，不带php后缀
     * @return mixed
     */
    function load_config($name)
    {
        global $all_configs;
        $config_file = HOME . "/config/" . $name . ".php";
        if (isset($all_configs[$name])) {
            return $all_configs[$name];
        } else {
            $all_configs[$name] = include $config_file;
        }
        return $all_configs[$name];
    }
}

if (!function_exists("try_lock")) {
    /**
     * 使用文件锁尝试加锁
     * @param string $key 锁定的key
     * @return bool true加锁成功，false加锁失败
     */
    function try_lock($key)
    {
        $dir = HOME."/cache/lock";
        if (!is_dir($dir)) {
            $obj_dir = new \Wing\FileSystem\WDir($dir);
            $obj_dir->mkdir();
            unset($obj_dir);
        }
        $file = $dir."/".md5($key);
        if (file_exists($file)) {
            return false;
        }
        touch($file);
        return file_exists($file);
    }
}

if (!function_exists("lock_free")) {
    /**
     * 释放锁
     * @param string $key 需要释放的key
     * @return bool
     */
    function lock_free($key)
    {
        $dir = HOME."/cache/lock";
        if (!is_dir($dir)) {
            $obj_dir = new \Wing\FileSystem\WDir($dir);
            $obj_dir->mkdir();
            unset($obj_dir);
        }
        $file = $dir."/".md5($key);
        if (!file_exists($file)) {
            return true;
        }
        return unlink($file);
    }
}

if (!function_exists("timelen_format")) {
    /**
     * 时间长度格式化
     * @param int $time_len 时间长度，单位为秒，比如 60， 最终会转换为 "1分钟" 或者 "1minutes"
     */
    function timelen_format($time_len)
    {
        $lang = "en";
        if ($time_len < 60) {
            if ($lang == "en") {
                return $time_len . " seconds";
            }
            return $time_len . "秒";
        } elseif ($time_len < 3600 && $time_len >= 60) {
            $m = intval($time_len / 60);
            $s = $time_len - $m * 60;
            if ($lang == "en") {
                return $m . " minutes " . $s . " seconds";
            }
            return $m . "分钟" . $s . "秒";
        } elseif ($time_len < (24 * 3600) && $time_len >= 3600) {
            $h = intval($time_len / 3600);
            $s = $time_len - $h * 3600;
            if ($s >= 60) {
                $m = intval($s / 60);
            } else {
                $m = 0;
            }
            $s = $s-$m * 60;
            if ($lang == "en") {
                return $h . " hours " . $m . " minutes " . $s . " seconds";
            }
            return $h . "小时" . $m . "分钟" . $s . "秒";
        } else {
            $d = intval($time_len / (24 * 3600));
            $s = $time_len - $d * (24 * 3600);
            $h = 0;
            $m = 0;
            if ($s < 60) {
                //do nothing
            } elseif ($s >= 60 && $s < 3600) {
                $m = intval($s / 60);
                $s = $s - $m * 60;
            } else {
                $h = intval($s / 3600);
                $s = $s - $h * 3600;
                $m = 0;
                if ($s >= 60) {
                    $m = intval($s / 60);
                    $s = $s - $m * 60;
                }
            }
            if ($lang == "en") {
                return $d." days ".$h . " hours " . $m . " minutes " . $s . " seconds";
            }
            return $d."天".$h . "小时" . $m . "分钟" . $s . "秒";

        }
    }
}

if (!function_exists("scan")) {
    function scan($dir, $callback)
    {
        ob_start();
        $path[] = $dir . "/*";
        while (count($path) != 0) {
            $v = array_shift($path);
            foreach (glob($v) as $item) {
                if (is_file($item)) {
                    $t   = explode("/", $item);
                    $t   = array_pop($t);
                    $sub = substr($t, 0, 4);
                    if ($sub == "lock") {
                        unset($t, $sub);
                        continue;
                    }
                    unset($t, $sub);
                    $callback($item);
                    unlink($item);
                }
            }
        }
        $debug = ob_get_contents();
        ob_end_clean();
        if ($debug) {
            wing_debug($debug);
        }
    }
}

if (!function_exists("wing_debug")) {
    function wing_debug($log)
    {
        if (!WING_DEBUG) {
            return;
        }
        echo date("Y-m-d H:i:s")." ";
        foreach (func_get_args() as $item) {
            if (is_scalar($item)) {
                echo $item." ";
            } else {
                var_dump($item);
            }
        }
        echo PHP_EOL;
    }
}

if (!function_exists("wing_log")) {
    function wing_log($level = "log", $msg = "")
    {
        $log = date("Y-m-d H:i:s")." ";
        $argvs = func_get_args();
        array_shift($argvs);
        foreach ($argvs as $item) {
            if (is_scalar($item)) {
                $log .= $item."  ";
            } else {
                $log.= json_encode($item, JSON_UNESCAPED_UNICODE)."  ";
            }
        }
        $log .= "\r\n";
        file_put_contents(HOME."/logs/".$level.".log", $log, FILE_APPEND);
    }
}