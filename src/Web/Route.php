<?php namespace Seals\Web;
use Seals\Web\Logic\Service;

/**
 * Created by PhpStorm.
 * User: yuyi
 * Date: 17/3/16
 * Time: 15:28
 */
class Route
{

    protected $response;
    protected $resource;

    static $routes = [
        "post" => [
            "/service/node/refresh" => "\\Seals\\Web\\Logic\\Node::info",
            "/service/all"          => "\\Seals\\Web\\Logic\\Service::getAll",
            "/service/node/restart" => "\\Seals\\Web\\Logic\\Node::restart",
            "/service/node/update"  => "\\Seals\\Web\\Logic\\Node::update",
            "/service/node/offline" => "\\Seals\\Web\\Logic\\Node::offline",
            "/service/node/runtime/config/save"      => "\\Seals\\Web\\Logic\\Node::setRuntimeConfig",
            "/service/node/notify/config/save"       => "\\Seals\\Web\\Logic\\Node::setNotifyConfig",
            "/service/node/local_redis/config/save"  => "\\Seals\\Web\\Logic\\Node::setLocalRedisConfig",
            "/service/node/redis/config/save"        => "\\Seals\\Web\\Logic\\Node::setRedisConfig",
            "/service/node/rabbitmq/config/save"     => "\\Seals\\Web\\Logic\\Node::setRabbitmqConfig",
            "/service/node/zookeeper/config/save"    => "\\Seals\\Web\\Logic\\Node::setZookeeperConfig",
            "/service/node/db/config/save"           => "\\Seals\\Web\\Logic\\Node::setDbConfig",
            "/service/node/generallog/open"          => "\\Seals\\Web\\Logic\\Node::openGenerallog",
            "/service/node/day/report"               => "\\Seals\\Web\\Logic\\Node::getDayReport",
            "/service/node/day/hour/report"          => "\\Seals\\Web\\Logic\\Node::getDayDetailReport",

            "/service/group/runtime/config/save"     => "\\Seals\\Web\\Logic\\Group::setRuntimeConfig",
            "/service/group/notify/config/save"      => "\\Seals\\Web\\Logic\\Group::setNotifyConfig",
            "/service/group/redis/config/save"       => "\\Seals\\Web\\Logic\\Group::setRedisConfig",
            "/service/group/rabbitmq/config/save"    => "\\Seals\\Web\\Logic\\Group::setRabbitmqConfig",
            "/service/group/zookeeper/config/save"   => "\\Seals\\Web\\Logic\\Group::setZookeeperConfig",
            "/service/group/db/config/save"          => "\\Seals\\Web\\Logic\\Group::setDbConfig",
            "/service/group/offline"         => "\\Seals\\Web\\Logic\\Group::offline",
            "/service/group/generallog/open" => "\\Seals\\Web\\Logic\\Group::openGenerallog",
            "/service/group/restart"         => "\\Seals\\Web\\Logic\\Group::restart",
            "/service/group/update"          => "\\Seals\\Web\\Logic\\Group::update",
            "/service/group/local_redis/config/save"  => "\\Seals\\Web\\Logic\\Group::setLocalRedisConfig",

            "/service/master/restart"        => "\\Seals\\Web\\Logic\\Master::restart",
            "/service/master/update"         => "\\Seals\\Web\\Logic\\Master::update",
            "/services/user/role/add"        => "\\Seals\\Web\\Logic\\User::addRole",
            "/services/user/update"        => "\\Seals\\Web\\Logic\\User::update",
            "/services/user/delete"=> "\\Seals\\Web\\Logic\\User::delete",
            "/services/user/add"=> "\\Seals\\Web\\Logic\\User::addUser",
        ]
    ];

    public function __construct(HttpResponse $response, $resource)
    {
        $this->response = $response;
        $this->resource = $resource;
    }

    public static function getRoutes()
    {
        return self::$routes;
    }

    public static function getAllPage()
    {
        $path[] = __APP_DIR__.'/web/*';
        $pages  = [];
        while (count($path) != 0) {
            $v = array_shift($path);
            foreach(glob($v) as $item) {
                if (is_file($item)) {
                    $pages[] = "/".pathinfo($item,PATHINFO_BASENAME);
                }
            }
        }
        return $pages;
    }

    public function parse()
    {
        echo $this->response->getMethod(),"\r\n";
        echo $this->resource,"\r\n";

        if (isset(self::$routes[$this->response->getMethod()][$this->resource])) {

            if(is_callable(self::$routes[$this->response->getMethod()][$this->resource]))
                $data = call_user_func_array(self::$routes[$this->response->getMethod()][$this->resource],[$this->response]);
            else
                $data = "";
            if (is_array($data))
                $data = json_encode($data);

            if ($data === false)
                $data = 0;

            if ($data === true)
                $data = 1;

            if (!is_scalar($data))
                return "";

            return $data;
        }

        return "404 not found";
    }

    /**
     * register get route
     * the url must start with /service
     *
     * @param string $url
     * @param \Closure $callback
     */
    public function get($url, $callback){
        self::$routes["get"][$url] = $callback;
    }

    /**
     * register post route
     * the url must start with /service
     *
     * @param string $url
     * @param \Closure $callback
     */
    public function post($url, $callback){
        self::$routes["post"][$url] = $callback;
    }
}