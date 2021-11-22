<?php

use TelegramApiServer\Logger;
use TelegramApiServer\Migrations\EnvUpgrade;

$root = __DIR__;
const ENV_VERSION='1';

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
        EnvUpgrade::mysqlToDbPrefix();

        $envFile = $options['env'];
        if (empty($envFile)) {
            throw new InvalidArgumentException('Env file not defined');
        }
        $envPath = ROOT_DIR . '/' . $envFile;
        $envPathExample = $envPath . '.example';

        if (!is_file($envPath) || filesize($envPath) === 0) {
            if (!is_file($envPathExample) || filesize($envPathExample) === 0) {
                throw new InvalidArgumentException("Env files not found or empty: {$envPath}, {$envPathExample}");
            }
            //Dont use copy because of docker symlinks
            $envContent = file_get_contents($envPathExample);
            if (strlen($envContent) > 0) {
                $result = file_put_contents($envPath, $envContent);
                if (!$result) {
                    throw new RuntimeException("Cant write file: {$envPath}");
                }
            } else {
                throw new RuntimeException("Cant read file: {$envPathExample}");
            }
        }

        Dotenv\Dotenv::createImmutable(ROOT_DIR, $envFile)->load();

        if (getenv('VERSION') !== ENV_VERSION) {
            Logger::getInstance()->critical("Env version mismatch. Update {$envPath} from {$envPathExample}", [
                'VERSION in .env' => getenv('VERSION'),
                'required' => ENV_VERSION
            ]);
            throw new RuntimeException('.env version mismatch');
        }
    }
}

if ($memoryLimit = getenv('MEMORY_LIMIT')) {
    ini_set('memory_limit', $memoryLimit);
}

if ($timezone = getenv('TIMEZONE')) {
    date_default_timezone_set($timezone);
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
