<?php

return [
    'swoole' => [
        'server' => [
            'address' => (string) getenv('SWOOLE_SERVER_ADDRESS'),
            'port' => (string) getenv('SWOOLE_SERVER_PORT'),
        ],
        'options'=> [
            'worker_num' => (int) getenv('SWOOLE_WORKER_NUM'),
            'http_compression' => (bool) getenv('SWOOLE_HTTP_COMPRESSION'),
        ]
    ],
    'telegram' => [
        'app_info' => [ // obtained in https://my.telegram.org
            'api_id'          => getenv('TELEGRAM_API_ID'),
            'api_hash'        => getenv('TELEGRAM_API_HASH'),
        ],
        'logger' => [ // Logger settings
            'logger'        => 3, // Logs disabled.
            'logger_level'  => 5, // Logging level, available logging levels are: ULTRA_VERBOSE - 0, VERBOSE - 1 , NOTICE - 2, WARNING - 3, ERROR - 4, FATAL_ERROR - 5.
        ],
        'updates' => [
            'handle_updates'        => false, // Should I handle updates?
            'handle_old_updates'    => false, // Should I handle old updates on startup?
        ],
        'connection_settings' => [
            'all' => [
                'proxy'         => '\SocksProxy',
                'proxy_extra'   => [
                    'address'   => getenv('TELEGRAM_PROXY_ADDRESS'),
                    'port'      => getenv('TELEGRAM_PROXY_PORT'),
                    'username'  => getenv('TELEGRAM_PROXY_USERNAME'),
                    'password'  => getenv('TELEGRAM_PROXY_PASSWORD'),
                ]
            ]
        ]
    ],
    'curl' => [
        'proxy' => [
            'address'   => getenv('CURL_PROXY_ADDRESS'),
            'port'      => getenv('CURL_PROXY_PORT'),
            'username'  => getenv('CURL_PROXY_USERNAME'),
            'password'  => getenv('CURL_PROXY_PASSWORD'),
        ]
    ],
    'api' => [
        'ip_whitelist' => json_decode(getenv('API_CLIENT_WHITELIST'),true),
        'index_message' => getenv('API_INDEX_MESSAGE'),
    ],
];