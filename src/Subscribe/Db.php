<?php namespace Wing\Subscribe;

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

	public function __construct($config)
	{
        if(!empty($config['conf'])) require($config['conf']);
        require(HOME . '/vendor/myphps/myphp/base.php');

        $this->db = db();
        $this->allowDbTable = $config['allow_db_table']??[];
        $this->dbMap = $config['db_map']??[];
        $this->lastDbName = '';
	}
	public function tableName($table, $shardId){
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
	}
	protected function _write($data){
        $table = $this->tableName($this->currTable, $data['mch_id']);
        $model = new \Model($table);
        $model->setData($data);
        try{
            if($model->save(null, null, 0)===false){
                throw new \Exception(\myphp::err());
            }
        }catch (\Exception $e){
            \Log::Exception($e, false);
            \Log::write($e->getMessage(), 'write');
            \Log::write($this->currTable, 'write_tb');
            \Log::write($data, 'write_data');
        }
    }
	protected function _update($data, $old){
        $oldTable = $this->tableName($this->currTable, $old['mch_id']);
        $table = $this->tableName($this->currTable, $data['mch_id']);
        try{
            if($oldTable!=$table){ //分片表不一致 清除原分片旧数据
                $this->db->del($oldTable, ['id'=>$old['id']]);
            }

            $model = new \Model($table);
            $model->where(['id'=>$old['id']])->find();
            $model->setData($data);
            if($model->save(null, null, 0)===false){
                throw new \Exception(\myphp::err());
            }
        }catch (\Exception $e){
            \Log::Exception($e, false);
            \Log::write($e->getMessage(), 'update');
            \Log::write($this->currTable, 'update_tb');
            \Log::write($old, 'update_old');
            \Log::write($data, 'update_data');
        }
    }
	protected function _delete($data){
        $table = $this->tableName($this->currTable, $data['mch_id']);

        try{
            // order 未支付的支持删除
            if($this->currTable=='order' && $data['pay_time']==0){
                $this->db->del($table, ['id'=>$data['id']]);
            }
            // mch_order 未支付的支持删除 #mch_order与mch_ordermx做关联删除处理
            if($this->currTable=='mch_order' && $data['pay_time']==0){
                $this->db->del($table, ['id'=>$data['id']]);

                $this->db->del($this->tableName('mch_ordermx', $data['mch_id']), ['mch_id'=>$data['mch_id'],'o_id'=>$data['_id']]);
            }
        }catch (\Exception $e){
            \Log::write($e->getMessage(), 'delete');
            \Log::write($this->currTable, 'delete_tb');
            \Log::write($data, 'delete_data');
        }
    }
}