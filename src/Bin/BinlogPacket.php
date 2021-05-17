<?php namespace Wing\Bin;

use Wing\Bin\Constant\Column;
use Wing\Bin\Constant\EventType;
use Wing\Bin\Constant\FieldType;
use Wing\Library\Binlog;

/**
 * Created by PhpStorm.
 * User: yuyi
 * Date: 17/9/8
 * Time: 23:14
 */
class BinlogPacket
{
    /**
     * @var int $offset 读取当前数据包的偏移量
     */
    protected $offset = 0;

    /**
     * @var string $packet 当前待处理的二进制数据包
     */
    protected $packet;

    /**
     * @var string $buffer 缓冲区，实现c语言的"unget作用是将最近读取的字符回流"
     */
    protected $buffer = '';

    /**
     * @var string $schema_name 数据库名称
     */
    protected $schema_name;

    /**
     * @var string $table_name 数据表名称
     */
    protected $table_name;

    /**
     * @var array $table_map 数据表字段缓冲区，用于提升性能
     */
    protected $table_map = [];

    /**
     * table_map 缓存文件
     * @var string
     */
    protected $table_map_file = '';

    public static $bitCountInByte = [
        0, 1, 1, 2, 1, 2, 2, 3, 1, 2, 2, 3, 2, 3, 3, 4,
        1, 2, 2, 3, 2, 3, 3, 4, 2, 3, 3, 4, 3, 4, 4, 5,
        1, 2, 2, 3, 2, 3, 3, 4, 2, 3, 3, 4, 3, 4, 4, 5,
        2, 3, 3, 4, 3, 4, 4, 5, 3, 4, 4, 5, 4, 5, 5, 6,
        1, 2, 2, 3, 2, 3, 3, 4, 2, 3, 3, 4, 3, 4, 4, 5,
        2, 3, 3, 4, 3, 4, 4, 5, 3, 4, 4, 5, 4, 5, 5, 6,
        2, 3, 3, 4, 3, 4, 4, 5, 3, 4, 4, 5, 4, 5, 5, 6,
        3, 4, 4, 5, 4, 5, 5, 6, 4, 5, 5, 6, 5, 6, 6, 7,
        1, 2, 2, 3, 2, 3, 3, 4, 2, 3, 3, 4, 3, 4, 4, 5,
        2, 3, 3, 4, 3, 4, 4, 5, 3, 4, 4, 5, 4, 5, 5, 6,
        2, 3, 3, 4, 3, 4, 4, 5, 3, 4, 4, 5, 4, 5, 5, 6,
        3, 4, 4, 5, 4, 5, 5, 6, 4, 5, 5, 6, 5, 6, 6, 7,
        2, 3, 3, 4, 3, 4, 4, 5, 3, 4, 4, 5, 4, 5, 5, 6,
        3, 4, 4, 5, 4, 5, 5, 6, 4, 5, 5, 6, 5, 6, 6, 7,
        3, 4, 4, 5, 4, 5, 5, 6, 4, 5, 5, 6, 5, 6, 6, 7,
        4, 5, 5, 6, 5, 6, 6, 7, 5, 6, 6, 7, 6, 7, 7, 8,
    ];

    private static $instance = null;

    /**
    * 此api为唯一入口，对外必须以静态单例调用
    * 因为有些属性前面初始化后，后面可能继续使用的
    *
    * @param string $pack 数据包，次参数来源于Packet::readPacket
    * @param bool $check_sum
    * @return array|mixed
    */
    public static function parse($pack, $check_sum = true)
    {
        if (!self::$instance) {
            self::$instance = new self();
            $table_map_file = HOME.'/cache/table_map.json';
            self::$instance->table_map_file = $table_map_file;
            //读取table_map缓存
            if(is_file($table_map_file)){
                self::$instance->table_map = json_decode(file_get_contents($table_map_file), true);
            }
        }
        return self::$instance->packParse($pack, $check_sum);
    }

