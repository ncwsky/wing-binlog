<?php namespace Wing\Subscribe;

use Wing\Cache\File;
use Wing\Library\ISubscribe;

/**
 * Class TmSync
 * @package Wing\Subscribe
 */
class TmSync implements ISubscribe
{
    private $redis;
    private $queue;

    /**
     * @var string $table_name 数据表名称
     */
    protected $table_name;
    protected $time;
    protected $writeTable = ['mch_nodes','module','module_relate'];
    protected $writeSysTable = ['conf','voice','goods'];
    protected $deleteTable = ['mch_nodes','module'];

    public function __construct($config)
    {
        $host = $config["host"];
        $port = $config["port"];
        $password = $config["password"];
        $queue = $config["queue"];

        $this->redis = new \Wing\Library\lib_redis([
            'host' => $host,
            'port' => $port,
            'password' => $password,
            'select'=> $config["select"]??0
        ]);
        $this->queue = $queue;
        $this->time = time();
    }

    public function onchange($result)
    {
        $time = time();
        if (($this->time + 10) < $time) {
            $this->redis->ping();
            $this->time = $time;
        }
        //表检测
        $this->table_name = $table = $result['table'] ?? '';
        try{
            $sql = '';
            switch ($result['event']){
                case 'write_rows':
                    $sql = $this->_write($result['data']);
                    break;
                case 'update_rows':
                    $sql = $this->_update($result['data']['new'], $result['data']['old']);
                    break;
                case 'delete_rows':
                    $sql = $this->_delete($result['data']);
                    break;
                case 'query':
                    if($result['data']=='BEGIN' || $result['data']=='COMMIT' || strncmp($result['data'], 'SAVEPOINT', 9)===0) break;
                    $sql = $result['data'];
                    break;
            }
            $sql!=='' && $this->redis->rpush($this->queue, $sql); //json_encode(['event' => $result['event'], 'dbname'=>$result['dbname'], 'table'=>$table, 'sql' => $sql])
        }catch (\Exception $e){
            wing_log('error', $e->getMessage()."\n".'line:'.$e->getLine().', file:'.$e->getFile()."\n".$e->getTraceAsString());
        }
    }

    private $startSpec = '`';
    protected $endSpec = '`';
    protected function quote($val){
        //$val = addslashes($val);
        $val = "'".str_replace("'", "''", $val)."'";
        return $val;
    }
    protected function parseValue($val) {
        if(is_string($val)) {
            $val = $this->quote($val);
        }
        elseif(is_array($val)){
            $val = array_map(array($this, 'parseValue'), $val);
        }
        elseif(is_null($val)){
            $val = 'null';
        }
        return $val;
    }
    protected function makeWhere($case){
        $where = '';
        if(is_array($case)){ //数组组合条件
            foreach($case as $k=>$v){
                if(is_int($k)){ // '1=1'
                    $field = $v;
                }else{  // ['a'=>1] || ['a::like'=>'%s%']
                    $operator = '=';
                    if ($pos = strpos($k, '::')) {
                        $operator = trim(substr($k, $pos + 2));
                        $operator = $operator == '' ? '=' : ' ' . $operator . ' ';
                        $k = substr($k, 0, $pos);
                    }elseif(is_array($v)){
                        $operator = ' in ';
                    }
                    switch ($operator){
                        case ' between ':
                        case ' not between ':
                            $v = is_array($v) ? $v : explode(',', $v);
                            $v = $this->parseValue($v);
                            $field = $k . $operator . $v[0] . ' and ' . $v[1];
                            break;
                        case ' in ':
                        case ' not in ':
                            $field = $k . $operator . '(' . (is_array($v) ? implode(',', $this->parseValue($v)) : $v) . ')';
                            break;
                        case ' exists ':
                        case ' not exists ':
                            $field = $k . $operator . '(' . $v . ')';
                            break;
                        default:
                            $field = $k . $operator . $this->parseValue($v);
                            break;
                    }
                }
                $where .= $where == '' ? $field : ' and ' . $field;
            }
        }elseif(is_string($case)){ //参数绑定方式条件
            $where = $case;
        }
        return $where;
    }

    protected function add_sql($table, $data){
        $field = '';
        $value = '';
        foreach ($data as $k => $v) {
            $field .= ',' . $this->startSpec . $k . $this->endSpec;
            //val值得预先过滤处理
            $value .= ',' . $this->parseValue($v);
        }
        $field = substr($field, 1);
        $values = '(' . substr($value, 1) . ')';

        $sql = 'INSERT INTO ' . $this->startSpec . $table . $this->endSpec . '(' . $field . ') VALUES ' . $values;
        return $sql;//返回执行sql
    }

    //更新记录 $where[str|arr]
    protected function update_sql($table, $post, $where = '')
    {
        $value = '';
        foreach ($post as $k => $v) {
            //val值得预先过滤处理
            $value .= $this->startSpec . $k . $this->endSpec . ' = ' . $this->parseValue($v) . ',';
        }
        $value = substr($value, 0, -1);

        $sql = 'UPDATE ' . $this->startSpec . $table . $this->endSpec . ' SET ' . $value;

        if ($where != '') $sql .= ' WHERE ' . $this->makeWhere($where);

        return $sql;
    }

    protected function _write($data)
    {
        $sql = '';
        if (in_array($this->table_name, $this->writeTable)) {
            $sql = $this->add_sql($this->table_name, $data);
        } elseif (in_array($this->table_name, $this->writeSysTable) && $data['mch_id'] == 0) {
            $sql = $this->add_sql($this->table_name, $data);
        }
        return $sql;
    }

    protected function _update($data, $old)
    {
        $sql = '';
        if (in_array($this->table_name, $this->writeTable) || (in_array($this->table_name, $this->writeSysTable) && $data['mch_id'] == 0)) {
            $id = 0;
            foreach ($data as $k=>$v){
                if($k=='id') {
                    $id = $v;
                }
                if($old[$k]===$v){
                    unset($data[$k]);
                }
            }
            $sql = $this->update_sql($this->table_name, $data, ['id' => $id]);
        }
        return $sql;
    }

    protected function _delete($data)
    {
        $sql = '';
        if (!empty($data['id']) && in_array($this->table_name, $this->deleteTable)) {
            $sql = "DELETE FROM {$this->table_name} WHERE id={$data['id']}";
        }
        return $sql;
    }
}