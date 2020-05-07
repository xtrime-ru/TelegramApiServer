<?php

use TelegramApiServer\Logger;

$root = __DIR__;

//Composer init
{
    if (!file_exists($root . '/vendor/autoload.php')) {
        if (file_exists(__DIR__ . '/../../..' . '/vendor/autoload.php')) {
            $root = __DIR__ . '/../../..';
        } else {
            system('composer install -o --no-dev');
        }
    }

    define('ROOT_DIR', $root);
    chdir(ROOT_DIR);
    require_once ROOT_DIR . '/vendor/autoload.php';
}

//Config init
{
    if (!getenv('SERVER_ADDRESS')) {
        if ($options['docker']) {
            $envSource = file_exists(ROOT_DIR . '/.env') ? ROOT_DIR . '/.env' : ROOT_DIR . '/.env.example';
            $envContent = file_get_contents($envSource);
            $envContent = str_replace(
                ['SERVER_ADDRESS=127.0.0.1', 'IP_WHITELIST=127.0.0.1'],
                ['SERVER_ADDRESS=0.0.0.0', 'IP_WHITELIST='],
                $envContent
            );
            file_put_contents(ROOT_DIR . '/.env', $envContent);
        } elseif (!file_exists(ROOT_DIR . '/.env')) {
            copy( ROOT_DIR . '/.env.example', ROOT_DIR . '/.env');
        }

        Dotenv\Dotenv::createImmutable(ROOT_DIR)->load();
    }
}

$memoryLimit = getenv('MEMORY_LIMIT');
if ($memoryLimit !== false) {
    ini_set('memory_limit', $memoryLimit);
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
