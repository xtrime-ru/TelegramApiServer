<?php

use danog\MadelineProto\Logger;
use TelegramApiServer\EventObservers\LogObserver;

$settings = [
    'server' => [
        'address' => (string)getenv('SERVER_ADDRESS'),
        'port' => (int)getenv('SERVER_PORT'),
    ],
    'telegram' => [
        'app_info' => [ // obtained in https://my.telegram.org
            'api_id' => (int)getenv('TELEGRAM_API_ID'),
            'api_hash' => (string)getenv('TELEGRAM_API_HASH'),
        ],
        'logger' => [ // Logger settings
            'logger' => Logger::CALLABLE_LOGGER, //  0 - Logs disabled, 3 - echo logs.
            'logger_param' => LogObserver::class . '::log',
            'logger_level' => (int)getenv('LOGGER_LEVEL'), // Logging level, available logging levels are: ULTRA_VERBOSE - 5, VERBOSE - 4 , NOTICE - 3, WARNING - 2, ERROR - 1, FATAL_ERROR - 0.
        ],
        'connection_settings' => [
            'all' => [
                'proxy' => '\SocksProxy',
                'proxy_extra' => [
                    'address' => (string)getenv('TELEGRAM_PROXY_ADDRESS'),
                    'port' => (int)getenv('TELEGRAM_PROXY_PORT'),
                    'username' => getenv('TELEGRAM_PROXY_USERNAME'),
                    'password' => getenv('TELEGRAM_PROXY_PASSWORD'),
                ]
            ],
            'media_socket_count' => [
                'max' => 1000,
            ]
        ],
        'serialization' => [
            'serialization_interval' => 60,
        ],
        'db' => [
            'type' => getenv('DB_TYPE'),
            getenv('DB_TYPE') => [
                'host' => (string)getenv('DB_HOST'),
                'port' => (int)getenv('DB_PORT'),
                'user' => getenv('DB_USER'),
                'password' => getenv('DB_PASSWORD'),
                'database' => getenv('DB_DATABASE'),
                'max_connections' => (int)getenv('DB_MAX_CONNECTIONS'),
                'idle_timeout' => (int)getenv('DB_IDLE_TIMEOUT'),
                'cache_ttl' => getenv('DB_CACHE_TTL'),
            ]
        ],
        'download' => [
            'report_broken_media' => false,
        ],
    ],
    'api' => [
        'ip_whitelist' => array_filter(
            array_map(
                'trim',
                explode(',', getenv('IP_WHITELIST'))
            )
        ),
    ],
    'health_check' => [
        'enabled' => (bool)filter_var((string)getenv('HEALTHCHECK_ENABLED'), FILTER_VALIDATE_BOOL),
        'interval' => ((int)getenv('HEALTHCHECK_INTERVAL') ?: 30),
        'timeout' => ((int)getenv('HEALTHCHECK_REQUEST_TIMEOUT') ?: 60),
    ]
];

if (empty($settings['telegram']['connection_settings']['all']['proxy_extra']['address'])) {
    $settings['telegram']['connection_settings']['all']['proxy'] = '\Socket';
    $settings['telegram']['connection_settings']['all']['proxy_extra'] = [];
}

if (empty($settings['telegram']['app_info']['api_id'])) {
    throw new InvalidArgumentException('Need to fill TELEGRAM_API_ID in .env.docker or .env');
}

return $settings;