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
        $this->chain_id = $this->currTable=='merchant'? $data['chain_id'] : $data['mch_id']??0;
        if(isset($chainMap[$this->chain_id])) return $chainMap[$this->chain_id];

        //查询是否是tm
        $type = (int)db('db2', true)->getCustomId('merchant', 'type', 'id='.$this->chain_id);
        $chainMap[$this->chain_id] = $type==99;
        return $chainMap[$this->chain_id];
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