<?php namespace Wing\FileSystem;
/**
 * 文件夹处理类
 * Created by PhpStorm.
 * User: yuyi
 * Date: 16/11/8
 * Time: 10:15
 *
 * 文件夹操作
 *
 * @property string $__path
 * @property WDir $path
 * @property string $dir_name
 */
class WDir
{

    private $__path;
    public  $dir_name;
    private $__info;

    /**
     * 构造函数
     *
     * @param string $path
     */
    public function __construct($path)
    {
        if ($path instanceof self) {
            $path = $path->getDir();
        }

        $path = str_replace("\\","/",$path);
        $path = rtrim($path,"/");
        $info = pathinfo($path);

        $this->dir_name = $info["basename"];
        $this->__path   = $path;
        $this->__info   = $info;

    }

    /**
     * 获取原生目录路径
     *
     * @return string
     */
    public function get()
    {
        return $this->__path;
    }
    public function getDir()
    {
        return $this->__path;
    }

    /**
     * 判断目录是否可写
     *
     * @return bool
     */
    public function isWrite()
    {
        return is_writeable($this->__path);
    }

    /**
     * 魔术方法，返回path对象
     *
     * @return self use like $this->path
     */
    public function __get($name)
    {
        if ($name == "path" && !$this->path)
            $this->path = new WDir($this->__info["dirname"]);
        return $this->path;
    }

    /**
     * @深度创建文件夹
     *
     * @param string $path 想要创建的文件夹路径
     * @return bool
     */
    public function mkdir()
    {
        if (is_dir($this->__path)) {
            return true;
        }

        $path  = $this->__path;
        $path  = str_replace("\\","/",$path);
        $spath = $path[0];
        $path  = trim($path,"/");
        $paths = explode("/",$path);

        if ($spath == "/") {
            $temp  = "/".$paths[0];
        } else {
            $temp  = $paths[0];
        }

        for ($i = 1; $i < count($paths); $i++) {
            if (!is_dir($temp)) {
                if (!mkdir($temp)) {
                    return false;
                }
            }
            $temp = $temp."/".$paths[$i];
        }
        if (!is_dir($temp)) {
            if (!mkdir($temp)) {
                return false;
            }
        }
        return true;
    }

    /**
     * @深度复制文件夹
     *
     * @param string|static $target_path 复制到目标文件夹
     * @param bool $rf 是否覆盖已存在的文件，true为是，false跳过
     * @return bool
     */
    public function copyTo($target_path, $is_rf = false)
    {

        if ($target_path instanceof self) {
            $target_path = $target_path->getDir();
        }

        if (!is_dir($target_path)) {
            $dir = new self($target_path);
            if (!$dir->mkdir())
                return false;
            unset($dir);
        }
        $copy_path   = $this->__path;
        $target_path = str_replace("\\","/",$target_path);
        $target_path = rtrim($target_path,"/");

        if (file_exists($copy_path."/.gitignore"))
            copy($copy_path."/.gitignore",$target_path."/.gitignore");

        $path[] = $copy_path.'/*';

        while (count($path) != 0) {
            $v = array_shift($path);
            foreach (glob($v) as $item) {
                if (is_dir($item)) {
                    $path[] = $item . '/*';
                    $item   = str_replace(
                        str_replace("\\","/",$copy_path),
                        "",
                        str_replace("\\","/",$item)
                    );
                    if (!is_dir($target_path.$item)) {
                        mkdir($target_path.$item);
                    }
                } elseif (is_file($item)) {
                    $target_item = $target_path.str_replace(
                            str_replace("\\","/",$copy_path),
                            "",
                            str_replace("\\","/",$item)
                        );
                    if (file_exists($target_item)) {
                        if( $is_rf ) {
                            copy($item, $target_item);
                        }
                    } else {
                        copy($item, $target_item);
                    }
                }
            }
        }

        return true;
    }


    /**
     * 移动文件夹
     *
     * @param string $target_path 目标文件夹
     * @param bool $is_rf 如果目标文件存在是否覆盖 默认为false 不覆盖
     * @return bool
     */
    public function moveTo($target_path, $is_rf = false)
    {

        if ($target_path instanceof self) {
            $target_path = $target_path->getDir();
        }

        if (!is_dir($target_path)) {
            $dir = new self($target_path);
            if (!$dir->mkdir()) {
                return false;
            }
            unset($dir);
        }
        $copy_path   = $this->__path;
        $target_path = str_replace("\\","/",$target_path);
        $target_path = rtrim($target_path,"/");

        $path[] = $copy_path.'/*';

        while (count($path) != 0) {
            $v = array_shift($path);
            foreach (glob($v) as $item) {
                if (is_dir($item)) {
                    $path[] = $item . '/*';
                    $item   = str_replace(
                        str_replace("\\","/",$copy_path),
                        "",
                        str_replace("\\","/",$item)
                    );
                    if(!is_dir($target_path.$item)) {
                        mkdir($target_path.$item);
                    }
                }
                elseif (is_file($item)) {
                    $target_item = $target_path.str_replace(
                            str_replace("\\","/",$copy_path),
                            "",
                            str_replace("\\","/",$item)
                        );
                    if( (file_exists($target_item) && $is_rf) || !file_exists($target_item)) {
                        rename($item, $target_item);
                    }
                }
            }
        }

        $this->delete();
        return true;
    }


    /**
     * 删除文件夹（包括所有的子文件夹和文件都会被删除）
     *
     * @return bool
     */
    public function delete()
    {
        $dir   = $this->__path;
        $files = array_diff(scandir($dir), array('.','..'));

        foreach ($files as $file) {
            if (is_dir("$dir/$file")) {
                $_dir = new self("$dir/$file");
                $_dir->delete();
            } else {
                unlink("$dir/$file");
            }
        }
        return rmdir($dir);
    }

    /**
     * 目录遍历
     *
     * @return array
     */
    public function scandir()
    {
        $path[] = $this->__path.'/*';
        $files  = [];
        while (count($path) != 0) {
            $v = array_shift($path);
            foreach(glob($v) as $item) {
                if (is_dir($item)) {
                    $path[] = $item . '/*';
                } elseif (is_file($item)) {
                    $files[] = $item;
                }
            }
        }
        return $files;
    }
}