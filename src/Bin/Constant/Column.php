<?php namespace Wing\Bin\Constant;
/**
 * Created by PhpStorm.
 * User: yuyi
 * Date: 17/9/9
 * Time: 06:58
 */
class Column
{
    const NULL                  = 251;
    const UNSIGNED_CHAR         = 251;
    const UNSIGNED_SHORT        = 252;
    const UNSIGNED_INT24        = 253;
    const UNSIGNED_INT64        = 254;
    const UNSIGNED_CHAR_LENGTH  = 1;
    const UNSIGNED_SHORT_LENGTH = 2;
    const UNSIGNED_INT24_LENGTH = 3;
    const UNSIGNED_INT64_LENGTH = 8;

    const JSONB_TYPE_SMALL_OBJECT = 0x00;
    const JSONB_TYPE_LARGE_OBJECT = 0x01;
    const JSONB_TYPE_SMALL_ARRAY = 0x02;
    const JSONB_TYPE_LARGE_ARRAY = 0x03;
    const JSONB_TYPE_LITERAL = 0x04;
    const JSONB_TYPE_INT16 = 0x05;
    const JSONB_TYPE_UINT16 = 0x06;
    const JSONB_TYPE_INT32 = 0x07;
    const JSONB_TYPE_UINT32 = 0x08;
    const JSONB_TYPE_INT64 = 0x09;
    const JSONB_TYPE_UINT64 = 0x0A;
    const JSONB_TYPE_DOUBLE = 0x0B;
    const JSONB_TYPE_STRING = 0x0C;
    const JSONB_TYPE_OPAQUE = 0x0F;

    const JSONB_LITERAL_NULL = 0x00;
    const JSONB_LITERAL_TRUE = 0x01;
    const JSONB_LITERAL_FALSE = 0x02;
}