    /**
    * 内部入口
    *
    * @param string $pack binlog事件数据包，次参数来源于Packet::readPacket
    * @param bool $check_sum
    * @return array|mixed
    */
    private function packParse($pack, $check_sum = true)
    {
        $file_name  = null;
        $data       = [];
        $log_pos    = 0;

        if (strlen($pack) < 20) { //公有事件头 ok[1]+固定[19]字节
            goto end;
        }

        $this->packet = $pack;
        $this->offset = 0;

        $this->advance(1); # OK value

        $timestamp  = unpack('V', $this->read(4))[1];
        $event_type = unpack('C', $this->read(1))[1];
        $server_id  = unpack('V', $this->read(4))[1];

        #wing_debug("server id = ",$server_id);

        $event_size = unpack('V', $this->read(4))[1];
        //position of the next event
        $log_pos    = unpack('V', $this->read(4))[1];

        $flags = unpack('v', $this->read(2))[1];

        //排除事件头Event Head的事件大小
        $event_size_without_header = $check_sum === true ? ($event_size -23) : $event_size - 19;

        switch ($event_type) {
            // 映射fileds相关信息
            case EventType::TABLE_MAP_EVENT:
                $this->tableMap();

                break;
            case EventType::UPDATE_ROWS_EVENT_V2:
            case EventType::UPDATE_ROWS_EVENT_V1:
                $data = $this->updateRow($event_type, $event_size_without_header);
                $data["time"] = date("Y-m-d H:i:s", $timestamp);

                break;
            case EventType::WRITE_ROWS_EVENT_V1:
            case EventType::WRITE_ROWS_EVENT_V2:
                $data = $this->addRow($event_type, $event_size_without_header);
                $data["time"] = date("Y-m-d H:i:s", $timestamp);

                break;
            case EventType::DELETE_ROWS_EVENT_V1:
            case EventType::DELETE_ROWS_EVENT_V2:
                $data =  $this->delRow($event_type, $event_size_without_header);
                $data["time"] = date("Y-m-d H:i:s", $timestamp);

                break;
            case EventType::ROTATE_EVENT:
                $log_pos = $this->readUint64();
                $file_name = $this->read($event_size_without_header - 8);

                wing_log('rotate', $file_name.'   '.$log_pos);
                Binlog::$forceWriteLogPos = true; //更新日志点

                break;
            case EventType::HEARTBEAT_LOG_EVENT:
                //心跳检测机制
                $binlog_name = $this->read($event_size_without_header);
                wing_debug('HEARTBEAT => ' . $binlog_name.' : '.$log_pos);

                break;
            case EventType::XID_EVENT:
                wing_debug('XID');
//                $data =  $this->eventXid();
//                $data["time"] = date("Y-m-d H:i:s", $timestamp);
                break;
            case EventType::QUERY_EVENT:
                $data =  $this->eventQuery($event_size_without_header);
                $data["time"] = date("Y-m-d H:i:s", $timestamp);

                //可能是修改表结构sql 清除数据表缓存
                if ($this->schema_name && $this->table_name && $this->schema_name==$data['dbname'] && strpos(strtolower($data['data']), strtolower($this->table_name)) !== false) {
                    $this->unsetTableMapCache($this->schema_name, $this->table_name);

                    wing_log('query', $data, '['.$this->schema_name.']', '['.$this->table_name.']');
                }
                wing_debug("QUERY", $pack);
                break;
            default:
                wing_debug("Unknown", $event_type, $pack);
                break;
        }
/*        if(isset($data["dbname"])){
            $data["event_size"] = $event_size;
        }*/

        if (WING_DEBUG) {
            $msg  = $file_name;
            $msg .= '-- next pos -> '.$log_pos;

            wing_debug("position", $msg);
        }

        end:
        return [$data, $file_name, $log_pos];
    }

    /**
    * 读取指定长度的字节数据
    *
    * @param int $length
    * @return string
    */
    public function read($length)
    {
        $length  = intval($length);
        $sub_str = '';

        if ($this->buffer!=='') {
            $sub_str = substr($this->buffer, 0 , $length);
            if (strlen($sub_str) == $length) {
                $this->buffer = substr($this->buffer, $length);;
                return $sub_str;
            } else {
                $this->buffer = '';
                $length = $length - strlen($sub_str);
            }
        }

        $sub_str .= substr($this->packet, $this->offset, $length);

        $this->offset += $length;

        return $sub_str;
    }

    /**
    * 前进步长
    *
    * @param $length
    */
    public  function advance($length)
    {
        $this->read($length);
    }

    /**
    * 读取一个使用'Length Coded Binary'格式编码的数据长度
    * read a 'Length Coded Binary' number from the data buffer.
    * Length coded numbers can be anywhere from 1 to 9 bytes depending
    * on the value of the first byte.
    * From PyMYSQL source code
    *
    * @return int|string
    */
    public function readCodedBinary()
    {
        $c = ord($this->read(1));
        if ($c == Column::NULL) {
            return null;
        }
        if ($c < Column::UNSIGNED_CHAR) {
            return $c;
        }
        if ($c == Column::UNSIGNED_SHORT) {
            return $this->readUint16();
        }
        if ($c == Column::UNSIGNED_INT24) {
            return $this->readUint24();
        }

        if ($c == Column::UNSIGNED_INT64) {
            return $this->readUint64();
        }

        #throw new \Exception('Column num ' . $c . ' not handled');
        return null;
    }

    public function readUint8()
    {
        return unpack('C', $this->read(1))[1];
    }
    public function readInt8()
    {
        $re = unpack('c', $this->read(1))[1];
        return $re >= 0x80 ? $re - 0x100 : $re;
    }

    public function readUint16()
    {
        return unpack('v', $this->read(2))[1];
    }
    public function readInt16()
    {
        return unpack('s', $this->read(2))[1];
    }
    public function readInt16Be(): int
    {
        $re = unpack('n', $this->read(2))[1];
        return $re >= 0x8000 ? $re - 0x10000 : $re;
    }

