<?php

namespace TelegramApiServer\EventObservers;


trait ObserverTrait
{
    /** @var callable[] */
    public static array $subscribers = [];

    public static function addSubscriber($clientId, callable $callback): void
    {
        notice("Add event listener. ClientId: {$clientId}");
        static::$subscribers[$clientId] = $callback;
    }

    public static function removeSubscriber($clientId): void
    {
        notice("Removing listener: {$clientId}");
        unset(static::$subscribers[$clientId]);
        $listenersCount = count(static::$subscribers);
        notice("Event listeners left: {$listenersCount}");
        if ($listenersCount === 0) {
            static::$subscribers = [];
        }
    }
}