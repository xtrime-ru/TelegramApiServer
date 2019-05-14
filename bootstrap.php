<?php
//Check if autoload has been already loaded (in case plugin installed in existing project)
$root = __DIR__;
if (!class_exists('TelegramSwooleClient')) {
    if (!file_exists($root . '/vendor/autoload.php')) {
        $root = __DIR__ . '/../../..';
    }
    require $root . '/vendor/autoload.php';
    chdir($root);
}
//Check if root env file hash been loaded (in case plugin installed in existing project)
if (!getenv('SWOOLE_SERVER_ADDRESS')) {
    Dotenv\Dotenv::create($root, '.env')->load();
}