    public function readInt24()
    {
        $data = unpack("CCC", $this->read(3));
        $res  = $data[1] | ($data[2] << 8) | ($data[3] << 16);

        if ($res >= 0x800000) {
            $res -= 0x1000000;
        }
        return $res;
    }

    public function readUint24()
    {
        $data = unpack("C3", $this->read(3));
        return $data[1] + ($data[2] << 8) + ($data[3] << 16);
    }

    public function readInt24Be()
    {
        $data = unpack('C3', $this->read(3));
        $res  = ($data[1] << 16) | ($data[2] << 8) | $data[3];
        if ($res >= 0x800000) {
            $res -= 0x1000000;
        }
        return $res;
    }

    public function readUint32()
    {
        return unpack('V', $this->read(4))[1];
    }

    public function readInt32()
    {
        return unpack('l', $this->read(4))[1];
    }

    public function readInt32Be()
    {
        $res = unpack('N', $this->read(4))[1];
        if ($res >= 0x80000000) {
            $res -= 0x100000000;
        }
        return $res;
    }

    public function readUint40()
    {
        $data = unpack("CI", $this->read(5));
        return $data[1] + ($data[2] << 8);
    }

    public function readInt40Be()
    {
        $data1= unpack("N", $this->read(4))[1];
        $data2 = unpack("C", $this->read(1))[1];
        return $data2 + ($data1 << 8);
    }

    public function readUint48()
    {
        $data = unpack("vvv", $this->read(6));
        return $data[1] + ($data[2] << 16) + ($data[3] << 32);
    }

    public function readUint56()
    {
        $data = unpack("CSI", $this->read(7));
        return $data[1] + ($data[2] << 8) + ($data[3] << 24);
    }

    /*
    * 不支持unsigned long long，溢出
    */
    public function readUint64()
    {
        return $this->unpackUInt64($this->read(8));
    }

    public function readInt64(): string
    {
        $data = unpack('V*', $this->read(8));

        return bcadd((string)$data[1], (string)($data[2] << 32));
    }

    public function unpackUInt64(string $binary)
    {
        $data = unpack('V*', $binary);
        return bcadd((string)$data[1], bcmul((string)$data[2], bcpow('2', '32')));
    }

    public function readUintBySize($size)
    {
        if ($size == 1) {
            return $this->readUint8();
        } else if ($size == 2) {
            return $this->readUint16();
        } else if ($size == 3) {
            return $this->readUint24();
        } else if ($size == 4) {
            return $this->readUint32();
        } else if ($size == 5) {
            return $this->readUint40();
        } else if ($size == 6) {
            return $this->readUint48();
        } else if ($size == 7) {
            return $this->readUint56();
        } else if ($size == 8) {
            return $this->readUint64();
        }

        return null;
    }

    public function readLengthCodedPascalString($size)
    {
        return $this->read($this->readUintBySize($size));
    }

    //读取大端序
    public function readIntBeBySize($size)
    {
        //Read a big endian integer values based on byte number
        if ($size == 1) {
            return $this->readInt8();
        }
        else if( $size == 2) {
            return $this->readInt16Be();
        }
        else if( $size == 3) {
            return $this->readInt24Be();
        }
        else if( $size == 4) {
            return $this->readInt32Be();
        }
        else if( $size == 5) {
            return $this->readInt40Be();
        }
        else if( $size == 8) {
            return unpack('J', $this->read($size))[1];
        }

        return null;
    }

    /**
     * 用于判定是否已经处理完数据
     * @param $size $event_size_without_header
     * @return bool
     */
    public function hasNext($size)
    {
        return $this->offset - 20 < $size;
    }

    public function unread($data)
    {
        $this->buffer .= $data;
    }

    public function readTableId()
    {
        $tableIdStr = $this->read(6) . chr(0) . chr(0);
        //Table ID is 6 byte
        return PHP_INT_SIZE > 4 ? unpack("P", $tableIdStr)[1] : $this->unpackUInt64($tableIdStr);
        #return unpack("P", $this->read(6).chr(0).chr(0))[1];
    }

    /**
    * 设置table_map缓存，避免重复查询数据库
    *
    * @param string $schema_name 数据库名称
    * @param string $table_name 数据表名称
    * @param array $data
    */
    protected function setTableMapCache($schema_name, $table_name, $data)
    {
        $this->table_map[$schema_name][$table_name] = $data;
    }

    /**
    * 判断是都存在table_map缓存
    *
    * @param string $schema_name
    * @param string $table_name
    * @param int $table_id
    * @return bool
    */
    protected function issetTableMapCache($schema_name, $table_name, $table_id)
    {
        return isset($this->table_map[$schema_name][$table_name]['table_id']) &&
            $this->table_map[$schema_name][$table_name]['table_id'] == $table_id;
    }

