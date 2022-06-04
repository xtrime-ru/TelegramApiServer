<?php

/**
 * Get all updates from MadelineProto EventHandler running inside TelegramApiServer via websocket
 * @see \TelegramApiServer\Controllers\EventsController
 */

require 'vendor/autoload.php';

use Amp\Loop;
use Amp\Websocket\Client\Connection;
use Amp\Websocket\Message;
use function Amp\Websocket\Client\connect;

$shortopts = 'u::';
$longopts = [
    'url::',
];
$options = getopt($shortopts, $longopts);
$options = [
    'url' => $options['url'] ?? $options['u'] ?? 'ws://127.0.0.1:9503/events',
];

Amp\Loop::run(static function () use ($options) {
    echo "Connecting to: {$options['url']}" . PHP_EOL;

    while (true) {
        try {
            /** @var Connection $connection */
            $connection = yield connect($options['url']);

            $repeat = Loop::repeat(5_000, function () use ($connection) {
                echo 'ping' . PHP_EOL;
                yield $connection->send('ping');
            });

            $connection->onClose(static function () use ($connection, &$repeat) {
                Loop::cancel($repeat);
                $repeat = null;
                printf("Connection closed. Reason: %s\n", $connection->getCloseReason());
            });

            echo 'Waiting for events...' . PHP_EOL;
            while ($message = yield $connection->receive()) {
                /** @var Message $message */
                $payload = yield $message->buffer();
                printf("[%s] Received event: %s\n", date('Y-m-d H:i:s'), $payload);
            }
        } catch (\Throwable $e) {
            if (!empty($repeat)) {
                Loop::cancel($repeat);
            }
            printf("Error: %s\n", $e->getMessage());
        }
        yield new Amp\Delayed(500);
    }

});