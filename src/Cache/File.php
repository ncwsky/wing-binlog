<?php namespace Wing\Cache;
use Wing\Library\ICache;

/**
 * Created by PhpStorm.
 * User: yuyi
 * Date: 17/3/11
 * Time: 10:22
 */
class File implements ICache
{
    protected $cache_dir = CACHE_DIR;
    public function __construct($cache_dir = CACHE_DIR)
    {
        if ($cache_dir) {
            $this->cache_dir = str_replace("\\", "/", $cache_dir);
            $this->cache_dir = rtrim($this->cache_dir, "/");
        }

        if (!is_dir($this->cache_dir)) {
            mkdir($this->cache_dir, 0777, true);
        }
    }
    public function set($key, $value, $timeout = 0)
    {
        return file_put_contents(
            $this->cache_dir."/".$key,
            json_encode([
                "value"   => $value,
                "timeout" => $timeout,
                "created" => time()
            ])
        );
    }
    public function get($key)
    {
        $file = $this->cache_dir."/".$key;
        if (!is_file($file) || !file_exists($file))
            return null;
        $res = file_get_contents($file);
        $res = json_decode($res,true);

        if (!is_array($res)) {
            unlink($file);
            return null;
        }

        $timeout = $res["timeout"];
        if ($timeout > 0 && (time()-$timeout) > $res["created"]) {
            unlink($file);
            return null;
        }
        return $res["value"];
    }
    public function del($key)
    {
        if (!is_array($key)) {
            $file = $this->cache_dir."/".$key;

            if (!is_file($file) || !file_exists($file))
                return 0;
            $success = unlink($file);
            if ($success)
                return 1;
            return 0;
        } else {
            $count = 0;
            foreach ($key as $_key) {
                $file = $this->cache_dir."/".$_key;

                if (!is_file($file) || !file_exists($file))
                    continue;

                $success = unlink($file);
                if ($success)
                    $count++;
            }
            return $count;
        }
    }

    public function keys($p = ".*")
    {
        $path[] = $this->cache_dir.'/*';
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

        $keys = [];
        foreach ($files as $file) {
            $name = pathinfo($file,PATHINFO_FILENAME);
            if (preg_match("/".$p."/",$name))
                $keys[] = $name;
        }
        return $keys;
    }
}