    /**
    * 删除table_map缓存，发生在table结构改变事件
    *
    * @param string $schema_name
    * @param string $table_name
    */
    protected function unsetTableMapCache($schema_name, $table_name)
    {
        wing_debug('del_table_map:'.$table_name);
        unset($this->table_map[$schema_name][$table_name]);
    }

    /**
     * 解析 TABLE_MAP_EVENT
     * @return array
     */
    public function tableMap()
    {
        $table_id = $this->readTableId();
        $this->read(2); //flags

        wing_debug('table_id:'.$table_id);

        //$flags       = unpack('S', $this->read(2))[1];
        $schema_length = unpack("C", $this->read(1))[1];

        // 数据库名称
        $this->schema_name = $this->read($schema_length);
        // 00
        $this->advance(1);

        $table_length     = unpack("C", $this->read(1))[1];
        $this->table_name = $this->read($table_length); //数据表名称

        //00
        $this->advance(1);

        $columns_num     = $this->readCodedBinary();
        $column_type_def = $this->read($columns_num);

        if ($this->issetTableMapCache($this->schema_name, $this->table_name, $table_id)) {
            return [
                'schema_name'=> $this->schema_name,
                'table_name' => $this->table_name,
                'table_id'   => $table_id
            ];
        }

        $this->setTableMapCache($this->schema_name, $this->table_name, [
            'schema_name'=> $this->schema_name,
            'table_name' => $this->table_name,
            'table_id'   => $table_id
        ]);

        $this->readCodedBinary();
        //fields 相应属性
        $colums = Binlog::$db->getFields($this->schema_name, $this->table_name);
        $this->table_map[$this->schema_name][$this->table_name]['fields'] = [];

        wing_log('getFields', $this->schema_name, $this->table_name, $column_type_def, $colums);

        for ($i = 0; $i < strlen($column_type_def); $i++) {
            $type = ord($column_type_def[$i]);
            //if(!isset($colums[$i])){
            //    wing_log("slave_warn", var_export($colums, true).var_export($data, true));
            //}
            //self::$TABLE_MAP[self::$SCHEMA_NAME][self::$TABLE_NAME]['fields'][$i] =
            //BinLogColumns::parse($type, $colums[$i], $this);
            $this->table_map[$this->schema_name][$this->table_name]['fields'][$i] = $this->ColumnParse($type, $colums[$i]);
        }

        //缓存
        file_put_contents($this->table_map_file, json_encode($this->table_map), LOCK_EX | LOCK_NB);

        return [
            'schema_name'=> $this->schema_name,
            'table_name' => $this->table_name,
            'table_id'   => $table_id
        ];
    }

    public function ColumnParse($column_type, $column_schema)
    {
        $field = [];

        $field['type']               = $column_type;
        $field['name']               = $column_schema["COLUMN_NAME"];
        $field['collation_name']     = $column_schema["COLLATION_NAME"];
        $field['character_set_name'] = $column_schema["CHARACTER_SET_NAME"];
        $field['comment']            = $column_schema["COLUMN_COMMENT"];
        $field['unsigned']           = stripos($column_schema["COLUMN_TYPE"], 'unsigned') === false ? false : true;
        $field['type_is_bool']       = false;
        $field['is_primary']         = $column_schema["COLUMN_KEY"] == "PRI";

        if ($field['type'] == FieldType::VARCHAR) {
            $field['max_length'] = $this->readUint16();
        }
        else if ($field['type'] == FieldType::DOUBLE) {
            $field['size'] = $this->readUint8();
        }
        else if ($field['type'] == FieldType::FLOAT) {
            $field['size'] = $this->readUint8();
        }
        else if ($field['type'] == FieldType::TIMESTAMP2) {
            $field['fsp'] = $this->readUint8();
        }
        else if ($field['type'] == FieldType::DATETIME2) {
            $field['fsp']= $this->readUint8();
        }
        else if ($field['type'] == FieldType::TIME2) {
            $field['fsp'] = $this->readUint8();
        }
        else if ($field['type'] == FieldType::TINY && $column_schema["COLUMN_TYPE"] == "tinyint(1)") {
            $field['type_is_bool'] = True;
        }
        else if ($field['type'] == FieldType::VAR_STRING || $field['type'] == FieldType::STRING) {
            $this->_readStringMetadata($column_schema, $field);
        }
        else if( $field['type'] == FieldType::BLOB) {
            $field['length_size'] = $this->readUint8();
        }
        else if ($field['type'] == FieldType::GEOMETRY) {
            $field['length_size'] = $this->readUint8();
        }
        else if ($field['type'] == FieldType::JSON) {
            $field['length_size'] = $this->readUint8();
        }
        else if( $field['type'] == FieldType::NEWDECIMAL) {
            $field['precision'] = $this->readUint8();
            $field['decimals'] = $this->readUint8();
        }
        else if ($field['type'] == FieldType::BIT) {
            $bits  = $this->readUint8();
            $bytes = $this->readUint8();

            $field['bits']  = ($bytes * 8) + $bits;
            $field['bytes'] = (int)(($field['bits'] + 7) / 8);
        }

        return $field;
    }

