<?php namespace Wing\Subscribe;

use Wing\Cache\File;
use Wing\Library\ISubscribe;

/**
 * 使用数据库写数据
 * @package Wing\Subscribe
 */
class DbTm implements ISubscribe
{
    public $db;
    public $allowDbTable = []; // 格式 ['db_name'=>1|['table_name',...],....]
    protected $useDbName = ''; //使用的库名
    protected $currTable = '';
    protected $dataDir = '';
    protected $cache = null;
    protected $chain_id = 0;

	public function __construct($config)
	{
        require_once(HOME . '/config/conf.php');
        require_once(HOME . '/vendor/myphps/myphp/base.php');

        $this->db = db();
        $this->allowDbTable = $config['allow_db_table']??[];
        $this->useDbName = '';
        $this->dataDir = HOME."/cache";
        $this->cache = new File(HOME."/cache");

        //无字段存在过程 创建
        if(!db('db2')->getOne("SHOW PROCEDURE STATUS LIKE 'TableFields'")){
            wing_log('run', '生成TableFields过程函数');
            db('db2')->execute('use mysql');
            db('db2')->execute(file_get_contents(HOME.'/config/TableFields.sql'));
            db('db2')->execute('use '.GetC('db2.name'));
        }
    }
    //连锁判断
    protected function chkChainId(&$result){
	    static $chainMap = [];
        switch ($result['event']){
            case 'write_rows':
            case 'delete_rows':
                $data = $result['data'];
                break;
            case 'update_rows':
                $data = $result['data']['new'];
                break;

        }

        $is_chain = false;
        if($this->currTable=='merchant'){
            $this->chain_id = $data['chain_id'];
        }else{
            if($this->currTable=='user'){
                $this->chain_id = 0;
                if(strpos($data['ext'],'chain_id')){
                    $this->chain_id = intval(json_decode($data['ext'], true)['chain_id']??0);
                }
                #如果结果是0 不是tm
                $this->chain_id===0 && $this->chain_id = (int)db('db2')->getCustomId('yxchain.chain_user', 'chain_id', 'uid='.$data['id']);
                $is_chain = $this->chain_id ? true : false;
            }elseif(isset($data['chain_id'])) { //chain_user类表
                $this->chain_id = $data['chain_id'];
                $is_chain = true;
            }else{
                $mch_id = $data['mch_id']??0;
                $this->chain_id = (int)db('db2', true)->getCustomId('merchant', 'chain_id', 'id='.$mch_id);
            }
        }
        if(isset($chainMap[$this->chain_id])) return $chainMap[$this->chain_id];

        //查询是否是tm
        if($this->chain_id>0){
            $type = $is_chain ? 99 : (int)db('db2', true)->getCustomId('merchant', 'type', 'id='.$this->chain_id);
            $chainMap[$this->chain_id] = $type==99;
        }else{
            $chainMap[$this->chain_id] = false;
        }
        //生成复制账号
        if($chainMap[$this->chain_id]){
            $sql = "CREATE USER 'yx_".$this->chain_id."'@'%' IDENTIFIED BY 'yx_".$this->chain_id."'";
            try{
                db()->execute($sql);
            }catch (\Exception $e){}
            try{
                db('db2')->execute($sql);
            }catch (\Exception $e){}

            $sql = "GRANT REPLICATION SLAVE, REPLICATION CLIENT ON *.* TO 'yx_".$this->chain_id."'@'%';GRANT EXECUTE ON PROCEDURE `mysql`.`TableFields` TO 'yx_".$this->chain_id."'@'%'";
            db()->execute($sql);
            db('db2')->execute($sql);
            #$sql = "REVOKE EXECUTE ON PROCEDURE `mysql`.`TableFields` FROM 'yx_".$this->chain_id."'@'%';";

            #$sql = "GRANT SELECT ON *.* TO 'yx_40259'@'%';";
            #db('db2')->execute($sql);
            #"REVOKE SELECT ON `yxgoods\_40259`.* FROM 'yx_40259'@'%';";
        }
        #file_put_contents(HOME.'/logs/chainMap', json_encode($chainMap));
        return $chainMap[$this->chain_id];
    }
    protected function initMerchant(){
        $k = md5(sprintf("%s+%s", 'chain_id='.$this->chain_id, md5('rMRVa&UkF32FjQlF_%_'.($this->chain_id<<6))));
        $url = GetC('api_url').'/merchant/chain-list?chain_id='.$this->chain_id.'&k='.$k;
        $json = \Http::doGet($url, 10, '*/*');

        #wing_log('initMerchant', $url, $json);
        if ($json === false) {
            wing_log('initMerchant', '数据获取失败');
            return;
        }
        $res = json_decode($json, true);
        if (!$res) {
            wing_log('initMerchant', '数据json解析失败:' . $json);
            return;
        }
        if(!isset($res['data']) && !array_key_exists('data', $res)){
            wing_log('initMerchant', isset($res['message'])?$res['message']:'数据请求失败');
            return;
        }
        if($res['code']!=0){
            wing_log('initMerchant', $res['msg']);
            return;
        }
        try{
            $table = $this->currTable;

            $this->currTable = 'merchant';
            foreach ($res['data'] as $v){
                $this->_update($v, $v);
            }

            $this->currTable = $table; //还原表名
        }catch (\Exception $e){
            wing_log('initMerchant', $e->getMessage());
        }
    }
    //初始库
    protected function initDb($dbName){
        static $dbMap = [];

        $name = $dbName.'_'.$this->chain_id;
        if(isset($dbMap[$name])) return $dbMap[$name];

        $createSql = 'CREATE DATABASE IF NOT EXISTS '.$dbName.'_'.$this->chain_id.' DEFAULT CHARACTER SET utf8;';
        $this->db->execute($createSql);


        $this->useDbName = $name;
        $this->db->db->config['name'] = $name; //防止重连时丢失选择库
        $this->db->execute('use '.$name);
        //初始库.表
        if(is_file(HOME.'/config/'.$dbName.'.sql')){
            $this->db->execute(file_get_contents(HOME.'/config/'.$dbName.'.sql'));
        }
        if($dbName==GetC('db2.name')){
            $this->initMerchant();
        }
        #file_put_contents(HOME.'/logs/dbMap', json_encode($dbMap));
        $dbMap[$name] = true;
    }

