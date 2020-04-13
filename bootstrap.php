<?php

use TelegramApiServer\Logger;

$root = __DIR__;

//Composer init
{
    if (!file_exists($root . '/vendor/autoload.php')) {
        if (file_exists(__DIR__ . '/../../..' . '/vendor/autoload.php')) {
            $root = __DIR__ . '/../../..';
        } else {
            if (system("composer 2>/dev/null") == "") {
                $mdn = getpwd();
                chdir(dirname(__FILE__));
                echo("Installing composer..".PHP_EOL);
                $actual_sha = file_get_contents("https://composer.github.io/installer.sig");
                copy('https://getcomposer.org/installer', './composer-setup.php');
                if (hash_file('sha384', './composer-setup.php') == $actual_sha) {
                    system("php ./composer-setup.php");
                    unlink("composer-setup.php");
                }
                echo("Installation is finished.");
                system('php composer.phar install -o --no-dev');
                chdir($mdn);
            }
            else system('composer install -o --no-dev');
        }
    }

    define('ROOT_DIR', $root);
    chdir(ROOT_DIR);
    require_once ROOT_DIR . '/vendor/autoload.php';
}

//Config init
{

    if (!getenv('SERVER_ADDRESS')) {
        if (!file_exists(ROOT_DIR . '/.env')) {
            $envSource = file_exists(ROOT_DIR . '/.env') ? ROOT_DIR . '/.env' : ROOT_DIR . '/.env.example';
            $envContent = file_get_contents($envSource);
            if (isset($options['docker'])) {
                $envContent = str_replace(
                    ['SERVER_ADDRESS=127.0.0.1', 'IP_WHITELIST=127.0.0.1'],
                    ['SERVER_ADDRESS=0.0.0.0', 'IP_WHITELIST='],
                    $envContent
                );
            }
            file_put_contents(ROOT_DIR . '/.env', $envContent);
        }
        Dotenv\Dotenv::createImmutable(ROOT_DIR)->load();
    }
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