    private function _readStringMetadata($column_schema, &$field)
    {
        $metadata  = ($this->readUint8() << 8) + $this->readUint8();
        $real_type = $metadata >> 8;

        if ($real_type == FieldType::SET || $real_type == FieldType::ENUM) {
            $field['type'] = $real_type;
            $field['size'] = $metadata & 0x00ff;
            $this->_readEnumMetadata($column_schema, $field);
        } else {
            $field['max_length'] = ((($metadata >> 4) & 0x300) ^ 0x300) + ($metadata & 0x00ff);
        }
    }

    private function _readEnumMetadata($column_schema, &$field)
    {
        $enums = $column_schema["COLUMN_TYPE"];

        if ($field['type'] == FieldType::ENUM) {
            $enums = str_replace('enum(', '', $enums);
            $enums = str_replace(')', '', $enums);
            $enums = str_replace('\'', '', $enums);
            $field['enum_values'] = explode(',', $enums);
        } else {
            $enums = str_replace('set(', '', $enums);
            $enums = str_replace(')', '', $enums);
            $enums = str_replace('\'', '', $enums);
            $field['set_values'] = explode(',', $enums);
        }
    }

    public static function bitCount($bitmap)
    {
        $n = 0;

        for ($i = 0; $i < strlen($bitmap); $i++) {
            $bit = $bitmap[$i];

            if(is_string($bit)) {
                $bit = ord($bit);
            }

            $n += self::$bitCountInByte[$bit];
        }

        return $n;
    }

    public static function BitGet($bitmap, $position)
    {
        $bit = $bitmap[intval($position / 8)];

        if (is_string($bit)) {
            $bit = ord($bit);
        }

        return $bit & (1 << ($position & 7));
    }

    public static function _isNull($null_bitmap, $position)
    {
        $bit = $null_bitmap[intval($position / 8)];
        if (is_string($bit)) {
            $bit = ord($bit);
        }

        return $bit & (1 << ($position % 8));
    }

    private function _readString($size, $column)
    {
        $string = $this->readLengthCodedPascalString($size);
        if ($column['character_set_name']) {
            //string = string . decode(column . character_set_name)
        }
        return $string;
    }

    private function readNewDecimal($column)
    {
        # Read MySQL's new decimal format introduced in MySQL 5"""
        # This project was a great source of inspiration for
        # understanding this storage format.
        # https://github.com/jeremycole/mysql_binlog

        $digits_per_integer = 9;
        $compressed_bytes   = [0, 1, 1, 2, 2, 3, 3, 4, 4, 4];
        $integral           = ($column['precision'] - $column['decimals']);
        $uncomp_integral    = intval($integral / $digits_per_integer);
        $uncomp_fractional  = intval($column['decimals'] / $digits_per_integer);
        $comp_integral      = $integral - ($uncomp_integral * $digits_per_integer);
        $comp_fractional    = $column['decimals'] - ($uncomp_fractional * $digits_per_integer);

        # Support negative
        # The sign is encoded in the high bit of the the byte
        # But this bit can also be used in the value
        $value = $this->readUint8();

        if (($value & 0x80) != 0) {
            $res  = "";
            $mask = 0;
        } else {
            $mask = -1;
            $res  = "-";
        }

        $this->unread(pack('C', $value ^ 0x80));
        $size = $compressed_bytes[$comp_integral];

        if ($size > 0) {
            $value =  $this->readIntBeBySize($size) ^ $mask;
            $res  .= (string)$value;
        }

        for ($i = 0; $i < $uncomp_integral; $i++) {
            $value = unpack('N', $this->read(4))[1] ^ $mask;
            $res  .= sprintf('%09d' , $value);
        }

        $res .= ".";

        for ($i =0 ; $i < $uncomp_fractional; $i++) {
            $value = unpack('N', $this->read(4))[1] ^ $mask;
            $res  .= sprintf('%09d' , $value);
        }

        $size = $compressed_bytes[$comp_fractional];

        if ($size > 0) {
            $value = $this->readIntBeBySize($size) ^ $mask;
            $res  .= sprintf('%0'.$comp_fractional.'d' , $value);
        }

        return bcmul($res, '1', $comp_fractional);
    }

    private function _readDatetime()
    {
        $value = $this->readUint64();

        if ($value == 0) {  # nasty mysql 0000-00-00 dates
            return null;
        }
/*        $date = \DateTime::createFromFormat('YmdHis', $value)->format('Y-m-d H:i:s');
        if (array_sum(\DateTime::getLastErrors()) > 0) {
            return null;
        }

        return $date;*/

        $date  = $value / 1000000;
        $time  = (int)($value % 1000000);
        $year  = (int)($date / 10000);
        $month = (int)(($date % 10000) / 100);
        $day   = (int)($date % 100);

        if ($year == 0 or $month == 0 or $day == 0) {
            return null;
        }

        return $year.'-'.$month.'-'.$day .' '.
            intval($time / 10000).':'.
            intval(($time % 10000) / 100).':'.
            intval($time % 100);
    }

