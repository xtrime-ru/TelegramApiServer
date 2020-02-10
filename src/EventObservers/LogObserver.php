<?php

namespace TelegramApiServer\EventObservers;

use TelegramApiServer\Logger;

class LogObserver
{
    use ObserverTrait;

    public static function notify(string $level, string $message, array $context = []): void
    {
        foreach (static::$subscribers as $clientId => $callback) {
            $callback($level, $message, $context);
        }
    }

    public static function log(string $message, int $level) {
        Logger::log(Logger::$madelineLevels[$level], $message);
    }
}