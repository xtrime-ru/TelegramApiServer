<?php

use TelegramApiServer\Migrations\SessionsMigration;
use TelegramApiServer\Migrations\SwooleToAmpMigration;

chdir(__DIR__);

require_once __DIR__ . '/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Start in CLI');
}

$shortopts = 'a::p::s::';
$longopts = [
    'address::', // ip адресс сервера, необязательное значение
    'port::',  // порт сервера, необязательное значение
    'session::', //префикс session файла
    'help', //нужна ли справка?
];
$options = getopt($shortopts, $longopts);
$options = [
    'address' => $options['address'] ?? $options['a'] ?? '',
    'port' => $options['port'] ?? $options['p'] ?? '',
    'session' => (array) ($options['session'] ?? $options['s'] ?? []),
    'help' => isset($options['help']),
];

if ($options['help']) {
    $help = 'Fast, simple, async php telegram parser: MadelineProto + Swoole Server

usage: php server.php [--help] [-a=|--address=127.0.0.1] [-p=|--port=9503] [-s=|--session=]

Options:
        --help      Show this message
        
    -a  --address   Server ip (optional) (default: 127.0.0.1)
                    To listen external connections use 0.0.0.0 and fill IP_WHITELIST in .env
                    
    -p  --port      Server port (optional) (default: 9503)
    
    -s  --session   Name for session file (optional)
                    Multiple sessions can be specified: "--session=user --session=bot"
                    
                    Each session is stored in `sessions/{$session}.madeline`. 
                    Nested folders supported.
                    See README for more examples.

Also all options can be set in .env file (see .env.example)

Example:
    php server.php
    
';
    echo $help;
    exit;
}

SessionsMigration::move(__DIR__);
SwooleToAmpMigration::check();

$sessionFiles = [];
foreach ($options['session'] as $session) {
    $session = trim($session);
    if (mb_substr($session, -1) === '/') {
        throw new InvalidArgumentException('Session name specified as directory');
    }

    $session = TelegramApiServer\Client::getSessionFile($session);

    if (preg_match('~['.preg_quote('*?[]!', '~').']~',$session)) {
        $sessions = glob($session);
    } else {
        $sessions[] = $session;
    }

    $sessions = array_filter($sessions);
    foreach ($sessions as $file) {
        $file = str_replace('//','/', $file);
        TelegramApiServer\Client::checkOrCreateSessionFolder($file, __DIR__);
        $sessionFiles[$file] = null;
    }
}

new TelegramApiServer\Server\Server(
    new TelegramApiServer\Client(),
    $options,
    array_keys($sessionFiles)
);