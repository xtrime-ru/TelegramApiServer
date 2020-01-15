<?php

if (getenv('SWOOLE_SERVER_ADDRESS')) {
    throw new UnexpectedValueException('Please, update .env file! See .env.example');
}

return [
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
            'logger' => \danog\MadelineProto\Logger::ECHO_LOGGER, //  0 - Logs disabled, 3 - echo logs.
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
            'serialization_interval' => 300,
            'cleanup_before_serialization' => true,
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
];
