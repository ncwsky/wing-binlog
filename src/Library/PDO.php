<?php
namespace Wing\Library;

/**
 * @author yuyi
 * @created 2016/11/25 22:23
 * @property \PDO $pdo
 *
 * 数据库操作pdo实现
 *
 */
class PDO implements IDb
{
    /**
     * @var \PDO
     */
    private $pdo;

    /**
     * @var \PDOStatement
     */
    private $statement;

    /**
     * @var bool
     */
    private $bconnected = false;
    private $host = '127.0.0.1';
    private $dbname = '';
    private $password;
    private $user;
    private $lastSql = "";
    private $port = 3306;
    private $char = 'utf8';
    public $hasTableFields = false;

    /**
     * 构造函数
     */
    public function __construct()
    {
        $config = load_config("app");

        if (!is_array($config)) {
            if (WING_DEBUG) {
                wing_debug("数据库配置错误");
            }
            exit;
        }

        $config = $config["mysql"];
        isset($config["host"]) && $this->host = $config["host"];
        isset($config["db_name"]) && $this->dbname = $config["db_name"];
        isset($config["port"]) && $this->port = $config["port"];
        isset($config["char"]) && $this->char = $config["char"];

        $this->password   = $config["password"];
        $this->user       = $config["user"];

        $this->connect();
    }

    /**
     * @析构函数
     */
    public function __destruct()
    {
        $this->close();
    }

    /**
     * 获取db名称
     *
     * @return string
     */
    public function getDatabaseName()
    {
        return $this->dbname;
    }

    /**
     * 获取host
     *
     * @return string
     */
    public function getHost()
    {
        return $this->host;
    }

    /**
     * 获取user
     *
     * @return string
     */
    public function getUser()
    {
        return $this->user;
    }

    /**
     * 获取password
     *
     * @return string
     */
    public function getPassword()
    {
        return $this->password;
    }

    /**
     * 获取连接端口
     *
     * @return int
     */
    public function getPort()
    {
        return $this->port;
    }

    public function getTables()
    {
        $datas = $this->query("show tables");
        return $datas;
    }

    /**
     * @链接数据库
     */
    private function connect()
    {
        $dsn = 'mysql:dbname=' . $this->dbname . ';host=' . $this->host . ';port='.$this->port;
        try {
            $this->pdo = new \PDO(
                $dsn,
                $this->user,
                $this->password,
                [\PDO::MYSQL_ATTR_INIT_COMMAND => 'SET NAMES '.$this->char]
            );

            $this->pdo->setAttribute(\PDO::ATTR_ERRMODE, \PDO::ERRMODE_EXCEPTION);
            $this->pdo->setAttribute(\PDO::ATTR_EMULATE_PREPARES, false);

            $this->bconnected = true;

            //是否有定义获取表字段过程 TableFields(db_name,table_name) 同时必需给账号此过程的执行权限
            if($this->row("SHOW PROCEDURE STATUS LIKE 'TableFields'")){
                $this->hasTableFields = true;
            }
        } catch (\PDOException $e) {
            wing_log('exception', 'connect fail', $e->getFile().':'.$e->getLine(), $e->getMessage(), $e->errorInfo, $e->getTraceAsString());
            if (WING_DEBUG) {
                var_dump("mysql连接异常:".__CLASS__."::".__FUNCTION__, $e->errorInfo);
            }

            sleep(5);
            $this->connect();
        }
    }
    private function getConnection()
    {
        $this->init('SELECT 1'); //用于失败后重连

        return $this;
    }

    /**
     * @关闭链接
     */
    private function close()
    {
        $this->pdo        = null;
        $this->bconnected = false;
    }

