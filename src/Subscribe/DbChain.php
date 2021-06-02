<?php namespace Wing\Subscribe;

use Wing\Cache\File;
use Wing\Library\ISubscribe;

/**
 * 使用数据库写数据
 * @package Wing\Subscribe
 */
class DbChain implements ISubscribe
{
    public $db;
    public $allowDbTable = []; // 格式 ['db_name'=>1|['table_name',...],....]
    public $dbMap = []; //库名映射
    protected $useDbName = ''; //使用的库名
    protected $currTable = '';
    protected $dataDir = '';
    protected $cache = null;
    protected $chain_id = 0;

	public function __construct($config)
	{
        require_once(HOME . '/config/conf.php');
        require_once(HOME . '/vendor/myphps/myphp/base.php');

        $this->chain_id = $cfg['chain_id']??load_config("app")['slave_server_id'];
        $this->db = db();
        $this->allowDbTable = $config['allow_db_table']??[];
        $this->dbMap = $config['db_map']??[];
        $this->useDbName = '';
        $this->dataDir = HOME."/cache";
        $this->cache = new File(HOME."/cache");

        $this->initDb();
        $this->init();
    }
    public static function curl($chain_id, $url, $params=''){
        $k = md5(sprintf("%s+%s", 'chain_id='.$chain_id, md5('rMRVa&UkF32FjQlF_%_'.($chain_id<<6))));
        $json = \Http::doGet(GetC('api_url').$url.'?chain_id='.$chain_id.'&k='.$k.($params?'&'.$params:''), 10, '*/*');
        if ($json === false) {
            throw new \Exception('数据获取失败');
        }
        $res = json_decode($json, true);
        if (!$res) {
            throw new \Exception('数据json解析失败:' . $json);
        }
        if(!isset($res['data']) && !array_key_exists('data', $res)){
            throw new \Exception(isset($res['message'])?$res['message']:'数据请求失败');
        }
        if($res['code']!=0){
            throw new \Exception($res['msg']);
        }
        return $res;
    }
	protected function init(){
        try{
            $res = self::curl($this->chain_id, '/merchant/chain-list');
            $this->currTable = 'merchant';
            foreach ($res['data'] as $v){
                $this->_update($v, $v);
            }
        }catch (\Exception $e){
            wing_log('init-fail', $e->getMessage());
            \Log::Exception($e, false);
            exit(1);
        }
    }

    //初始库
    protected function initDb(){
	    $dbLock = HOME . '/dbLock'; //防重复运行
        if(is_file($dbLock)){
            if(file_get_contents($dbLock)==0){
                echo 'Please import initial data!',PHP_EOL;
                exit(0);
            }else{
                return;
            }
        }

        $dbList = ['yx','yxgoods','yxchain'];
        foreach ($dbList as $dbName){
            $createSql = 'CREATE DATABASE IF NOT EXISTS '.$dbName.' DEFAULT CHARACTER SET utf8;';
            $this->db->execute($createSql);

            $this->db->execute('use '.$dbName);
            //初始库.表
            if(is_file(HOME.'/config/'.$dbName.'.sql')){
                $this->db->execute(file_get_contents(HOME.'/config/'.$dbName.'.sql'));
            }
        }
        file_put_contents($dbLock, 0);
        $this->db->execute('use yx');
        $this->init();

        $merchants = $this->db->query('select id from merchant', true);
        $mchIds = implode(',', array_column($merchants, 'id'));
        $sql = '#!/bin/bash'.PHP_EOL;
        foreach ($this->allowDbTable as $dbName=>$tables){
            foreach ($tables as $table){
                if($table=='merchant') continue;
                if($table=='user') continue;

                if(in_array($table, ['chain_user','chain_mchuser','user_info'])){
                    $sql .= "mysqldump -uroot -pd79f03f02ad4dece --skip-opt --no-create-info=TRUE --where='chain_id={$this->chain_id}' {$dbName}_tm {$table}-".($this->chain_id%10).">{$dbName}.{$table}.sql;".PHP_EOL;
                }else{
                    $sql .= "mysqldump -uroot -pd79f03f02ad4dece --skip-opt --no-create-info=TRUE --where='mch_id in({$mchIds})' {$dbName}_tm {$table}-".($this->chain_id%10).">{$dbName}.{$table}.sql;".PHP_EOL;
                }
            }
        }
        file_put_contents(HOME . '/sql_dump_cmd.sh', $sql.PHP_EOL. "tar -czvf sql_dump.tar.gz  ./*.sql". PHP_EOL.'exit 0'.PHP_EOL);
        echo 'db init ok!',PHP_EOL;
        exit(0);
    }

    //连锁判断
    public function checkChainId(&$data){
        static $chainMap = [];
        if($this->currTable=='merchant') {
            return $data['chain_id']==$this->chain_id;
        }
        if($this->currTable=='user'){
            $chain_id = (int)db('db2')->getCustomId('yxchain.chain_user', 'chain_id', 'uid='.$data['id']);
            return $chain_id==$this->chain_id;
        }

        if(isset($data['chain_id'])) return $data['chain_id']; //有连锁id的直接返回

        $mch_id = $data['mch_id']??0;

        if(isset($chainMap[$mch_id])) return $chainMap[$mch_id];

        $chain_id = (int)$this->db->getCustomId(GetC('db.name').'.merchant', 'chain_id', 'id='.$mch_id);
        $chainMap[$mch_id] = $chain_id==$this->chain_id;

        return $chainMap[$mch_id];
    }

	public function onchange($result)
	{
        try{
            if($result['event']=='query'){
                return;
            }
            $db_name = str_replace('_'.$this->chain_id,'',$result['dbname']);
            //库检查
            if(!isset($this->allowDbTable[$db_name])){
                return;
            }
            //表检测
            $this->currTable = $result['table']??'';
            if(is_array($this->allowDbTable[$db_name]) && !in_array($this->currTable, $this->allowDbTable[$db_name])){
                return;
            }
            //切换库
            if($this->useDbName!=$db_name){
                $dbName = isset($this->dbMap[$db_name]) ? $this->dbMap[$db_name] : $db_name;
                $this->useDbName = $db_name;

                $this->db->db->config['name'] = $dbName; //防止重连时丢失选择库
                $this->db->execute('use '.$dbName);
            }

            switch ($result['event']){
                case 'write_rows':
                    if(!$this->checkChainId($result['data'])){
                        return;
                    }
                    $this->_write($result['data']);
                    break;
                case 'update_rows':
                    if(!$this->checkChainId($result['data']['new'])){
                        return;
                    }
                    $this->_update($result['data']['new'], $result['data']['old']);
                    break;
                case 'delete_rows':
                    if(!$this->checkChainId($result['data'])){
                        return;
                    }
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
                file_put_contents($this->dataDir.'/fail_data', date("Y-m-d H:i:s ").json_encode($result)."\n", FILE_APPEND);
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