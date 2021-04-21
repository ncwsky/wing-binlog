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

	/**
	 * 发送数据
	 *
	 * @param string $data
	 * @throws \RuntimeException
	 * @return bool
	 */
	public static function send($data)
	{
	    $len = strlen($data);
		if (($bytes = socket_write(self::$socket, $data, $len)) === false) {
			$error_code = socket_last_error();
			throw new \RuntimeException(sprintf( "Unable to write to socket: %s", socket_strerror($error_code)), $error_code);
		}
		return $bytes === $len;
	}

    /**
     * 读取指定的字节数量的数据
     * @param $data_len
     * @return string
     */
	public static function readBytes($data_len)
	{
		$bytes_read = 0;
		$body       = '';

		while ($bytes_read < $data_len) {
			$resp = socket_read(self::$socket, $data_len - $bytes_read);

			if ($resp === false) {
				throw new \RuntimeException(
					sprintf(
						'remote host has closed. error:%s, msg:%s',
						socket_last_error(),
						socket_strerror(socket_last_error())
					));
			}

			// server kill connection or server gone away
			if ($resp === '') {
				throw new \RuntimeException("read less " . ($data_len - $bytes_read));
			}

			$body .= $resp;
			$bytes_read += strlen($resp);
		}

		return $body;
	}

    /**
     * 读取数据包主体内容
     * @return string
     */
	public static function readPacket()
	{
		//消息头 包数据长度<3>+包序列id<1>
		$header = self::readBytes(4);
		//消息体长度3bytes 小端序
		$unpack_data = unpack("V",$header[0].$header[1].$header[2].chr(0))[1];
		//包序列id
		//$sequence_id =  unpack("C",$header[3])[1];

        #echo 'pack_len:'.$unpack_data,PHP_EOL;

		return self::readBytes($unpack_data);
	}
}