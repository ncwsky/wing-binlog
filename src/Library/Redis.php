<?php namespace Wing\Library;
/**
 * Redis.php
 * User: huangxiaoan
 * Created: 2017/8/7 16:06
 * Email: huangxiaoan@xunlei.com
 */
class Redis
{
	private $user;
	private $host;
	private $password   = null;

	private $is_connect = false;
	private $instance   = null;

	public function __construct($host, $user, $password = null)
	{
		$this->user = $user;
		$this->host = $host;
		$this->password = $password;
	}

	protected function connect()
	{
		try {
			$this->instance = null;
			$this->is_connect = false;
			if (!class_exists("Redis")) {
				return;
			}
			$redis = new \Redis();
			$this->is_connect = $redis->connect('127.0.0.1', 6379);
			if (!$this->is_connect) {
				return;
			}

			if ($this->password !== null) {
				$this->is_connect = $redis->auth($this->password);
				if (!$this->is_connect) {
					return;
				}
			}

			$this->is_connect = true;
			$this->instance = $redis;

		} catch (\Exception $e) {
			$this->is_connect = false;
			var_dump($e->getMessage());
		}
	}

	public function __call($name, $arguments)
	{
		if (!$this->is_connect) {
			$this->connect();
		}
		try {
			if (!$this->instance || !$this->is_connect) {
				return false;
			}

			return call_user_func_array([$this->instance, $name], $arguments);
		} catch (\Exception $e) {
			var_dump($e->getMessage());
			$this->is_connect = false;
		}
		return false;
	}
}