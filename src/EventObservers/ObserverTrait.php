<?php declare(strict_types=1);

namespace TelegramApiServer\EventObservers;

trait ObserverTrait
{
    /** @var callable[] */
    public static array $subscribers = [];

    public static function addSubscriber($clientId, callable $callback): void
    {
        notice("Add event listener. ClientId: {$clientId}");
        self::$subscribers[$clientId] = $callback;
    }

    public static function removeSubscriber($clientId): void
    {
        notice("Removing listener: {$clientId}");
        unset(self::$subscribers[$clientId]);
        $listenersCount = \count(self::$subscribers);
        notice("Event listeners left: {$listenersCount}");
        if ($listenersCount === 0) {
            self::$subscribers = [];
        }
    }
}
