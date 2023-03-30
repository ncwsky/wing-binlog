<?php
require __DIR__ . '/vendor/autoload.php';
if (is_file(__DIR__ . "/../conf.local.php")) {
    require __DIR__ . "/../conf.local.php";
} else {
    require __DIR__ . "/../conf.php";
}
require __DIR__ . '/../vendor/myphps/myphp/base.php';

$chain_id = 0;
$home_dir = '';
$c_key = array_search('-c', $argv); //-c 连锁id
if($c_key && isset($argv[$c_key+1])){
    $chain_id = trim($argv[$c_key+1]);
}
$c_key = array_search('-m', $argv); //指定主目录
if($c_key && isset($argv[$c_key+1])){
    $home_dir = $argv[$c_key+1];
} else {
    exit("php recover.php -m 主目录  \n");
}

date_default_timezone_set("PRC");
//根目录
define("HOME", $home_dir);
define("CACHE_DIR", $home_dir.'/cache');
define("CONFIG_DIR", $home_dir.'/config');
define("LOG_DIR", $home_dir.'/logs');

if (!$chain_id) exit('fail chain_id |  php recover.php -m 主目录 -c 连锁id ');

$coverFile = CACHE_DIR . '/fail_data';
if (!file_exists($coverFile)) {
    exit('null');
}
$ok = 0;
$all = 0;
$fp = fopen($coverFile, "r+");
while (!feof($fp)) {
    $result = fgets($fp);
    echo $result, PHP_EOL;
    if ($result) {
        $result = json_decode(trim(substr($result, 20)), true);
        if (isset($result["dbname"])) {
            $hasOk = toRecover($chain_id, $result);
            if ($hasOk) {
                $all++;
                if ($hasOk === 1) $ok++;
            }
            echo $hasOk ? 'ok' : 'fail', PHP_EOL;
        }
    }
}
if (flock($fp, LOCK_EX)) {
    ftruncate($fp, 0);
    flock($fp, LOCK_UN);
} else {
    echo "lock fail", PHP_EOL;
}
fclose($fp);
$msg = 'all:' . $all . ', ok:' . $ok . ', ' . toByte(memory_get_peak_usage()) . ' -- ' . run_mem() . ' -- ' . run_time();
file_put_contents(LOG_DIR . '/recover_result.log', date("Y-m-d H:i:s ") . $msg . "\r\n", FILE_APPEND);
echo $msg, PHP_EOL;

function toRecover($chain_id, $result)
{
    static $useDbName;
    try {
        $db_name = $chain_id . '_' . $result['dbname'];
        //切换库
        if ($useDbName != $db_name) {
            $useDbName = $db_name;

            db()->conn()->config['name'] = $db_name; //防止重连时丢失选择库
            db()->execute('use ' . $db_name);
        }

        if ($result['event'] == 'write_rows' || $result['event'] == 'update_rows') {
            $data = $result['event'] == 'write_rows' ? $result['data'] : $result['data']['new'];
            $model = new \myphp\Model($result['table']);
            $hasData = $model->where(['id' => $data['id']])->find();
            if (!$hasData) {
                $model->setData($data);
                if ($model->save(null, null, 0) === false) {
                    throw new \Exception(\myphp::err());
                }
                return 1;
            }
        } elseif ($result['event'] == 'delete_rows') {
            $data = $result['data'];
            db()->del($result['table'], ['id' => $data['id']]);
        } elseif($result['event']=='query'){
            db()->execute($result['data']);
        }
        return true;
    } catch (\Exception $e) {
        $hasRepeat = $result['event'] == 'write_rows' && strpos($e->getMessage(), 'Duplicate entry');
        if (!$hasRepeat) {
            isset($result['table']) && \myphp\Log::write($result['table'], 'table');
            \myphp\Log::write($result['data'], 'data');
            \myphp\Log::Exception($e, false);

            file_put_contents(CACHE_DIR . '/fail_data2', date("Y-m-d H:i:s ") . json_encode($result) . "\n", FILE_APPEND);
        }
    }
    return false;
}
