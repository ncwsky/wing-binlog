<?php
use Wing\Library\PDO;

require __DIR__.'/vendor/autoload.php';
require_once(__DIR__ . '/config/conf.php');
require_once(__DIR__ . '/vendor/myphps/myphp/base.php');

date_default_timezone_set("PRC");
define('IS_WINDOWS', DIRECTORY_SEPARATOR === '\\');
define("HOME", __DIR__);

$chain_id = (int)GetC('chain_id');
if(!$chain_id) exit('fail chain_id');

$shareVal = $chain_id%10;

function sql_get_lines($file, $table_name, $shareVal){
    $fp = fopen($file, 'r');
    while($sql = fgets($fp)){
        $sql = str_replace('`'.$table_name.'-'.$shareVal.'`','`'.$table_name.'`', $sql);
        yield $sql;
    }
    fclose($fp);
}

$db = db();
$sqlList = glob(__DIR__. '/sql_dump/*.sql');
foreach ($sqlList as $file){
    $file_name = basename($file);
    #if($file_name!='yxchain.user_money.sql') continue;
    echo $file_name,PHP_EOL;
    list($db_name,$table_name,) = explode('.', $file_name);

    $i = 0;
/*    $lines = sql_get_lines($file, $table_name, $shareVal);
    foreach ($lines as $sql){
        $i++;
        echo $sql,PHP_EOL;
    }*/

    $db->execute('use '.$db_name);
    $fp = fopen($file, 'r');
    while($sql = fgets($fp)){
        $old = '`'.$table_name.'-'.$shareVal.'`';
        if(strpos($sql, 'INTO '.$old)){
            $sql = str_replace($old,'`'.$table_name.'`', $sql);
            $i++;
            $db->execute($sql);
        }
        #echo $sql,PHP_EOL;
    }
    fclose($fp);
    @unlink($file);
    echo $file_name.':'.$i,PHP_EOL;
}


$uid = 0;
$res = db()->query('select uid from yxchain.chain_user where uid>'.$uid.' order by id asc limit 200',true);
while($res){
    try{
        $ids = '';
        foreach ($res as $v){
            $ids .= ','.$v['uid'];
            $uid = $v['uid'];
        }
        $ids = substr($ids,1);

        $ret = \Wing\Subscribe\DbChain::curl($chain_id, '/merchant/chain-user', ['id'=>$ids]);
        foreach ($ret['data'] as $v){
            $model = new \Model('user');
            if($model->where(['id'=>$v['id']])->find()){
                continue;
            }
            $model->setData($v);
            if($model->save(null, null, 0)===false){
                throw new \Exception(\myphp::err());
            }
        }
        echo count($ret),PHP_EOL;
    }catch (\Exception $e){
        echo $e->getMessage();
        die();
    }
    usleep(100000);
    $res = db()->query('select uid from yxchain.chain_user where uid>'.$uid.' order by id asc limit 200',true);
}

$dbLock = HOME . '/dbLock'; //防重复运行
file_put_contents($dbLock, 1);
echo toByte(memory_get_peak_usage()), ' -- ', run_mem(),' -- ', run_time(),PHP_EOL;

