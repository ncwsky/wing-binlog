<?php

namespace Wing;

use myphp\Db;
use Wing\Library\ISubscribe;

/**
 * 使用数据库写数据
 */
class SyncDb implements ISubscribe
{
    public $allowDbTable = []; //允许库、表 格式 ['db_name'=>1|['table_name',...],....]
    public $exclude_table = [];//【优先】排除库、表 格式 ['db_name'=>1|'table_name,...',....]
    public $slave_id = 0;
    private $db_name = ''; //使用的库名
    private $local_db_name = ''; //使用的库名
    private $table_name = '';
    private $cache;
    private $sync_table_conf = []; //表同步配置 主键、唯一键
    /**
     * @var \Closure|null 数据处理前的回调函数 function($result){return $result;}
     */
    public $event_before_call = null;

    public function __construct($params = [])
    {
        if (file_exists(HOME . '/config/conf.local.php')) {
            require_once(HOME . '/config/conf.local.php');
        } else {
            require_once(HOME . '/config/conf.php');
        }
        $cfg['log_dir'] = LOG_DIR; //重置日志目录

        require_once(__DIR__ . '/../vendor/myphps/myphp/base.php');

        if (!empty($params['db_table'])) {
            $this->allowDbTable = $params['db_table'];
        }
        $this->slave_id = (int)load_config(WING_CONFIG)['slave_server_id'] ?? 0;
        $this->cache = new \myphp\cache\File(['path'=>CACHE_DIR]);
        if (file_exists(HOME . '/config/sync_table_conf.json')) {
            $this->sync_table_conf = json_decode(file_get_contents(HOME . '/config/sync_table_conf.json'), true);
            //Log::write($this->sync_table_conf, 'sync_table_conf');
        } else {
            $this->sync_table_conf = GetC('sync_table_conf', []);
        }
        $this->exclude_table = GetC('exclude_table', []);
        //回调
        if (isset($params['event_before_call'])) {
            $this->event_before_call = $params['event_before_call'];
        } else {
            $this->event_before_call = GetC('event_before_call');
        }
        if ($this->event_before_call && !($this->event_before_call instanceof \Closure)) {
            $this->event_before_call = null;
        }
        Db::$useIdentifier = true; //使用标识符处理字段及条件
        //db()::log_on(2);
    }

