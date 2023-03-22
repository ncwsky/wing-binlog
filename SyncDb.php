<?php

use Wing\Cache\File;
use Wing\Library\ISubscribe;

/**
 * 使用数据库写数据
 */
class SyncDb implements ISubscribe
{
    public $allowDbTable = []; // 格式 ['db_name'=>1|['table_name',...],....]
    public $chain_id = 0;
    private $db_name = ''; //使用的库名
    private $local_db_name = ''; //使用的库名
    private $table_name = '';
    private $dataDir = '';
    private $cache = null;
    private $sync_table_conf = [];

    public function __construct($params=[])
    {
        if (file_exists(HOME . '/../conf.local.php')) {
            require_once(HOME . '/../conf.local.php');
        } else {
            require_once(HOME . '/../conf.php');
        }
        $cfg['log_dir'] = LOG_DIR; //重置日志目录

        require_once(HOME . '/../vendor/myphps/myphp/base.php');

        if (!empty($params['db_table'])) {
            $this->allowDbTable = $params['db_table'];
        }
        $this->chain_id = (int)load_config(WING_CONFIG)['slave_server_id']??0;
        $this->dataDir = CACHE_DIR;
        $this->cache = new File(CACHE_DIR);
        $this->sync_table_conf = GetC('sync_table_conf', []);
        //db()::log_on(2);
    }

    public function onchange($result)
    {
        try {
            //库检查 $result['dbname']
            //表检测 $result['table']??''
            $this->table_name = $table = $result['table'] ?? '';

            //本地测试使用
            if ($result['dbname'] == 'service') {
                $result['dbname'] = 'yx';
            } elseif ($result['dbname'] == 'local_yx') {
                $result['dbname'] = 'yxgoods';
            }
            //切换库
            if ($this->db_name != $result['dbname']) {
                //db()->conn()->config['name'] = $result['dbname']; //防止重连时丢失选择库

                $this->db_name = $result['dbname'];
                //db()->execute('use '.$this->db_name);
            }
            //限定库表处理
            if ($this->allowDbTable) {
                if (!isset($this->allowDbTable[$this->db_name])) {
                    wing_echo($this->db_name . ' continue');
                    return;
                }
                if ($this->table_name && $this->allowDbTable[$this->db_name] !== 1 && strpos(',' . $this->allowDbTable[$this->db_name] . ',', ',' . $this->table_name . ',') === false) {
                    wing_echo($this->db_name . '.' . $this->table_name . ' continue');
                    return;
                }
            }
            if(!$this->chain_id){
                wing_echo($this->db_name . '.' . $this->table_name . ' 未指定连锁id continue');
                return;
            }
            $this->local_db_name = $this->chain_id.'_'.$this->db_name;
            //echo toJson($result).PHP_EOL; return; //test
            switch ($result['event']) {
                case 'query':
                    if ($result['data'] == 'BEGIN' || $result['data'] == 'COMMIT' || strncmp($result['data'], 'SAVEPOINT', 9) === 0) {
                        break;
                    }
                    db()->execute('use '.$this->local_db_name);
                    \myphp\Log::write($result['data'], 'exec');
                    db()->execute($result['data']);
                    break;
                case 'write_rows':
                    db()->add($result['data'], $this->local_db_name.'.'.$this->table_name);
                    break;
                case 'update_rows':
                    $this->update($result['data']['new'], $result['data']['old']);
                    break;
                case 'delete_rows':
                    $this->delete($result['data']);
                    break;
            }
        } catch (\Exception $e) {
            $hasRepeat = $result['event'] == 'write_rows' && strpos($e->getMessage(), 'Duplicate entry');

            if ($hasRepeat) {
                #\Log::write(db()->getSql(), 'Duplicate');
                #error_log(date("Y-m-d H:i:s ").json_encode($result)."\n", 3, $this->dataDir.'/repeat_data');
            } else {
                \myphp\Log::write($this->table_name, 'table');
                \myphp\Log::write($result['data'], 'data');
                \myphp\Log::Exception($e, false);

                //发送通知
                $appConfig = load_config(WING_CONFIG);
                if (!empty($appConfig['warn_notice_url'])) {
                    if (!$this->cache->get('warn-notice')) { #x分钟内只发一次
                        $this->cache->set('warn-notice', date("Y-m-d H:i:s"), 30 * 60);

                        $ret = \Http::doPost($appConfig['warn_notice_url'], ['title' => 'binglog错误预警', 'msg' => $e->getMessage()]);
                        \myphp\Log::write($ret, 'curl');
                    }
                }

                //$result 缓存下来用于修复处理
                error_log(date("Y-m-d H:i:s ") . json_encode($result) . "\n", 3, $this->dataDir . '/fail_data');
            }
        }
    }

    protected function update($data, $old)
    {
        $map = [];
        if (!empty($data['id'])) { //优先主键id
            $map = ['id' => $data['id']];
        } elseif (isset($this->sync_table_conf[$this->db_name][$this->table_name]['unique'])) { //唯一键
            foreach ($this->sync_table_conf[$this->db_name][$this->table_name]['unique'] as $field) {
                $map[$field] = $data[$field];
            }
        }
        if (!$map) { //未匹配更新条件使用所有旧数据做为条件
            $map = $old;
        }
        foreach ($data as $k => $v) {
            if ($old[$k] === $v) {
                unset($data[$k]);
            }
        }
        db()->update($data, $this->local_db_name . "." . $this->table_name, $map);
    }

    protected function delete($data)
    {
        if (!empty($data['id'])) { //优先主键id
            db()->execute("DELETE FROM `" . $this->local_db_name . "`.`" . $this->table_name . "` WHERE id=" . $data['id']);
        } else { //唯一键
            $map = [];
            if (isset($this->sync_table_conf[$this->db_name][$this->table_name]['unique'])) {
                foreach ($this->sync_table_conf[$this->db_name][$this->table_name]['unique'] as $field) {
                    $map[$field] = $data[$field];
                }
            }
            if (!$map) { //未匹配删除条件使用所有数据做为条件
                $map = $data;
            }
            db()->del($this->local_db_name . "." . $this->table_name, $map);
        }
    }
}