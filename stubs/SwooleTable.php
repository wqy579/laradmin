<?php

namespace Swoole {
    if (! class_exists('Table')) {
        class Table
        {
            const TYPE_STRING = 1;

            const TYPE_INT = 2;

            const TYPE_FLOAT = 3;

            const TYPE_BIGINT = 4;

            const TYPE_DOUBLE = 5;

            const TYPE_CHAR = 6;

            const TYPE_BLOB = 7;

            const TYPE_UNIXTIME = 8;

            const TYPE_DATETIME = 9;

            const TYPE_JSON = 10;

            public function __construct($size) {}

            public function column($name, $type, $size = 0)
            {
                return $this;
            }

            public function set(array $definition) {}

            public function create()
            {
                return true;
            }

            public function get($key)
            {
                return null;
            }

            public function setItem($key, array $value) {}

            public function del($key) {}

            public function exists($key)
            {
                return false;
            }

            public function incr($key, $column, $step = 1) {}

            public function decr($key, $column, $step = 1) {}

            public function pack($value)
            {
                return serialize($value);
            }

            public function unpack($data)
            {
                return unserialize($data);
            }

            public function start()
            {
                return true;
            }

            public function shutdown() {}

            public function isset($key)
            {
                return false;
            }

            public function getCurrentSize()
            {
                return 0;
            }

            public function __isset($name)
            {
                return false;
            }

            public function __get($name)
            {
                return null;
            }

            public function __set($name, $value) {}
        }
    }
}
