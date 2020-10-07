<?php

$settings = [
    'server' => [
        'address' => (string)getenv('SERVER_ADDRESS'),
        'port' => (string)getenv('SERVER_PORT'),
    ],
    'telegram' => [
        'app_info' => [ // obtained in https://my.telegram.org
            'api_id' => getenv('TELEGRAM_API_ID'),
            'api_hash' => getenv('TELEGRAM_API_HASH'),
        ],
        'logger' => [ // Logger settings
            'logger' => \danog\MadelineProto\Logger::CALLABLE_LOGGER, //  0 - Logs disabled, 3 - echo logs.
            'logger_param' => \TelegramApiServer\EventObservers\LogObserver::class . '::log',
            'logger_level' => getenv('LOGGER_LEVEL'), // Logging level, available logging levels are: ULTRA_VERBOSE - 5, VERBOSE - 4 , NOTICE - 3, WARNING - 2, ERROR - 1, FATAL_ERROR - 0.
        ],
        'updates' => [
            'handle_updates' => true, // Should I handle updates?
            'handle_old_updates' => false, // Should I handle old updates on startup?
        ],
        'connection_settings' => [
            'all' => [
                'proxy' => '\SocksProxy',
                'proxy_extra' => [
                    'address' => getenv('TELEGRAM_PROXY_ADDRESS'),
                    'port' => getenv('TELEGRAM_PROXY_PORT'),
                    'username' => getenv('TELEGRAM_PROXY_USERNAME'),
                    'password' => getenv('TELEGRAM_PROXY_PASSWORD'),
                ]
            ]
        ],
        'serialization' => [
            'serialization_interval' => 30,
            'cleanup_before_serialization' => true,
        ],
        'db' => [
            'type' => getenv('DB_TYPE'),
            getenv('DB_TYPE') => [
                'host' => getenv('DB_HOST'),
                'port' => (int) getenv('DB_PORT'),
                'user' => getenv('DB_USER'),
                'password' => getenv('DB_PASSWORD'),
                'database' => getenv('DB_DATABASE'),
                'max_connections' => (int) getenv('DB_MAX_CONNECTIONS'),
                'idle_timeout' => (int) getenv('DB_IDLE_TIMEOUT'),
                'cache_ttl' => getenv('DB_CACHE_TTL'),
            ]
        ],
        'download'=>[
            'report_broken_media' => false,
        ],
        'ipc' => [
            'slow' => true
        ]
    ],
    'api' => [
        'ip_whitelist' => array_filter(
            array_map(
                'trim',
                explode(',', getenv('IP_WHITELIST'))
            )
        ),
    ],
];

if (empty($settings['telegram']['connection_settings']['all']['proxy_extra']['address'])) {
    $settings['telegram']['connection_settings']['all']['proxy'] = '\Socket';
    $settings['telegram']['connection_settings']['all']['proxy_extra'] = [];
}

return $settings;