    private static function _readBinarySlice($binary, $start, $size, $data_length)
    {
        /*
        Read a part of binary data and extract a number
        binary: the data
        start: From which bit (1 to X)
        size: How many bits should be read
        data_length: data size
        */
        $binary = $binary >> $data_length - ($start + $size);
        $mask = ((1 << $size) - 1);
        return $binary & $mask;
    }

    private function  _readDatetime2($column)
    {
        /*DATETIME
       1 bit  sign           (1= non-negative, 0= negative)
       17 bits year*13+month  (year 0-9999, month 0-12)
       5 bits day            (0-31)
       5 bits hour           (0-23)
       6 bits minute         (0-59)
       6 bits second         (0-59)
       ---------------------------
       40 bits = 5 bytes
        */
        $data       = $this->readIntBeBySize(5);
        $year_month = self::_readBinarySlice($data, 1, 17, 40);
        $year       = (int)($year_month / 13);
        $month      = $year_month % 13;
        $day        = self::_readBinarySlice($data, 18, 5, 40);
        $hour       = self::_readBinarySlice($data, 23, 5, 40);
        $minute     = self::_readBinarySlice($data, 28, 6, 40);
        $second     = self::_readBinarySlice($data, 34, 6, 40);

        if ($hour < 10) {
            $hour ='0'.$hour;
        }

        if ($minute < 10) {
            $minute = '0'.$minute;
        }

        if ($second < 10) {
            $second = '0'.$second;
        }

        $time        = $year.'-'.$month.'-'.$day.' '.$hour.':'.$minute.':'.$second;
        $microsecond = $this->_addFspToTime($column);

        if ($microsecond) {
            $time .='.'.$microsecond;
        }

        return $time;
    }

    private function _addFspToTime($column)
    {
        /*
         Read and add the fractional part of time
         For more details about new date format:
         http://dev.mysql.com/doc/internals/en/date-and-time-data-type-representation.html
        */
        $read = 0;
        $time = '';
        $fsp = $column ['fsp'];
        if ($fsp == 1 || $fsp == 2) {
            $read = 1;
        } else if ($fsp == 3 || $fsp == 4) {
            $read = 2;
        } else if ($fsp == 5 || $fsp == 6) {
            $read = 3;
        }

        if ($read > 0) {
            $microsecond = $this->readIntBeBySize($read);
            if ($fsp % 2) {
                $microsecond = (int)($microsecond / 10);
            }
            $time = $microsecond * (10 ** (6 - $fsp));
        }

        return (string)$time;
    }

    private function _readDate()
    {
        $time = $this->readUint24();

        if ($time == 0) {  # nasty mysql 0000-00-00 dates
            return null;
        }

        $year  = ($time & ((1 << 15) - 1) << 9) >> 9;
        $month = ($time & ((1 << 4) - 1) << 5) >> 5;
        $day   = ($time & ((1 << 5) - 1));

        if ($year == 0 || $month == 0 || $day == 0) {
            return null;
        }

        return $year.'-'.$month.'-'.$day;
    }

    private function _getSet($column)
    {
        // we read set columns as a bitmap telling us which options are enabled
        $bit_mask = $this->readUIntBySize($column['size']);
        $sets = [];
        foreach ($column['set_values'] as $k => $item) {
            if ($bit_mask & (2 ** $k)) {
                $sets[] = $item;
            }
        }

        return $sets;
    }

    private function _getBit($column)
    {
        $res = '';
        for ($byte = 0; $byte < $column['bytes']; ++$byte) {
            $current_byte = '';
            $data = $this->readUInt8();
            if (0 === $byte) {
                if (1 === $column['bytes']) {
                    $end = $column['bits'];
                } else {
                    $end = $column['bits'] % 8;
                    if (0 === $end) {
                        $end = 8;
                    }
                }
            } else {
                $end = 8;
            }

            for ($bit = 0; $bit < $end; ++$bit) {
                if ($data & (1 << $bit)) {
                    $current_byte .= '1';
                } else {
                    $current_byte .= '0';
                }

            }
            $res .= strrev($current_byte);
        }

        return $res;
    }