    /**
     * 初始化数据库连接以及参数绑定
     *
     * @param string $query
     * @param array $parameters
     * @return bool
     */
    private function init($query, $parameters = null)
    {
        if ($parameters && !is_array($parameters)) {
            $parameters = [$parameters];
        }

        $this->lastSql = $query;

        if ($parameters) {
            $this->lastSql .= ", with data: " . json_encode($parameters, JSON_UNESCAPED_UNICODE);
        }

        if (!$this->bconnected) {
            $this->connect();
        }

        try {
            if (!$this->pdo) {
                return false;
            }

            $this->statement = $this->pdo->prepare($query);

            if (!$this->statement) {
                return false;
            }

            return $this->statement->execute($parameters);
        } catch (\PDOException $e) {
            wing_log('exception', 'will retry conn', $e->getFile().':'.$e->getLine(), $e->getMessage(), $e->errorInfo, $e->getTraceAsString());
            $this->close();
            $this->connect();

            if (WING_DEBUG) {
                var_dump(__CLASS__."::".__FUNCTION__, $e->errorInfo);
            }
        }
        return false;
    }

    public function query($query, $params = null, $fetchmode = \PDO::FETCH_ASSOC){
        return $this->getConnection()->_query($query, $params, $fetchmode);
    }
    /**
     * 执行SQL语句
     *
     * @param  string $query
     * @param  array  $params
     * @param  int    $fetchmode
     * @return mixed
     */
    private  function _query($query, $params = null, $fetchmode = \PDO::FETCH_ASSOC)
    {
        $query    = preg_replace("/\s+|\t+|\n+/", " ", $query);
        $init_res = $this->init($query, $params);

        try {
            $rawStatement = explode(" ", $query);
            $statement    = strtolower($rawStatement[0]);

            if ($statement === 'select' || $statement === 'show') {
                if (!$this->statement) {
                    return null;
                }

                return $this->statement->fetchAll($fetchmode);
            }

            if ($statement === 'insert') {
                if (!$this->pdo) {
                    return null;
                }

                return $this->pdo->lastInsertId();
            }

            if ($statement === 'update' || $statement === 'delete') {
                if (!$this->statement) {
                    return 0;
                }

                return $this->statement->rowCount();
            }
        } catch (\PDOException $e) {
            wing_log('exception', 'query fail', $e->getFile().':'.$e->getLine(), $e->getMessage(), $e->errorInfo, $e->getTraceAsString());
            if (WING_DEBUG) {
                var_dump(__CLASS__."::".__FUNCTION__, $e->errorInfo);
            }

            $this->close();
            $this->connect();
        }

        return $init_res;
    }

    public function row($query, $params = null, $fetchmode = \PDO::FETCH_ASSOC){
        return $this->getConnection()->_row($query, $params, $fetchmode);
    }
    /**
     * 查询返回行
     *
     * @param  string $query
     * @param  array  $params
     * @param  int    $fetchmode
     * @return array
     */
    public function _row($query, $params = null, $fetchmode = \PDO::FETCH_ASSOC)
    {
        try {
            $this->init($query, $params);

            if ($this->statement) {
                $result = $this->statement->fetch($fetchmode);
                $this->statement->closeCursor();
                return $result;
            }
        } catch (\PDOException $e) {
            wing_log('exception', 'query fail', $e->getFile().':'.$e->getLine(), $e->getMessage(), $e->errorInfo, $e->getTraceAsString());
            if (WING_DEBUG) {
                var_dump(__CLASS__."::".__FUNCTION__, $e->errorInfo);
            }

            $this->close();
            $this->connect();
        }
        return [];
    }

    public function getFields($schema, $table)
    {
        //使用了自定义获取存储过程
        if($this->hasTableFields){
            return $this->getConnection()->pdo->query("call mysql.TableFields('{$schema}','{$table}')")->fetchAll(\PDO::FETCH_ASSOC);
        }
        $sql = "SELECT COLUMN_NAME,COLLATION_NAME,CHARACTER_SET_NAME,COLUMN_COMMENT,COLUMN_TYPE,COLUMN_KEY FROM information_schema.columns WHERE table_schema = '{$schema}' AND table_name = '{$table}'";
        return $this->query($sql);
    }

    /**
     * @获取最后执行的sql
     *
     * @return string
     */
    public function getLastSql()
    {
        return $this->lastSql;
    }

    public function getDatabases()
    {
        $data = $this->query('show databases');
        $res  = [];

        foreach ($data as $row) {
            $res[] = $row["Database"];
        }

        return $res;
    }
}
