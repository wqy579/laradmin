<?php
namespace Swoole {
    if (!class_exists('Table')) {
        class Table {
            public function __construct($size) {}
            public function set(array $definition) {}
            public function create() { return true; }
            public function get($key) { return null; }
            public function setItem($key, array $value) {}
            public function del($key) {}
            public function exists($key) { return false; }
            public function incr($key, $column, $step = 1) {}
            public function decr($key, $column, $step = 1) {}
            public function pack($value) { return serialize($value); }
            public function unpack($data) { return unserialize($data); }
            public function start() { return true; }
            public function shutdown() {}
        }
    }
}
