<?php
//Check if autoload has been already loaded (in case plugin installed in existing project)
if (!class_exists('TelegramSwooleClient')) {
    require __DIR__ . '/vendor/autoload.php';
}
//Check if root env file hash been loaded (in case plugin installed in existing project)
if (!getenv('SWOOLE_SERVER_ADDRESS')){
    (new Dotenv\Dotenv(__DIR__))->load();
}
