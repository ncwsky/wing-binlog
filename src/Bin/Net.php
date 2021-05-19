<?php
namespace Wing\Bin;

/**
 * tcp SOCK_STREAM连接类
 * @package Wing\Bin
 */
class Net
{
    protected $socket = null;
    /**
     * @var self
     */
	protected static $instance = null;

    /** 初始连接
     * @param string $host
     * @param int $port
     * @return false|resource|null
     * @throws \Exception
     */
	public static function initConnect(string $host, int $port){
        if(self::$instance){
            self::$instance->free();
        }
        self::$instance = new self($host, $port);
        return self::$instance->socket;
    }

    /**
     * @param $data
     * @return bool
     * @throws NetException
     */
    public static function send($data)
    {
        return self::$instance->write($data);
    }

    /**
     * @param $length
     * @return string
     * @throws NetException
     */
    public static function readBytes($length)
    {
        return self::$instance->read($length);
    }

    public static function close(){
        if(self::$instance){
            self::$instance->free();
        }
    }

    public function isConnected(): bool
    {
        return is_resource($this->socket);
    }
    //释放
    public function free(){
        if ($this->isConnected()) {
            #@socket_shutdown($this->socket);
            @socket_close($this->socket);
        }
    }
    public function __destruct()
    {
        $this->free();
    }
    /**
     * Net constructor.
     * @param string $host
     * @param int $port
     * @throws \Exception
     */
    public function __construct(string $host, int $port)
    {
        $this->socket = socket_create(AF_INET, SOCK_STREAM, SOL_TCP);
        if (!$this->socket) {
            throw new \Exception('Unable to create socket:' . socket_strerror(socket_last_error()), socket_last_error());
        }
        socket_set_block($this->socket);
        socket_set_option($this->socket, SOL_SOCKET, SO_KEEPALIVE, 1);

        if (!socket_connect($this->socket, $host, $port)) {
            throw new \Exception(socket_strerror(socket_last_error()), socket_last_error());
        }
    }
    /**
     * 发送数据
     *
     * @param string $data
     * @throws NetException
     * @return bool
     */
    public function write($data)
    {
        $len = strlen($data);
        if (($bytes = socket_write($this->socket, $data, $len)) === false) {
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
    public function read($length)
    {
        $received = socket_recv($this->socket, $buf, $length, MSG_WAITALL);
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