<?php
namespace Wing\Bin\Constant;
/**
 * Created by PhpStorm.
 * User: yuyi
 * Date: 17/9/8
 * Time: 23:20
 */
class EventType
{
	const UNKNOWN_EVENT             = 0; #0x00;
	const START_EVENT_V3            = 1; #0x01;
	const QUERY_EVENT               = 2; #0x02;
	const STOP_EVENT                = 3; #0x03;
	const ROTATE_EVENT              = 4; #0x04;
	const INTVAR_EVENT              = 5; #0x05;
	const LOAD_EVENT                = 6; #0x06;
	const SLAVE_EVENT               = 7; #0x07;
	const CREATE_FILE_EVENT         = 8; #0x08;
	const APPEND_BLOCK_EVENT        = 9; #0x09;
	const EXEC_LOAD_EVENT           = 10; #0x0a;
	const DELETE_FILE_EVENT         = 11; #0x0b;
	const NEW_LOAD_EVENT            = 12; #0x0c;
	const RAND_EVENT                = 13; #0x0d;
	const USER_VAR_EVENT            = 14; #0x0e;
	const FORMAT_DESCRIPTION_EVENT  = 15; #0x0f;

	const XID_EVENT					= 16; #0x10; //事务提交时写入
	const BEGIN_LOAD_QUERY_EVENT	= 17; #0x11;
	const EXECUTE_LOAD_QUERY_EVENT	= 18; #0x12;

	// Row-Based Binary Logging
	// TABLE_MAP_EVENT,WRITE_ROWS_EVENT
	// UPDATE_ROWS_EVENT,DELETE_ROWS_EVENT
	const TABLE_MAP_EVENT          	= 19; #0x13;

	// MySQL 5.1.5 to 5.1.17,
	const PRE_GA_WRITE_ROWS_EVENT  	= 20; #0x14;
	const PRE_GA_UPDATE_ROWS_EVENT 	= 21; #0x15;
	const PRE_GA_DELETE_ROWS_EVENT 	= 22; #0x16;

	// MySQL 5.1.15 to 5.6.x
	const WRITE_ROWS_EVENT_V1  		= 23; #0x17;
	const UPDATE_ROWS_EVENT_V1 		= 24; #0x18;
	const DELETE_ROWS_EVENT_V1 		= 25; #0x19;


	const INCIDENT_EVENT       		= 26; #0x1a;
	const HEARTBEAT_LOG_EVENT  		= 27; #0x1b;
	const IGNORABLE_LOG_EVENT  		= 28; #0x1c;
	const ROWS_QUERY_LOG_EVENT 		= 29; #0x1d;

	// MySQL 5.6.x
	const WRITE_ROWS_EVENT_V2  		= 30; #0x1e;
	const UPDATE_ROWS_EVENT_V2 		= 31; #0x1f;
	const DELETE_ROWS_EVENT_V2 		= 32; #0x20;

    const GTID_LOG_EVENT			= 33; #0x21; // GTID事务
    const ANONYMOUS_GTID_LOG_EVENT	= 34; #0x22; // 匿名事务
    const PREVIOUS_GTIDS_LOG_EVENT	= 35; #0x23; //用于表示上一个binlog最后一个gitd的位置，每个binlog只有一个
}