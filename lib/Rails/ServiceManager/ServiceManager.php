<?php
namespace Rails\ServiceManager;

/**
 * Beta test. Like everything else.
 */
class ServiceManager
{
    protected $serviceList = [];

    protected $instances = [];

    public function __construct()
    {
        $this->serviceList = [
            'inflector' => 'Rails\ActiveSupport\Inflector\Inflector',
            'i18n' => 'Rails\I18n\I18n',
            'rails.cache' => function () {
                $cache = new \Rails\Cache\Cache('file');
            }
        ];
    }

    public function get($name)
    {
        if (!isset($this->instances[$name])) {
            if (isset($this->serviceList[$name])) {
                if ($this->serviceList[$name] instanceof \Closure) {
                    $this->instances[$name] = $this->serviceList[$name]();
                } elseif (is_string($this->serviceList[$name])) {
                    $this->instances[$name] = new $this->serviceList[$name];
                } else {
                    $this->instances[$name] = $this->serviceList[$name];
                }
            } else {
                throw new Exception\RuntimeException(
                    sprintf("Unknown service %s", $name)
                );
            }
        }
        return $this->instances[$name];
    }

    public function set($name, $value)
    {
        if (isset($this->serviceList[$name])) {
            throw new Exception\RuntimeException(
                sprintf("Service %s already exists", $name)
            );
        }
        $this->serviceList[$name] = $value;
    }
}