	public function onchange($result)
	{
        try{
            if($result['event']=='query'){
                return;
            }
            //库检查
            if(!isset($this->allowDbTable[$result['dbname']])){
                return;
            }
            //表检测
            $this->currTable = $result['table']??'';
            if(is_array($this->allowDbTable[$result['dbname']]) && !in_array($this->currTable, $this->allowDbTable[$result['dbname']])){
                return;
            }
            //连锁检测
            if(!$this->chkChainId($result)){
                return;
            }

            //初始库
            $this->initDb($result['dbname']);
            $currDbName = $result['dbname'].'_'.$this->chain_id;
            //切换库
            if($this->useDbName!=$currDbName){
                $this->useDbName = $currDbName;
                $this->db->db->config['name'] = $currDbName; //防止重连时丢失选择库
                $this->db->execute('use '.$currDbName);
            }

            switch ($result['event']){
                case 'write_rows':
                    $this->_write($result['data']);
                    break;
                case 'update_rows':
                    $this->_update($result['data']['new'], $result['data']['old']);
                    break;
                case 'delete_rows':
                    $this->_delete($result['data']);
                    break;
            }
        }catch (\Exception $e){
            $hasRepeat = $result['event']=='write_rows' && strpos($e->getMessage(), 'Duplicate entry');

            if($hasRepeat){
                #\Log::write($this->db->getSql(), 'Duplicate');
                #error_log(date("Y-m-d H:i:s ").json_encode($result)."\n", 3, $this->dataDir.'/repeat_data');
            }else{
                \Log::write($this->currTable, 'table');
                \Log::write($result['data'], 'data');
                \Log::Exception($e, false);

                //发送通知
                $appConfig = load_config("app");
                if (!empty($appConfig['warn_notice_url'])) {
                    if(!$this->cache->get('warn-notice')){ #x分钟内只发一次
                        $this->cache->set('warn-notice', date("Y-m-d H:i:s"), 30*60);

                        $ret = \Http::doPost($appConfig['warn_notice_url'], ['title' => 'binglog错误预警', 'msg' => $e->getMessage()]);
                        \Log::write($ret, 'curl');
                    }
                }

                //$result 缓存下来用于修复处理
                error_log(date("Y-m-d H:i:s ").json_encode($result)."\n", 3, $this->dataDir.'/fail_data');
            }
        }
	}

	protected function _write($data){
        $model = new \Model($this->currTable);
        $model->setData($data);
        if($model->save(null, null, 0)===false){
            throw new \Exception(\myphp::err());
        }
    }
	protected function _update($data, $old){
        $model = new \Model($this->currTable);
        $model->where(['id'=>$old['id']])->find();
        $model->setData($data);
        if($model->save(null, null, 0)===false){
            throw new \Exception(\myphp::err());
        }
    }
	protected function _delete($data){
        if($this->currTable=='user' || $this->currTable=='mchuser'){
            $this->db->del($this->currTable, ['id'=>$data['id']]);
            return;
        }
        
        $day30 = strtotime('-1 month');
        // order 未支付的支持删除
        if($this->currTable=='order' && $data['pay_time']==0 && $day30>$data['ctime']){
            $this->db->del($this->currTable, ['id'=>$data['id']]);
            return;
        }
        // mch_order 未支付的支持删除 #mch_order与mch_ordermx做关联删除处理
        if($this->currTable=='mch_order' && $data['pay_time']==0 && $day30>$data['ctime']){
            $this->db->del($this->currTable, ['id'=>$data['id']]);

            $this->db->del('mch_ordermx', ['mch_id'=>$data['mch_id'],'o_id'=>$data['_id']]);
        }
    }
}