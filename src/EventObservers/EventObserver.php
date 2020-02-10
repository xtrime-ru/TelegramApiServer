<?php

namespace TelegramApiServer\EventObservers;

use TelegramApiServer\Logger;

class EventObserver
{
    use ObserverTrait;

    public static function notify(array $update, string $sessionName) {
        foreach (static::$subscribers as $clientId => $callback) {
            Logger::notice("Pass update to callback. ClientId: {$clientId}");
            $callback($update, $sessionName);
        }
    }

}