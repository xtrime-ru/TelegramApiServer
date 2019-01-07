<?php

require_once __DIR__ . '/bootstrap.php';

if (PHP_SAPI !== 'cli') {
    throw new \Exception('Start in CLI');
}

$shortopts = 'a::p::';
$longopts  = [
    'address::', // ip адресс сервера, необязательное значение
    'port::',  // порт сервера, необязательное значение
    'help', //нужна ли справка?
];
$options = getopt($shortopts, $longopts);
$options = [
    'address'   => $options['address'] ?? $options['a'] ?? '',
    'port'      => $options['port'] ?? $options['p'] ?? '',
    'id'        => $options['id'] ?? $options['i'] ?? '',
    'hash'      => $options['hash'] ?? $options['h'] ?? '',
    'help'      => isset($options['help']),
];

if ($options['help']) {
    $help = 'Fast, simple, async php telegram parser: MadelineProto + Swoole Server

usage: php server.php [--help] [-a|--address=127.0.0.1] [-p|--port=9503]

Options:
        --help      Show this message
    -a  --address   Server ip (optional) (example: 127.0.0.1)
    -p  --port      Server port (optional) (example: 9503)

Also all options can be set in .env file (see .env.example)

Example:
    php server.php
    
';
    echo $help;
    exit;
}

$client = new \TelegramSwooleClient\Client();
new TelegramSwooleClient\Server($client, $options);