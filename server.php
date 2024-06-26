<?php

use TelegramApiServer\Files;
use TelegramApiServer\Migrations\StartUpFixes;

if (PHP_SAPI !== 'cli') {
    throw new RuntimeException('Start in CLI');
}

$shortopts = 'a::p::s::e::';
$longopts = [
    'address::', // ip адресс сервера
    'port::',  // порт сервера
    'session::', //префикс session файла
    'env::', //путь до .env файла
    'docker::', //включить настройки для запуска внутри docker
    'help', //нужна ли справка?
];
$options = getopt($shortopts, $longopts);
$options = [
    'address' => $options['address'] ?? $options['a'] ?? '',
    'port' => $options['port'] ?? $options['p'] ?? '',
    'session' => (array)($options['session'] ?? $options['s'] ?? []),
    'env' => $options['env'] ?? $options['e'] ?? '.env',
    'docker' => isset($options['docker']),
    'help' => isset($options['help']),
];

if ($options['help']) {
    $help = 'Fast, simple, async php telegram parser: MadelineProto + Swoole Server

usage: php server.php [--help] [-a=|--address=127.0.0.1] [-p=|--port=9503] [-s=|--session=]  [-e=|--env=.env] [--docker]

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

    -e  --env       .env file name. (default: .env). 
                    Helpful when need multiple instances with different settings
    
        --docker    Apply some settings for docker: add docker network to whitelist.

Also some options can be set in .env file (see .env.example)

Example:
    php server.php
    
';
    echo $help;
    exit;
}

require_once __DIR__ . '/bootstrap.php';

$sessions = [];
foreach ($options['session'] as $session) {
    $session = trim($session);
    if (mb_substr($session, -1) === '/') {
        throw new InvalidArgumentException('Session name specified as directory');
    }

    $session = Files::getSessionFile($session);

    if (preg_match('~[' . preg_quote('*?[]!', '~') . ']~', $session)) {
        $sessions = Files::globRecursive($session);
    } else {
        $sessions[] = $session;
    }

    $sessions = array_filter($sessions);
    $sessions = array_unique($sessions);
}

StartUpFixes::fix();

new TelegramApiServer\Server\Server(
    $options,
    $sessions
);