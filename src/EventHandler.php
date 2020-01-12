<?php

namespace TelegramApiServer;

use danog\MadelineProto\CombinedEventHandler;

class EventHandler extends CombinedEventHandler
{
    /** @var callable[] */
    public static array $eventListeners = [];

    public static function addEventListener($clientId, callable $callback)
    {
        Logger::getInstance()->notice("Add event listener. ClientId: {$clientId}");
        static::$eventListeners[$clientId] = $callback;
    }

    public static function removeEventListener($clientId): void
    {
        Logger::getInstance()->notice("Removing listener: {$clientId}");
        unset(static::$eventListeners[$clientId]);
        $listenersCount = count(static::$eventListeners);
        Logger::getInstance()->notice("Event listeners left: {$listenersCount}");
        if ($listenersCount === 0) {
            static::$eventListeners = [];
        }
    }

    public function onAny($update, $sessionFile): void
    {
        $session = Client::getSessionName($sessionFile);
        Logger::getInstance()->info("Received update from session: {$session}");

        foreach (static::$eventListeners as $clientId => $callback) {
            Logger::getInstance()->notice("Pass update to callback. ClientId: {$clientId}");
            $callback($update, $session);
        }
    }
}