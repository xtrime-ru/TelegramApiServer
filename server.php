<?php

chdir(__DIR__);

require_once __DIR__ . '/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    throw new \Exception('Start in CLI');
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
    'session' => (array) ($options['session'] ?? $options['s'] ?? ''),
    'help' => isset($options['help']),
];

if ($options['help']) {
    $help = 'Fast, simple, async php telegram parser: MadelineProto + Swoole Server

usage: php server.php [--help] [-a=|--address=127.0.0.1] [-p=|--port=9503] [-s=|--session=]

Options:
        --help      Show this message
    -a  --address   Server ip (optional) (example: 127.0.0.1)
    -p  --port      Server port (optional) (example: 9503)
    -s  --session   Prefix for session file (optional) (example: xtrime). 
                    Multiple sessions can be used via CombinedAPI. Example "--session=user --session=bot"
                    If running multiple sessions, then "session" parameter must be provided with every request.
                    See README for example requests.
    

Also all options can be set in .env file (see .env.example)

Example:
    php server.php
    
';
    echo $help;
    exit;
}

$sessionFiles = [];
foreach ($options['session'] as $session) {
    if (!$session) {
        $session = 'session';
    }
    $session = TelegramApiServer\Client::getSessionFile($session);
    $sessionFiles[$session] = '';
}

$client = new TelegramApiServer\Client($sessionFiles);
new TelegramApiServer\Server($client, $options);