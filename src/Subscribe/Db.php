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
    protected $lastDbName = ''; //上一次的库名
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
        $this->lastDbName = '';
        $this->dataDir = HOME."/cache/binlog";
        $this->cache = new File(HOME."/cache/binlog");
	}
	public function tableName($table, $shardId){
	    if($table=='merchant') return $table;
        return $table.'-'.($shardId%10);
    }

	public function onchange($result)
	{
	    //库检查
        if(!isset($this->allowDbTable[$result['dbname']])){
            return;
        }
        //切换库
        if($this->lastDbName!=$result['dbname']){
            $dbName = isset($this->dbMap[$result['dbname']]) ? $this->dbMap[$result['dbname']] : $result['dbname'];
            $this->db->execute('use '.$dbName);

            $this->lastDbName = $result['dbname'];
        }
        //表检测
        $this->currTable = $table = $result['table']??'';
        if(is_array($this->allowDbTable[$result['dbname']]) && !in_array($table, $this->allowDbTable[$result['dbname']])){
            return;
        }

/*        if($result['event']=='query'){
            \Log::write($result, 'query');
            $this->db->execute($result['data']);
            return;
        }*/

        try{
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
            error_log(json_encode($result)."\n", 3, $this->dataDir.'/fail_data');
        }
	}
	//以连锁id为分片依据
	protected function shardId($mch_id){
	    static $chainMap = [];
	    if(isset($chainMap[$mch_id])) return $chainMap[$mch_id];

	    $chain_id = (int)$this->db->getCustomId('yx_tm.merchant', 'chain_id', 'id='.$mch_id);
        $chainMap[$mch_id] = $chain_id;
	    return $chain_id;
    }
	protected function _write($data){
        $table = $this->tableName($this->currTable, $data['mch_id']);
        $model = new \Model($table);
        $model->setData($data);
        if($model->save(null, null, 0)===false){
            throw new \Exception(\myphp::err());
        }
    }
	protected function _update($data, $old){
        $oldTable = $this->tableName($this->currTable, $old['mch_id']);
        $table = $this->tableName($this->currTable, $data['mch_id']);
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
        $table = $this->tableName($this->currTable, $data['mch_id']);

        // order 未支付的支持删除
        if($this->currTable=='order' && $data['pay_time']==0){
            $this->db->del($table, ['id'=>$data['id']]);
        }
        // mch_order 未支付的支持删除 #mch_order与mch_ordermx做关联删除处理
        if($this->currTable=='mch_order' && $data['pay_time']==0){
            $this->db->del($table, ['id'=>$data['id']]);

            $this->db->del($this->tableName('mch_ordermx', $data['mch_id']), ['mch_id'=>$data['mch_id'],'o_id'=>$data['_id']]);
        }
    }
}