    private function columnFormat($cols_bitmap)
    {
        $values = [];
        $l      = (int)((self::bitCount($cols_bitmap) + 7) / 8);

        # null bitmap length = (bits set in 'columns-present-bitmap'+7)/8
        # See http://dev.mysql.com/doc/internals/en/rows-event.html

        $null_bitmap     = $this->read($l);
        $nullBitmapIndex = 0;
        $fields          = $this->table_map[$this->schema_name][$this->table_name]['fields'];

        foreach ($fields as $i => $value) {
            $column   = $value;
            $name     = $value['name'];
            $unsigned = $value['unsigned'];

            if (self::BitGet($cols_bitmap, $i) == 0) {
                $values[$name] = null;
                continue;
            }
            if (self::_isNull($null_bitmap, $nullBitmapIndex)) {
                $values[$name] = null;
            }
            else if ($column['type'] == FieldType::TINY) {
                if ($unsigned) {
                    $values[$name] = $this->readUint8();
                } else {
                    $values[$name] = $this->readInt8();
                }
            }
            else if ($column['type'] == FieldType::SHORT) {
                if ($unsigned) {
                    $values[$name] = $this->readUint16();
                } else {
                    $values[$name] = $this->readInt16();
                }
            }
            else if ($column['type'] == FieldType::LONG) {
                if ($unsigned) {
                    $values[$name] = $this->readUint32();
                } else {
                    $values[$name] = $this->readInt32();
                }
            }
            else if ($column['type'] == FieldType::LONGLONG) {
                if ($unsigned) {
                    $values[$name] = $this->readUint64();
                } else {
                    $values[$name] = $this->readInt64();
                }
            }
            else if ($column['type'] == FieldType::INT24) {
                if ($unsigned) {
                    $values[$name] = $this->readUint24();
                } else {
                    $values[$name] = $this->readInt24();
                }
            }
            else if ($column['type'] == FieldType::FLOAT) {
                $values[$name] = unpack("f", $this->read(4))[1];
            }
            else if ($column['type'] == FieldType::DOUBLE) {
                $values[$name] = unpack("d", $this->read(8))[1];
            }
            else if ($column['type'] == FieldType::VARCHAR || $column['type'] == FieldType::STRING) {
                if ($column['max_length'] > 255) {
                    $values[$name] = $this->_readString(2, $column);
                } else {
                    $values[$name] = $this->_readString(1, $column);
                }
            }
            else if ($column['type'] == FieldType::NEWDECIMAL) {
                $values[$name] = $this->readNewDecimal($column);
            }
            else if ($column['type'] == FieldType::BLOB) {
                $values[$name] = self::_readString($column['length_size'], $column);
            }
            else if ($column['type'] == FieldType::DATETIME) {
                $values[$name] = $this->_readDatetime();
            }
            else if ($column['type'] == FieldType::DATETIME2) {
                $values[$name] = $this->_readDatetime2($column);
            }
            else if ($column['type'] == FieldType::TIME) {
                $values[$name] = self::_readTime();
            }
            else if ($column['type'] == FieldType::TIME2) {
                $values[$name] = self::_readTime2($column);
            }
            else if ($column['type'] == FieldType::TIMESTAMP) {
                $values[$name] = date('Y-m-d H:i:s', $this->readUint32());
            }
            else if ($column['type'] == FieldType::TIMESTAMP2){
                $time = date('Y-m-d H:i:s', $this->readIntBeBySize(4));
                $fsp = self::_addFspToTime($column);// 微妙
                if ('' !== $fsp) $time .= '.' . $fsp;
                $values[$name] = $time;
            }
            else if ($column['type'] == FieldType::DATE) {
                $values[$name] = $this->_readDate();
            }
            else if ($column['type'] == FieldType::YEAR) {
                // https://dev.mysql.com/doc/refman/5.7/en/year.html
                $year = $this->readUInt8();
                $values[$name] = 0 === $year ? null : 1900 + $year;
            }
            else if($column['type'] == FieldType::ENUM) {
                $values[$name] = $column['enum_values'][$this->readUintBySize($column['size']) - 1]??'';
            }
            else if($column['type'] == FieldType::SET) {
                $values[$name] = $this->_getSet($column);
            }
            else if($column['type'] == FieldType::BIT) {
                $values[$name] = $this->_getBit($column);
            }
            else if($column['type'] == FieldType::GEOMETRY) {
                $values[$name] = $this->_readString($column['length_size'], $column);
            }
            else if($column['type'] == FieldType::JSON) { //当字符串处理
                $values[$name] = $this->_readString($column['length_size'], $column);
            }

            $nullBitmapIndex += 1;
        }

        return $values;
    }


    public function addRow($event_type, $size)
    {
        //$table_id =
        $this->readTableId();

        if (in_array($event_type, [
            EventType::DELETE_ROWS_EVENT_V2,
            EventType::WRITE_ROWS_EVENT_V2,
            EventType::UPDATE_ROWS_EVENT_V2
        ])) {
            $this->read(2);
            //$flags = unpack('S', $this->read(2))[1];
            $extra_data_length = unpack('S', $this->read(2))[1];
            //$extra_data =
            $this->read($extra_data_length / 8);
        } else {
            $this->read(2);
            //$flags = unpack('S', $this->read(2))[1];
        }

        $columns_num = $this->readCodedBinary();
        $len         = (int)(($columns_num + 7) / 8);
        $bitmap      = $this->read($len);
        $rows        = [];

        while($this->hasNext($size)) {
            $rows[] = $this->columnFormat($bitmap);
        }

        $value = [
            "dbname" => $this->schema_name,
            "table"  => $this->table_name,
            "event"  => "write_rows",
            "data"   => $rows
        ];
        return $value;
    }

