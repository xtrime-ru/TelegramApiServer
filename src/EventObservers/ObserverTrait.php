<?php

namespace TelegramApiServer\EventObservers;

use TelegramApiServer\Logger;

trait ObserverTrait
{
    /** @var callable[] */
    public static array $subscribers = [];

    public static function addSubscriber($clientId, callable $callback): void
    {
        Logger::notice("Add event listener. ClientId: {$clientId}");
        static::$subscribers[$clientId] = $callback;
    }

    public static function removeSubscriber($clientId): void
    {
        Logger::notice("Removing listener: {$clientId}");
        unset(static::$subscribers[$clientId]);
        $listenersCount = count(static::$subscribers);
        Logger::notice("Event listeners left: {$listenersCount}");
        if ($listenersCount === 0) {
            static::$subscribers = [];
        }
    }
}