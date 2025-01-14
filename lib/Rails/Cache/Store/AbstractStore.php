<?php
namespace Rails\Cache\Store;

abstract class AbstractStore
{
    abstract public function __construct(array $config);

    abstract public function read($key, array $params = []);

    abstract public function write($key, $val, array $params);

    abstract public function delete($key, array $params);

    abstract public function exists($key, array $params);

    public function cleanup(array $options = [])
    {
        throw new Exception\NotImplementedException(
            sprintf("%s does not support cleanup", __CLASS__)
        );
    }

    public function clear(array $options = [])
    {
        throw new Exception\NotImplementedException(
            sprintf("%s does not support clear", __CLASS__)
        );
    }

    public function deleteMatched($matcher, array $options = [])
    {
        throw new Exception\NotImplementedException(
            sprintf("%s does not support delete matched", __CLASS__)
        );
    }
}