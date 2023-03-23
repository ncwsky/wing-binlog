<?php
require_once(HOME . '/SyncDb.php');

return  [
    //必须配置：mysqlbinlog 命令，如果无法识别 写绝对路径
    //"mysqlbinlog" => "mysqlbinlog",
    //从库id
    "slave_server_id" => 9999,
    //重连等待时间
    "retry_connect_sleep" => 6,
    //库同步:默认所有>[!排除库]指定库 加上!前缀表示排除的库列表 使用,分隔
    "sync_db" => '!information_schema,mysql,performance_schema,test', // [!]xx,xx,...
    //必须配置：mysql
    "mysql" => [
        "db_name"  => "",
        "host"     => "127.0.0.1",
        "user"     => "slave",
        "password" => "slave",
        "port"     => 3306,
        "rec_time_out"=>0, //接收超时设置 网络断开时接收可能阻塞 0不限制 无此项配置将默认使用binlog心跳值
    ],
    //异常通知地址 post {title,msg}
    //'warn_notice_url'=>'https://xxx.com/warn-notice',
    //以下配置均属于可选订阅 可以任意增加 只需要遵循接口ISubscribe实现即可
    "subscribe" => [
        \SyncDb::class => [
            //'chain_id'=>0,
            //自定义参数
        ],
    ]
];
