<?php declare(strict_types=1);

/**
 * Get all updates from MadelineProto EventHandler running inside TelegramApiServer via websocket.
 * @see \TelegramApiServer\Controllers\EventsController
 */

use Amp\Websocket\Client\WebsocketHandshake;
use Revolt\EventLoop;
use function Amp\async;
use function Amp\delay;
use function Amp\Websocket\Client\connect;

require 'vendor/autoload.php';

$shortopts = 'u::';
$longopts = [
    'url::',
];
$options = getopt($shortopts, $longopts);
$options = [
    'url' => $options['url'] ?? $options['u'] ?? 'ws://127.0.0.1:9503/events',
];

echo "Connecting to: {$options['url']}" . PHP_EOL;

async(function () use ($options) {
    while (true) {
        try {
            $handshake = (new WebsocketHandshake($options['url']));

            $connection = connect($handshake);

            $connection->onClose(static function () use ($connection) {
                if ($connection->isClosed()) {
                    printf("Connection closed. Reason: %s\n", $connection->getCloseInfo()->getReason());
                }
            });

            echo 'Waiting for events...' . PHP_EOL;
            while ($message = $connection->receive()) {
                $payload = $message->buffer();
                printf("[%s] Received event: %s\n", date('Y-m-d H:i:s'), $payload);
            }
        } catch (Throwable $e) {
            printf("Error: %s\n", $e->getMessage());
        }
        delay(0.1);

    }
});

if (defined('SIGINT')) {
    $signal = Amp\trapSignal([SIGINT, SIGTERM]);
} else {
    EventLoop::run();
}
