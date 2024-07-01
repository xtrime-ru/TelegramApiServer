<?php

use danog\MadelineProto\Logger;
use TelegramApiServer\EventObservers\LogObserver;

use function Amp\Socket\SocketAddress\fromString;

$settings = [
    'server' => [
        'address' => (string)getenv('SERVER_ADDRESS'),
        'port' => (int)getenv('SERVER_PORT'),
        'real_ip_header' => (string)(getenv('REAL_IP_HEADER') ?? ''),
    ],
    'telegram' => [
        'app_info' => [ // obtained in https://my.telegram.org
            'api_id' => (int)getenv('TELEGRAM_API_ID'),
            'api_hash' => (string)getenv('TELEGRAM_API_HASH'),
        ],
        'logger' => [ // Logger settings
            'type' => Logger::CALLABLE_LOGGER, //  0 - Logs disabled, 3 - echo logs.
            'extra' => LogObserver::log(...),
            'level' => (int)getenv('LOGGER_LEVEL'), // Logging level, available logging levels are: ULTRA_VERBOSE - 5, VERBOSE - 4 , NOTICE - 3, WARNING - 2, ERROR - 1, FATAL_ERROR - 0.
        ],
        'rpc' => [
            'flood_timeout' => 5,
            'rpc_drop_timeout' => 20,
        ],
        'connection' => [
            'max_media_socket_count' => 10,
            'proxies' => [
                '\danog\MadelineProto\Stream\Proxy\SocksProxy' => [
                    [
                        "address" => (string)getenv('TELEGRAM_PROXY_ADDRESS'),
                        "port"=> (int)getenv('TELEGRAM_PROXY_PORT'),
                        "username"=> (string)getenv('TELEGRAM_PROXY_USERNAME'),
                        "password"=> (string)getenv('TELEGRAM_PROXY_PASSWORD'),
                    ],
                ]
            ]
        ],
        'serialization' => [
            'interval' => 600,
        ],
        'db' => [
            'enable_min_db' => (bool)filter_var((string)getenv('DB_ENABLE_MIN_DATABASE'), FILTER_VALIDATE_BOOL),
            'enable_file_reference_db' => (bool)filter_var((string)getenv('DB_ENABLE_FILE_REFERENCE_DATABASE'), FILTER_VALIDATE_BOOL),
            'type' => (string)getenv('DB_TYPE'),
            getenv('DB_TYPE') => [
                'uri' => 'tcp://' . getenv('DB_HOST') . ':' . (int)getenv('DB_PORT'),
                'username' => (string)getenv('DB_USER'),
                'password' => (string)getenv('DB_PASSWORD'),
                'database' => (string)getenv('DB_DATABASE'),
                'max_connections' => (int)getenv('DB_MAX_CONNECTIONS'),
                'idle_timeout' => (int)getenv('DB_IDLE_TIMEOUT'),
                'cache_ttl' => (string)getenv('DB_CACHE_TTL'),
                'serializer' => ((string)getenv('DB_SERIALIZER')) ?: 'serialize',
            ]
        ],
        'files' => [
            'report_broken_media' => false,
            'download_parallel_chunks' => 20,
        ],
        'metrics' => [
            'enable_prometheus_collection' => true, //(bool)getenv("PROMETHEUS_BIND_TO"),
            'metrics_bind_to' => fromString("0.0.0.0:12345")
        ]
    ],
    'api' => [
        'ip_whitelist' => array_filter(
            array_map(
                'trim',
                explode(',', (string)getenv('IP_WHITELIST'))
            )
        ),
        'passwords' => (array)json_decode((string)getenv('PASSWORDS'), true),
        'bulk_interval' => (float)getenv('REQUESTS_BULK_INTERVAL')
    ],
];

if (empty($settings['telegram']['connection']['proxies']['\danog\MadelineProto\Stream\Proxy\SocksProxy'][0]['address'])) {
    $settings['telegram']['connection']['proxies'] = [];
}

if (empty($settings['telegram']['app_info']['api_id'])) {
    throw new InvalidArgumentException('Need to fill TELEGRAM_API_ID in .env.docker or .env');
}

return $settings;
