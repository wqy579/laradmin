<?php

namespace Swoole;

class Table
{
    public function __construct($size) {}

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
