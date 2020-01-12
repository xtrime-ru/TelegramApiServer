<?php

namespace TelegramApiServer;

use danog\MadelineProto\CombinedEventHandler;
use danog\MadelineProto\Logger;

class EventHandler extends CombinedEventHandler
{
    /** @var callable[] */
    public static array $eventListeners = [];

    public static function addEventListener($clientId, callable $callback)
    {
        Logger::log("Add event listener. ClientId: {$clientId}");
        static::$eventListeners[$clientId] = $callback;
    }

    public static function removeEventListener($clientId): void
    {
        Logger::log("Removing listener: {$clientId}");
        unset(static::$eventListeners[$clientId]);
        if (!static::$eventListeners) {
            static::$eventListeners = [];
        }
    }

    public function onAny($update, $sessionFile): void
    {
        $session = Client::getSessionName($sessionFile);
        Logger::log("Got update from session: {$session}");

        foreach (static::$eventListeners as $clientId => $callback) {
            Logger::log("Pass update to callback. ClientId: {$clientId}");
            $callback($update, $session);
        }
    }
}