    public function onchange($result)
    {
        try {
            //库检查 $result['dbname']
            //表检测 $result['table']??''
            $this->table_name = $result['table'] ?? '';
            /*
            //本地测试使用
            if ($result['dbname'] == 'service') {
                $result['dbname'] = 'yx';
            } elseif ($result['dbname'] == 'local_yx') {
                $result['dbname'] = 'yxgoods';
            }*/
            //切换库
            if ($this->db_name != $result['dbname']) {
                //db()->conn()->config['name'] = $result['dbname']; //防止重连时丢失选择库

                $this->db_name = $result['dbname'];
                //db()->execute('use '.$this->db_name);
            }
            //限定库表处理
            if ($this->exclude_table) {
                if (isset($this->exclude_table[$this->db_name])) { //排除库处理
                    if ($this->exclude_table[$this->db_name] === 1) {
                        wing_echo($this->db_name . ' continue');
                        return true;
                    }
                    if ($this->table_name && strpos(',' . $this->exclude_table[$this->db_name] . ',', ',' . $this->table_name . ',') !== false) {
                        wing_echo($this->db_name . '.' . $this->table_name . ' continue');
                        return true;
                    }
                }
            } elseif ($this->allowDbTable) {
                if (!isset($this->allowDbTable[$this->db_name])) {
                    wing_echo($this->db_name . ' continue');
                    return true;
                }
                if ($this->table_name && $this->allowDbTable[$this->db_name] !== 1 && strpos(',' . $this->allowDbTable[$this->db_name] . ',', ',' . $this->table_name . ',') === false) {
                    wing_echo($this->db_name . '.' . $this->table_name . ' continue');
                    return true;
                }
            }
            $this->local_db_name = $this->db_name;
            //echo toJson($result).PHP_EOL; return; //test
            if ($this->event_before_call) {
                $result = call_user_func($this->event_before_call, $result);
                if ($result === null) {
                    return true;
                }
            }
            switch ($result['event']) {
                case 'query':
                    if ($result['data'] == 'BEGIN' || $result['data'] == 'COMMIT' || strncmp($result['data'], 'SAVEPOINT', 9) === 0) {
                        break;
                    }
                    if ($this->local_db_name) { //切换库
                        db()->execute('use ' . $this->local_db_name);
                        $sql = str_replace('`' . $this->db_name . '`', '`' . $this->local_db_name . '`', $result['data']);
                    } else {
                        $sql = $result['data'];
                    }
                    error_log('-- ' . date("Y-m-d H:i:s ") . "\n" . $sql . "\n", 3, CACHE_DIR . '/exec.sql');
                    //\myphp\Log::write($sql, 'exec');
                    db()->execute($sql);
                    break;
                case 'write_rows':
                    $this->insert($result['data']);
                    //db()->add($result['data'], $this->local_db_name . '.' . $this->table_name);
                    break;
                case 'update_rows':
                    $this->update($result['data']['new'], $result['data']['old']);
                    break;
                case 'delete_rows':
                    $this->delete($result['data']);
                    break;
            }
        } catch (\Exception $e) {
            $hasRepeat = strpos($e->getMessage(), 'Duplicate entry');
            \myphp\Log::write($result, 'result');
            \myphp\Log::WARN($this->db_name . '.' . $this->table_name . ', err:' . substr($e->getMessage(), 0, 255));
            //\myphp\Log::write(db()->getSql(), $hasRepeat ? 'Duplicate' : 'sql');

            //发送通知
            $appConfig = load_config(WING_CONFIG);
            if (!empty($appConfig['warn_notice_url'])) {
                if (!$this->cache->get('warn-notice')) { #1分钟内只发一次
                    $this->cache->set('warn-notice', date("Y-m-d H:i:s"), 60);

                    $ret = \Http::doPost($appConfig['warn_notice_url'], ['title' => 'binglog错误预警', 'msg' => $e->getMessage()]);
                    \myphp\Log::write($ret, 'curl');
                }
            }

            //$result 缓存下来用于修复处理
            if(!$hasRepeat){
                error_log(date("Y-m-d H:i:s ") . toJson($result) . "\n", 3, CACHE_DIR . '/fail_data');
            }

            return false;
        }
        return true;
    }

    protected function _map(&$data)
    {
        $map = [];
        if (isset($this->sync_table_conf[$this->db_name][$this->table_name]['unique'])) { //唯一键
            foreach ($this->sync_table_conf[$this->db_name][$this->table_name]['unique'] as $field) {
                $map[$field] = $data[$field];
            }
        } else {
            $priKey = $this->sync_table_conf[$this->db_name][$this->table_name]['pri_key'] ?? 'id';
            if (isset($data[$priKey])) { //主键id
                $map = [$priKey => $data[$priKey]];
            }
        }
        return $map;
    }

    protected function insert($data)
    {
        $priKey = $this->sync_table_conf[$this->db_name][$this->table_name]['pri_key'] ?? 'id';
        if (isset($data[$priKey])) { //主键id
            $map = [$priKey => $data[$priKey]];
            $find = db()->fields($priKey)->table($this->local_db_name . "." . $this->table_name)->where($map)->one();
            if ($find) { //记录存在不处理
                return;
            }
        }
        if (isset($this->sync_table_conf[$this->db_name][$this->table_name]['unique'])) { //唯一键
            $map = [];
            foreach ($this->sync_table_conf[$this->db_name][$this->table_name]['unique'] as $field) {
                $map[$field] = $data[$field];
            }
            $find = db()->fields(array_keys($map))->table($this->local_db_name . "." . $this->table_name)->where($map)->one();
            if ($find) { //记录存在不处理
                return;
            }
        }
        db()->add($data, $this->local_db_name . '.' . $this->table_name);
    }

