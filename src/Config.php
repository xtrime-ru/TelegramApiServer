<?php


namespace TelegramApiServer;


class Config
{
    private static ?Config $instance = null;
    private array $config;

    public static function getInstance(): Config
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
        $this->config = include ROOT_DIR . '/config.php';
    }

    /**
     * @param string $key
     * @param null $default
     * @return mixed|null
     */
    public function get($key = '', $default = null)
    {
        return $this->findByKey($key) ?? $default;
    }

    private function findByKey($key)
    {
        $key = (string)$key;
        $path = explode('.', $key);

        $value = &$this->config;
        foreach ($path as $pathKey) {
            if (!is_array($value) || !array_key_exists($pathKey, $value)) {
                return null;
            }
            $value = &$value[$pathKey];
        }

        return $value;
    }

}