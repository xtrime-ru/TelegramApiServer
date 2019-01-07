<?php


namespace TelegramSwooleClient;


class Config
{
    /**
     * @var self
     */
    private static $instance;
    private $config;


    public static function getInstance()
    {
        if (null === static::$instance) {
            static::$instance = new static();
        }

        return static::$instance;
    }

    /**
     * is not allowed to call from outside to prevent from creating multiple instances,
     * to use the singleton, you have to obtain the instance from Singleton::getInstance() instead
     */
    private function __construct()
    {
        $this->config = include __DIR__ . '/../config.php';
    }

    /**
     * prevent the instance from being cloned (which would create a second instance of it)
     */
    private function __clone()
    {
    }

    /**
     * prevent from being unserialized (which would create a second instance of it)
     */
    private function __wakeup()
    {
    }

    /**
     * @param string $key
     * @param null $default
     * @return mixed|null
     */
    public function getConfig($key = '', $default = null) {
        if ($key) {
            return $this->config[$key] ?? $default;
        }
        return $this->config;
    }

}