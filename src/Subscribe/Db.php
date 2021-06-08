<?php namespace Wing\Subscribe;

use Wing\Cache\File;
use Wing\Library\ISubscribe;

/**
 * 使用数据库写数据
 * @package Wing\Subscribe
 */
class Db implements ISubscribe
{
    public $db;
    public $allowDbTable = []; // 格式 ['db_name'=>1|['table_name',...],....]
    public $dbMap = []; //库名映射
    protected $useDbName = ''; //使用的库名
    protected $currTable = '';
    protected $dataDir = '';
    protected $cache = null;

	public function __construct($config)
	{
        require_once(HOME . '/config/conf.php');
        require_once(HOME . '/vendor/myphps/myphp/base.php');

        $this->db = db();
        $this->allowDbTable = $config['allow_db_table']??[];
        $this->dbMap = $config['db_map']??[];
        $this->useDbName = '';
        $this->dataDir = HOME."/cache";
        $this->cache = new File(HOME."/cache");
	}
	public function tableName($table, $shardId){
	    if($table=='merchant') return $table;

        return $table.'-'.($shardId%10);
    }
    //以连锁id为分片依据
    public function getShardId(&$data){
        static $chainMap = [];
        if($this->currTable=='merchant') return 9999; //商户表不分片处理
        if($this->currTable=='user'){
            $chain_id = 0;
            if(strpos($data['ext'],'chain_id')){
                $chain_id = intval(json_decode($data['ext'], true)['chain_id']??0);
            }
            $chain_id===0 && $chain_id = (int)db('db2')->getCustomId('yxchain.chain_user', 'chain_id', 'uid='.$data['id']);
            return $chain_id;
        }

        if(isset($data['chain_id'])) return $data['chain_id']; //有连锁id的直接返回

        $mch_id = $data['mch_id'];

        if(isset($chainMap[$mch_id])) return $chainMap[$mch_id];

        $chain_id = (int)$this->db->getCustomId('yx_tm.merchant', 'chain_id', 'id='.$mch_id);
        $type = (int)$this->db->getCustomId('yx_tm.merchant', 'type', 'id='.$chain_id);
        if($type!=99) $chain_id = 0; //不是tm

        $chainMap[$mch_id] = $chain_id;
        return $chain_id;
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
            $this->currTable = $table = $result['table']??'';
            if(is_array($this->allowDbTable[$result['dbname']]) && !in_array($table, $this->allowDbTable[$result['dbname']])){
                return;
            }
            //切换库
            if($this->useDbName!=$result['dbname']){
                $dbName = isset($this->dbMap[$result['dbname']]) ? $this->dbMap[$result['dbname']] : $result['dbname'];
                $this->db->db->config['name'] = $dbName; //防止重连时丢失选择库

                $this->db->execute('use '.$dbName);
                $this->useDbName = $result['dbname'];
            }
            /*
            if($result['event']=='query'){
                \Log::write($result, 'query');
                $this->db->execute($result['data']);
                return;
            }
            */

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
        $shardId = $this->getShardId($data);
        if($shardId==0) return;

        $table = $this->tableName($this->currTable, $shardId);
        $model = new \Model($table);
        $model->setData($data);
        if($model->save(null, null, 0)===false){
            throw new \Exception(\myphp::err());
        }
    }
	protected function _update($data, $old){
        $shardId = $this->getShardId($data);
        if($shardId==0) return;

        $oldTable = $this->tableName($this->currTable, $this->getShardId($old));
        $table = $this->tableName($this->currTable, $shardId);
        if($oldTable!=$table){ //分片表不一致 清除原分片旧数据
            $this->db->del($oldTable, ['id'=>$old['id']]);
        }

        $model = new \Model($table);
        $model->where(['id'=>$old['id']])->find();
        $model->setData($data);
        if($model->save(null, null, 0)===false){
            throw new \Exception(\myphp::err());
        }
    }
	protected function _delete($data){
        $shardId = $this->getShardId($data);
        if($shardId==0) return;

        if($this->currTable=='merchant'){
            $this->db->del($this->currTable, ['id'=>$data['id']]);
            return;
        }

        $table = $this->tableName($this->currTable, $shardId);
        
        if($this->currTable=='user' || $this->currTable=='mchuser'){
            $this->db->del($table, ['id'=>$data['id']]);
            return;
        }

        $day30 = strtotime('-1 month');
        // order 未支付的支持删除
        if($this->currTable=='order' && $data['pay_time']==0 && $day30>$data['ctime']){
            $this->db->del($table, ['id'=>$data['id']]);
            return;
        }
        // mch_order 未支付的支持删除 #mch_order与mch_ordermx做关联删除处理
        if($this->currTable=='mch_order' && $data['pay_time']==0 && $day30>$data['ctime']){
            $this->db->del($table, ['id'=>$data['id']]);

            $this->db->del($this->tableName('mch_ordermx', $shardId), ['mch_id'=>$data['mch_id'],'o_id'=>$data['_id']]);
        }
    }
}