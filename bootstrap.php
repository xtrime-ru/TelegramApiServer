<?php
//Check if autoload has been already loaded (in case plugin installed in existing project)
use TelegramApiServer\Logger;

$root = __DIR__;
if (!file_exists($root . '/vendor/autoload.php')) {
    $root = __DIR__ . '/../../..';
}
require_once $root . '/vendor/autoload.php';
chdir($root);

//Check if root env file hash been loaded (in case plugin installed in existing project)
if (!getenv('SERVER_ADDRESS')) {
    Dotenv\Dotenv::createImmutable($root)->load();
}

if (!function_exists('debug')) {
    function debug(string $message, array $context) {
        Logger::getInstance()->debug($message, $context);
    }
}
if (!function_exists('info')) {
    function info(string $message, array $context = []) {
        Logger::getInstance()->info($message, $context);
    }
}
if (!function_exists('notice')) {
    function notice($message, array $context = []) {
        Logger::getInstance()->notice($message, $context);
    }
}
if (!function_exists('warning')) {
    function warning(string $message, array $context = []) {
        Logger::getInstance()->warning($message, $context);
    }
}
if (!function_exists('error')) {
    function error(string $message, array $context = []) {
        Logger::getInstance()->error($message, $context);
    }
}
if (!function_exists('critical')) {
    function critical(string $message, array $context = []) {
        Logger::getInstance()->critical($message, $context);
    }
}
if (!function_exists('alert')) {
    function alert(string $message, array $context = []) {
        Logger::getInstance()->alert($message, $context);
    }
}
if (!function_exists('emergency')) {
    function emergency(string $message, array $context = []) {
        Logger::getInstance()->emergency($message, $context);
    }
}
