<?php
require __DIR__ . '/vendor/autoload.php';
require __DIR__ . '/config/conf.php';
require __DIR__ . '/vendor/myphps/myphp/base.php';

date_default_timezone_set("PRC");
define('IS_WINDOWS', DIRECTORY_SEPARATOR === '\\');
define("HOME", __DIR__);

$chain_id = (int)GetC('chain_id');
if(!$chain_id) exit('fail chain_id');

$coverFile = HOME . '/cache/fail_data';
if(!file_exists($coverFile)){
    exit('null');
}
$ok=0; $all=0;
$fp = fopen($coverFile, "r+");
while(!feof($fp)){
    $result = fgets($fp);
    echo $result,PHP_EOL;
    if($result){
        $result=json_decode(trim(substr($result, 20)), true);
        if(isset($result["dbname"])){
            $hasOk = toRecover($chain_id, $result);
            if($hasOk){
                $all++;
                if($hasOk===1) $ok++;
            }
            echo $hasOk?'ok':'fail', PHP_EOL;
        }
    }
}
if (flock($fp, LOCK_EX)) {
    ftruncate($fp, 0);
    flock($fp, LOCK_UN);
} else {
    echo "lock fail",PHP_EOL;
}
fclose($fp);
$msg = 'all:'.$all.', ok:'.$ok.', '.toByte(memory_get_peak_usage()). ' -- '. run_mem().' -- '. run_time();
file_put_contents(HOME.'/recover_result.log', date("Y-m-d H:i:s ").$msg."\r\n", FILE_APPEND);
echo $msg,PHP_EOL;

function toRecover($chain_id, $result)
{
    static $useDbName;
    try{
        $db_name = str_replace('_'.$chain_id,'',$result['dbname']);
        //切换库
        if($useDbName!=$db_name){
            $useDbName = $db_name;

            db()->db->config['name'] = $db_name; //防止重连时丢失选择库
            db()->execute('use '.$db_name);
        }


        if($result['event']=='write_rows' || $result['event']=='update_rows'){
            $data = $result['event']=='write_rows' ? $result['data'] : $result['data']['new'];
            $model = new \Model($result['table']);
            $hasData = $model->where(['id'=>$data['id']])->find();
            if(!$hasData){
                $model->setData($data);
                if($model->save(null, null, 0)===false){
                    throw new \Exception(\myphp::err());
                }
                return 1;
            }
        }elseif($result['event']=='delete_rows'){
            $data = $result['data'];
            $day30 = strtotime('-1 month');

            if($result['table']=='order' && $data['pay_time']==0 && $day30>$data['ctime']){
                $this->db->del($result['table'], ['id'=>$data['id']]);
                return 1;
            }
            if($result['table']=='mch_order' && $data['pay_time']==0 && $day30>$data['ctime']){
                $this->db->del($result['table'], ['id'=>$data['id']]);

                $this->db->del('mch_ordermx', ['mch_id'=>$data['mch_id'],'o_id'=>$data['_id']]);
                return 1;
            }
        }
        return true;
    }catch (\Exception $e){
        $hasRepeat = $result['event']=='write_rows' && strpos($e->getMessage(), 'Duplicate entry');
        if(!$hasRepeat){
            \Log::write($this->currTable, 'table');
            \Log::write($result['data'], 'data');
            \Log::Exception($e, false);

            file_put_contents(HOME.'/cache/fail_data2', date("Y-m-d H:i:s ").json_encode($result)."\n", FILE_APPEND);
        }
    }
    return false;
}
