<?php namespace Wing\FileSystem;

/**
 * Created by PhpStorm.
 * User: yuyi
 * Date: 16/11/8
 * Time: 08:05
 *
 * 文件操作类
 *
 * @property string $__file_name
 * @property string $file_name 文件名带扩展
 * @property string $ext 扩展
 * @property Wdir $path 文件所在路径
 * @property int $size 文件大小 字节数
 */
class WFile
{

    private $__file_name;
    public $file_name;
    public $ext;
    public $path;
    public $size; //字节数

    /**
     * 构造函数
     *
     * @param string $file_name 文件
     */
    public function __construct($file_name)
    {
        if ($file_name instanceof self) {
            $file_name = $file_name->getFileName();
        }

        $file_name = str_replace("\\","/",$file_name);
        $this->init( $file_name );
    }

    /**
     * 初始化
     *
     * @param string $file_name
     */
    private function init($file_name){
        unset($this->path);
        $info              = pathinfo( $file_name );
        $this->file_name   = $info["basename"];
        $this->ext         = isset($info["extension"])?$info["extension"]:"";
        $this->path        = new WDir($info["dirname"]);
        $this->__file_name = $file_name;
        $this->size        = file_exists( $file_name ) ? filesize( $file_name ) : 0;
    }

    /**
     * 获取文件名原始数据
     *
     * @return string
     */
    public function get()
    {
        return $this->__file_name;
    }
    public function getFileName()
    {
        return $this->__file_name;
    }
    public function getBaseFileName()
    {
        return $this->file_name;
    }
    public function getExt()
    {
        return $this->ext;
    }

    /**
     * 获取文件路径
     *
     * @return string
     */
    public function getFilePath()
    {
        return $this->__file_name;
    }

    /**
     * 深度创建文件，目录不存在时自动创建
     *
     * @param string $file_name 需要创建的文件路径
     * @return bool
     */
    public function touch(){
        if (file_exists($this->__file_name)) {
            return true;
        }
        $this->path->mkdir();
        $success    = touch($this->__file_name);
        $this->size = file_exists($this->__file_name) ? filesize($this->__file_name) : 0;
        return $success;
    }

    /**
     * 判断文件是否存在
     *
     * @return bool
     */
    public function exists()
    {
        return file_exists($this->__file_name);
    }

    /**
     * @复制到
     *
     * @param string|static $file_name 可以是目录，也可以是完整路径（包含文件名）
     * @param bool $rf 如果已存在 是否覆盖 默认为false 不覆盖
     * @return bool
     */
    public function copyTo($file_name, $rf = false)
    {

        if ($file_name instanceof self) {
            $file_name = $file_name->get();
        }

        $file_name = str_replace("\\","/",$file_name);
        if (is_dir($file_name)) {
            $file_name = rtrim( $file_name,"/");
            $file_name = $file_name."/".$this->file_name;
        }

        if (!$rf && file_exists($file_name))
            return false;

        if (!$this->exists())
            $this->touch();

        $file = new self($file_name);
        $file->path->mkdir();

        return copy($this->__file_name, $file_name);
    }

    /**
     * @文件移动到
     *
     * @param string $file_name 目标文件路径 如D:/123.txt
     * @param bool $rf 如果文件已存在是否覆盖，默认为否
     */
    public function moveTo($file_name, $rf = false)
    {

        if ($file_name instanceof self) {
            $file_name = $file_name->get();
        }

        if (file_exists($file_name) && !$rf) {
            return false;
        }

        $file_name = str_replace("\\","/",$file_name);
        $file = new self($file_name);
        $file->path->mkdir();

        $res = rename($this->__file_name, $file_name);

        if ($res){
            $this->init( $file_name );
        }
        return $res;
    }

    /**
     * 写入文件
     *
     * @throws \Exception
     * @param string $content 写入内容
     * @param bool $append 是否追加写入，默认为true，追加写入
     * @param int $lock_wait_timeout 锁等待超时时间
     * @return int
     */
    public function write($content, $append = true, $lock_wait_timeout = 3 /*秒*/)
    {
        try {

            $this->touch();

            if (!is_writable($this->__file_name)) {
                throw new \Exception("file is not writable ".$this->__file_name);
            }

            $mode = 'a+';
            if (!$append) {
                $mode = 'w+';
            }

            $fp = fopen($this->__file_name, $mode);
            if ($fp) {
                //flock($fp, LOCK_EX);// 加锁

                //锁等待 最大时间三秒
                $start_time = time();
                while (!flock($fp, LOCK_EX)) {
                    usleep(1000);
                    if ((time()-$start_time) > $lock_wait_timeout) {
                        break;
                    }
                }

                $success = fwrite($fp, $content);
                flock($fp, LOCK_UN);// 解锁
                fclose($fp);
                return $success;
            }
            return 0;
        } catch (\Exception $e) {
            var_dump($e);
            return 0;
        }
    }

    /**
     * 追加写入文件内容
     *
     * @param string $content
     * @return int
     */
    public function append($content)
    {
        return $this->write($content);
    }

    /**
     * 删除文件
     *
     * @return bool
     */
    public function delete()
    {
        if (!file_exists($this->__file_name)) {
            return false;
            //throw new \Exception("file not found ".$this->__file_name);
        }
        return unlink($this->__file_name);
    }

    /**
     * 读取文件
     *
     * @throws \Exception
     * @return string
     */
    public function read($lock = false)
    {

        try {

            if (!file_exists($this->__file_name)) {
                throw new \Exception("can not read, file not found ".$this->__file_name);
            }

            $fh = fopen($this->__file_name, 'r');
            $content = "";

            if ($fh) {

                if ($lock && !flock($fh, LOCK_EX)) {// 加锁
                    fclose($fh);
                    return null;
                }

                while (!feof($fh)) {
                    $content .= fgets($fh);
                }

                if ($lock) {// 解锁
                    flock($fh, LOCK_UN);
                }

                fclose($fh);
            } else {
                return null;
            }
            return $content;
        } catch(\Exception $e) {
            var_dump($e);
            return null;
        }
    }

}