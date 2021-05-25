<?php namespace Wing\Bin;
use Wing\Bin\Constant\CapabilityFlag;

/**
 * Auth.php
 * User: huangxiaoan
 * Created: 2017/9/11 18:26
 *
 * 连接mysql，完成认证
 */
class Auth
{
    /** 连接mysql 认证连接 初始化Net::$socket
     * @param $host
     * @param $user
     * @param $password
     * @param $db
     * @param $port
     * @param int $rec_time_out
     * @param int $send_time_out
     * @return array|null
     * @throws \Exception
     */
    public static function execute($host, $user, $password, $db, $port, $rec_time_out=0, $send_time_out=0)
    {
        //初始连接
        $socket = Net::initConnect($host, $port, $rec_time_out, $send_time_out);

        // mysql认证
        // 1、socket连接服务器
        // 2、读取服务器返回的信息，关键是获取到加盐信息，用于后面生成加密的password
        // 3、生成auth协议包
        // 4、发送协议包，认证完成

        // 获取server信息 加密salt
        $pack   	 = Packet::readPacket();
        $server_info = ServerInfo::parse($pack);

        //希望的服务器权能信息
        $flag = CapabilityFlag::DEFAULT_CAPABILITIES;
        if ($db) {
            $flag |= CapabilityFlag::CLIENT_CONNECT_WITH_DB;
        }
        /**
        clientFlags := clientProtocol41 |
        clientSecureConn |
        clientLongPassword |
        clientTransactions |
        clientLocalFiles |
        clientPluginAuth |
        clientMultiResults |
        mc.flags&clientLongFlag
         * if mc.cfg.ClientFoundRows {
        clientFlags |= clientFoundRows
        }

        // To enable TLS / SSL
        if mc.cfg.tls != nil {
        clientFlags |= clientSSL
        }

        if mc.cfg.MultiStatements {
        clientFlags |= clientMultiStatements
        }*/

        //认证
        $data = Packet::getAuth($flag, $user, $password, $server_info->salt,  $db);
        if (!Net::send($data)) {
            return null;
        }

        Packet::readPacket(true); #// 认证是否成功 Packet::success(Packet::readPacket());

        return [$socket, $server_info];
    }

	/**
	 * 释放socket资源，关闭socket连接
	 */
	public static function free()
	{
		Net::close();
	}
}