    protected function update($data, $old)
    {
        $needUpdate = false;
        $map = [];
        $priKey = $this->sync_table_conf[$this->db_name][$this->table_name]['pri_key'] ?? 'id';
        if (isset($data[$priKey])) { //主键id
            $map = [$priKey => $data[$priKey]];
            $find = db()->fields($priKey)->table($this->local_db_name . "." . $this->table_name)->where($map)->one();
            if ($find) { //记录存在需要更新
                $needUpdate = true;
            }
        }
        if (!$needUpdate && isset($this->sync_table_conf[$this->db_name][$this->table_name]['unique'])) { //唯一键
            $map = [];
            foreach ($this->sync_table_conf[$this->db_name][$this->table_name]['unique'] as $field) {
                $map[$field] = $data[$field];
            }
            $find = db()->fields(array_keys($map))->table($this->local_db_name . "." . $this->table_name)->where($map)->one();
            if ($find) { //记录存在需要更新
                $needUpdate = true;
            }
        }
        if ($needUpdate) {
            foreach ($data as $k => $v) {
                if ($old[$k] === $v) {
                    unset($data[$k]);
                }
            }
            if ($data) {
                db()->update($data, $this->local_db_name . "." . $this->table_name, $map);
            }
        } else { //未匹配更新条件生成新记录
            db()->add($data, $this->local_db_name . '.' . $this->table_name);
        }
    }

    protected function delete($data)
    {
        $map = [];
        $priKey = $this->sync_table_conf[$this->db_name][$this->table_name]['pri_key'] ?? 'id';
        if (isset($data[$priKey])) { //主键id
            $map = [$priKey => $data[$priKey]];
        } elseif (isset($this->sync_table_conf[$this->db_name][$this->table_name]['unique'])) { //唯一键
            foreach ($this->sync_table_conf[$this->db_name][$this->table_name]['unique'] as $field) {
                $map[$field] = $data[$field];
            }
        }
        if (!$map) { //未匹配删除条件使用所有数据做为条件
            $map = $data;
        }
        $ok = db()->del($this->local_db_name . '.' . $this->table_name, $map);
        if (!$ok) {
            //throw new \Exception('无可删除的记录');
        }
    }

    public function recover()
    {
        if (!file_exists(CACHE_DIR . '/fail_data')) {
            return;
        }
        //复制失败数据到执行恢复文件
        $src_fp = fopen(CACHE_DIR . '/fail_data', "r+");
        $fp = fopen(CACHE_DIR . '/fail_data_run', "w+");
        if (flock($src_fp, LOCK_EX)) {
            stream_copy_to_stream($src_fp, $fp);
            ftruncate($src_fp, 0);
            flock($src_fp, LOCK_UN);
            fclose($src_fp);
        } else {
            echo "lock fail", PHP_EOL;
            fclose($src_fp);
            return;
        }

        $coverFile = CACHE_DIR . '/fail_data_run';
        run_time(true);
        $ok = 0;
        $all = 0;
        $fp = fopen($coverFile, "r+");
        while (!feof($fp)) {
            $result = fgets($fp);
            echo $result, PHP_EOL;
            if ($result) {
                $result = json_decode(trim(substr($result, 20)), true);
                if (isset($result['event'])) {
                    $all++;
                    $hasOk = $this->onchange($result); //todo 这里恢复update操作可能覆盖新更新的数据
                    if ($hasOk) {
                        $ok++;
                    }
                    echo $hasOk ? 'ok' : 'fail', PHP_EOL;
                }
            }
        }
        ftruncate($fp, 0);
        fclose($fp);
        $msg = $coverFile . ' all:' . $all . ', ok:' . $ok . ', ' . toByte(memory_get_peak_usage()) . ' -- ' . run_time();
        file_put_contents(LOG_DIR . '/recover_result.log', date("Y-m-d H:i:s ") . $msg . "\r\n", FILE_APPEND);
        echo $msg, PHP_EOL;
    }
}