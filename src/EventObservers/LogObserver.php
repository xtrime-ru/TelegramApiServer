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

    /**
     * @param mixed|array|string $message
     * @param int $level
     */
    public static function log($message, int $level) {
        if (is_array($message)) {
            Logger::getInstance()->log(Logger::$madelineLevels[$level], '' ,$message);
        } else {
            Logger::getInstance()->log(Logger::$madelineLevels[$level], (string) $message);
        }

    }
}