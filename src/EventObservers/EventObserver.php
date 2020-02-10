<?php

namespace TelegramApiServer\EventObservers;


class EventObserver
{
    use ObserverTrait;

    public static function notify(array $update, string $sessionName) {
        foreach (static::$subscribers as $clientId => $callback) {
            notice("Pass update to callback. ClientId: {$clientId}");
            $callback($update, $sessionName);
        }
    }

}