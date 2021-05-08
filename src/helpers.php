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

        if($level=='exception' || $level=='retry' || $level=='error' || strpos($msg,'退出')!==false){
            //发送通知
            $appConfig = load_config("app");
            if (!empty($appConfig['warn_notice_url'])) {
                $ret = curlSend($appConfig['warn_notice_url'], 'POST', ['title' => $level, 'msg' => $msg]);
                file_put_contents(HOME . "/logs/" . $level . ".log", 'warn_notice:' . $ret, FILE_APPEND);
            }
        }
    }
}

//通过curl 自定义发送请求
function curlSend($url, $type='GET', $data=null, $timeout=5, $header='', $opt=[])
{
    /*
    GET（SELECT）：从服务器取出资源（一项或多项）。
    POST（CREATE）：在服务器新建一个资源。
    PUT（UPDATE）：在服务器更新资源（客户端提供改变后的完整资源）。
    PATCH（UPDATE）：在服务器更新资源（客户端提供改变的属性）。
    DELETE（DELETE）：从服务器删除资源。
    HEAD：获取资源的元数据。
    */
    if(!$header){
        $header="Accept: text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,image/apng,*/*;q=0.8\r\n";
        $header.="Accept-Language: zh-CN,zh;q=0.8,en-US;q=0.5,en;q=0.3\r\n";
        $header.="Cache-Control: no-cache\r\n";
        $header.="User-Agent: Mozilla/5.0 (Windows NT 10.0; WOW64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/70.0.3538.25 Safari/537.36 Core/1.70.3722.400 QQBrowser/10.5.3751.400";
    }else{
        if(is_array($header)){
            $headers = $header;
            $header = '';
            foreach ($headers as $k=>$v){
                $header .= "\r\n".(is_int($k) ? $v : $k . ':' . $v);
            }
            $header = substr($header, 2);
        }
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    if(substr($url,0,5)=='https'){ //ssl
        curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0); //检查服务器SSL证书 正式环境中使用 2
        curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false); //取消验证证书

        if(isset($opt['cert']) && isset($opt['key'])){
            $opt['type'] = isset($opt['type']) ? $opt['type'] : 'PEM';
            curl_setopt($ch, CURLOPT_SSLCERTTYPE, $opt['type']);
            curl_setopt($ch, CURLOPT_SSLKEYTYPE, $opt['type']);
            curl_setopt($ch, CURLOPT_SSLCERT, $opt['cert']);
            curl_setopt($ch, CURLOPT_SSLKEY, $opt['key']);
        }
        if(isset($opt['cainfo']) || isset($opt['capath'])){
            isset($opt['cainfo']) && curl_setopt($ch, CURLOPT_CAINFO , $opt['cainfo']);
            isset($opt['capath']) && curl_setopt($ch, CURLOPT_CAPATH , $opt['capath']);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 2);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, true);
        }
    }

    $type = strtoupper($type);
    switch ($type) {
        case 'GET':
            if ( $data ) {
                $data = is_array($data) ? http_build_query($data) : $data;
                $url = strpos($url, '?') === false ? ($url . '?' . $data) : ($url . '&' . $data);
                curl_setopt($ch, CURLOPT_URL, $url);
            }
            curl_setopt($ch, CURLOPT_HTTPGET, true);
            if(!empty($opt['redirect'])){ #是否重定向
                curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true); #302 redirect
                curl_setopt($ch, CURLOPT_MAXREDIRS, (int)$opt['redirect']); #次数
            }
            break;
        case 'POST':
            //https 使用数组的在某些未知情况下数据长度超过一定长度会报SSL read: error:00000000:lib(0):func(0):reason(0), errno 10054
            if(is_array($data)){
                $toBuild = true;
                if(class_exists('CURLFile')){ //针对上传文件处理
                    foreach ($data as $v){
                        if($v instanceof CURLFile){
                            $toBuild = false;
                            break;
                        }
                    }
                }
                if($toBuild) $data = http_build_query($data);
            }
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            if(is_string($data) && strlen($data)>=1024) $header .= "\r\nExpect:"; //取消 100-continue应答
            break;
        case 'PATCH':
        case 'PUT':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $type);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $data);
            break;
        case 'DELETE':
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, 'DELETE');
            break;
        case 'HEAD':
            curl_setopt($ch, CURLOPT_NOBODY, true); //将不对HTML中的BODY部分进行输出
            break;
    }
    if(is_string($header)){
        if(stripos($header, 'Referer')===false)
            curl_setopt($ch, CURLOPT_REFERER, $url);

        $header = explode("\r\n", $header);
    }
    if(isset($opt['referer'])){
        curl_setopt($ch, CURLOPT_REFERER, $opt['referer']);
    }

    if(isset($opt['cookie'])) curl_setopt($ch, CURLOPT_COOKIE, $opt['cookie']);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_TIMEOUT, $timeout);
    curl_setopt($ch, CURLOPT_HTTPHEADER, $header);//模拟的header头

    curl_setopt($ch, CURLOPT_TIMEOUT_MS, $timeout * 1000);
    curl_setopt($ch, CURLOPT_CONNECTTIMEOUT_MS, $timeout * 1000);
    //$timeoutRequiresNoSignal = false; $timeoutRequiresNoSignal |= $timeout < 1;
    if ($timeout < 1 && strtoupper(substr(PHP_OS, 0, 3)) !== 'WIN') {
        curl_setopt($ch, CURLOPT_NOSIGNAL, true);
    }
    $result = false;
    if(isset($opt['res'])){
        curl_setopt($ch, CURLOPT_HEADER, true);    // 是否需要响应 header
        $output          = curl_exec($ch);
        if($output!==false){
            $header_size     = curl_getinfo($ch, CURLINFO_HEADER_SIZE);    // 获得响应结果里的：头大小
            //$res_header = substr($output, 0, $header_size);    // 根据头大小去获取头信息内容
            //$http_code  = curl_getinfo($ch, CURLINFO_HTTP_CODE);    // 获取响应状态码
            //$res_body   = substr($output, $header_size);
            $result = [
                //'request_url'        => $url,
                //'request_body'       => $data,
                //'request_header'     => $header,
                'res_http_code' => curl_getinfo($ch, CURLINFO_HTTP_CODE), // 获取响应状态码
                'res_body'      => substr($output, $header_size),
                'res_header'    => substr($output, 0, $header_size),
                'res_errno'     => curl_errno($ch),
                'res_error'     => curl_error($ch),
            ];
        }
    }else{
        $result = curl_exec($ch);
    }
    if(curl_errno($ch)){
        $err_file = HOME.'/logs/curl_err.log';
        if(is_file($err_file) && 4194304 <= filesize($err_file) ){ #4M
            #copy($err_file, dirname($err_file).'/'.date('YmdHis').'.log');
            file_put_contents($err_file, '', LOCK_EX | LOCK_NB);
            clearstatcache(true, $err_file);
        }
        error_log('err:'. curl_error($ch)."\nurl:".$url.($data!==null?"\ndata:".(is_scalar($data)?urldecode($data):json_encode($data)):'')."\n", 3, $err_file);
    }

    curl_close($ch);
    return $result;
}