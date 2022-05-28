<?php namespace Wing\Subscribe;

use Wing\Library\ISubscribe;

/**
 * Created by PhpStorm.
 * User: yuyi
 * Date: 17/8/4
 * Time: 22:58
 *
 * @property \Redis $redis
 */
class Redis implements ISubscribe
{
    private $redis;
    private $queue;
    public $allowDbTable = []; // 格式 ['db_name'=>1|['table_name',...],....]

	public function __construct($config)
	{
        $host     = $config["host"];
        $port     = $config["port"];
        $password = $config["password"];
        $queue    = $config["queue"];
        $this->allowDbTable = $config['allow_db_table']??[];

        $this->redis = new \Wing\Library\lib_redis([
            'host' => $host,
            'port' => $port,
            'password' => $password,
        ]);
        $this->queue = $queue;
	}

	public function onchange($result)
	{
        //库检查
        if(!isset($this->allowDbTable[$result['dbname']])){
            return;
        }
        //表检测
        $table = $result['table']??'';
        if(is_array($this->allowDbTable[$result['dbname']]) && !in_array($table, $this->allowDbTable[$result['dbname']])){
            return;
        }

        #if($result['event']=='query') return;
        $this->redis->rpush($this->queue, json_encode($result));
        $len = $this->redis->llen($this->queue);
        if ($len > 200) { //test
            $this->redis->ltrim($this->queue, -100, -1);
        }
	}
}