    public function delRow($event_type, $size)
    {
        //$table_id =
        $this->readTableId();

        if (in_array($event_type, [
            EventType::DELETE_ROWS_EVENT_V2,
            EventType::WRITE_ROWS_EVENT_V2,
            EventType::UPDATE_ROWS_EVENT_V2
        ])) {
            $this->read(2);
            //$flags = unpack('S', $this->read(2))[1];
            $extra_data_length = unpack('S', $this->read(2))[1];
            //$extra_data =
            $this->read($extra_data_length / 8);
        } else {
            $this->read(2);
            //$flags = unpack('S', $this->read(2))[1];
        }

        $columns_num = $this->readCodedBinary();
        $len        = (int)(($columns_num + 7) / 8);
        $bitmap     = $this->read($len);
        $rows        = [];

        while($this->hasNext($size)) {
            $rows[] = $this->columnFormat($bitmap);
        }

        $value = [
            "dbname" => $this->schema_name,
            "table"  => $this->table_name,
            "event"  => "delete_rows",
            "data"   => $rows
        ];
        return $value;
    }

    public function updateRow($event_type, $size)
    {
        $table_id =$this->readTableId(); //6
        $this->read(2); #$flags

        if (in_array($event_type, [
            EventType::DELETE_ROWS_EVENT_V2,
            EventType::WRITE_ROWS_EVENT_V2,
            EventType::UPDATE_ROWS_EVENT_V2
        ])) {
            $extra_data_length = $this->readUint16(); #extra-data-length
            $this->read($extra_data_length / 8); #extra-data
        }

        //Body
        $columns_num = $this->readCodedBinary();
        $len         = (int)(($columns_num + 7) / 8);
        $bitmap1     = $this->read($len);
        $bitmap2     = $this->read($len);

        $rows = [];
        while ($this->hasNext($size)) {
            $rows[] = [
                "old" => $this->columnFormat($bitmap1),
                "new" => $this->columnFormat($bitmap2)
            ];
        }

        $value = [
            "dbname" => $this->schema_name,
            "table"  => $this->table_name,
            "event"  => "update_rows",
            "data"   => $rows
        ];

        return $value;
    }

    public function eventQuery($event_size_without_header)
    {
        $this->advance(4); //slave_proxy_id 4
        $executionTime = $this->readUInt32(); //execution time 4
        $schemaLength = $this->readUInt8(); //schema length 1
        $this->advance(2); //error code 2
        $statusVarsLength = $this->readUInt16(); //status-vars length 2
        // 13 end
        $this->advance($statusVarsLength); //status-vars 内容
        $schema = $this->read($schemaLength);//schema 内容
        $this->advance(1); // 空白符 1
        $query = $this->read($event_size_without_header - 13 - $statusVarsLength - $schemaLength - 1);

        $value = [
            "dbname" => $schema,
            "event"  => "query",
            "data"   => $query
        ];

        return $value;
    }
    public function eventXid()
    {
        $xid = $this->readUint64();

        $value = [
            "dbname" => $this->schema_name,
            "event"  => "xid",
            "data"   => $xid
        ];

        return $value;
    }

    private function _readTime()
    {
        $data = $this->readUInt24();
        if (0 === $data) {
            return '00:00:00';
        }

        return sprintf('%s%02d:%02d:%02d', $data < 0 ? '-' : '', $data / 10000, ($data % 10000) / 100, $data % 100);
    }

    private function _readTime2($column)
    {
        /*
        https://dev.mysql.com/doc/internals/en/date-and-time-data-type-representation.html
        TIME encoding for nonfractional part:
        1 bit sign    (1= non-negative, 0= negative)
        1 bit unused  (reserved for future extensions)
        10 bits hour   (0-838)
        6 bits minute (0-59)
        6 bits second (0-59)
        ---------------------
        24 bits = 3 bytes
        */
        $data = $this->readIntBeBySize(3);
        $sign = 1;

        if (!self::_readBinarySlice($data, 0, 1, 24)) {
            $sign = -1;
        }

        if ($sign == -1) {
            # negative integers are stored as 2's compliment
            # hence take 2's compliment again to get the right value.
            $data = ~$data + 1;
        }

        $hours        = $sign * self::_readBinarySlice($data, 2, 10, 24);
        $minutes      = self::_readBinarySlice($data, 12, 6, 24);
        $seconds      = self::_readBinarySlice($data, 18, 6, 24);
        $microseconds = self::_addFspToTime($column);
        $t            = $hours.':'.$minutes.':'.$seconds;

        if ($microseconds) {
            $t .= '.'.$microseconds;
        }

        return $t;
    }
}