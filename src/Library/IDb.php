<?php
namespace Wing\Library;

/**
 * Created by PhpStorm.
 * User: yuyi
 * Date: 17/2/7
 * Time: 18:22
 * 数据库pdo实现接口
 */
interface IDb
{
    public function query($sql);
    public function getTables();
    public function row($sql);
}
