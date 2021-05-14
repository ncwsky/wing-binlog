<?php
namespace Wing\Bin;

/**
 * Net.php
 * User: huangxiaoan
 * Created: 2017/9/13 17:09
 * Email: huangxiaoan@xunlei.com
 */
class Net
{
	public static $socket = null;

	public $sockets = null;
    public function __destruct()
    {
        if (is_resource($this->sockets)) {
            socket_shutdown($this->sockets);
            socket_close($this->sockets);
        }
    }

    public function connectToStream(string $host, int $port)
    {
        $this->sockets = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!$this->sockets) {
            throw new \Exception('Unable to create socket:' . $this->getSocketErrorMessage(), $this->getSocketErrorCode());
        }
        socket_set_block($this->sockets);
        socket_set_option($this->sockets, SOL_SOCKET, SO_KEEPALIVE, 1);

        if (!socket_connect($this->sockets, $host, $port)) {
            throw new \Exception($this->getSocketErrorMessage(), $this->getSocketErrorCode());
        }
    }

    private function getSocketErrorMessage()
    {
        return socket_strerror($this->getSocketErrorCode());
    }

    private function getSocketErrorCode()
    {
        return socket_last_error();
    }
	/**
	 * 发送数据
	 *
	 * @param string $data
	 * @throws NetException
	 * @return bool
	 */
	public static function send($data)
	{
	    $len = strlen($data);
		if (($bytes = socket_write(self::$socket, $data, $len)) === false) {
			$error_code = socket_last_error();
			throw new NetException(sprintf( "Unable to write to socket: %s", socket_strerror($error_code)), $error_code);
		}
		return $bytes === $len;
	}

    /**
     * 读取指定的字节数量的数据
     * @param $length
     * @throws NetException
     * @return string
     */
	public static function readBytes($length)
	{
        $received = socket_recv(self::$socket, $buf, $length, MSG_WAITALL);
        if ($length === $received) {
            return $buf;
        }

        $error_code = socket_last_error();
        $error_msg = socket_strerror($error_code);
        // https://www.php.net/manual/zh/function.socket-recv.php
        if (0 === $received) { // socket closed
            throw new NetException('Disconnected by remote side. error:'.$error_msg, $error_code);
        }
        // false  no data available, socket not closed
        throw new NetException($error_msg, $error_code);
	}
}