<?php
/**
 * Created by PhpStorm.
 * User: yuyi
 * Date: 17/3/31
 * Time: 11:02
 */
define("__APP_DIR__", dirname(__DIR__));
include __DIR__."/../vendor/autoload.php";

$command = new \Seals\Library\Command("ps aux | grep /usr/local/mysql/bin/mysqld");
$res     = $command->run();

$temp = explode("\n", $res);
var_dump($temp);

echo $res,"\r\n";