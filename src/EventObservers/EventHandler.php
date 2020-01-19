<?php

namespace TelegramApiServer\EventObservers;

use danog\MadelineProto\API;
use TelegramApiServer\Client;
use TelegramApiServer\Logger;

class EventHandler extends \danog\MadelineProto\EventHandler
{
    /** @var callable[] */
    public static array $eventListeners = [];

    public function __construct(?API $MadelineProto)
    {
        parent::__construct($MadelineProto);
        echo 'Event observer CONSTRUCTED' . PHP_EOL;
    }

    public function __destruct()
    {
        echo 'Event observer DESTRUCTED' . PHP_EOL;
    }

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

    public function onAny($update): void
    {
        $session = Client::getSessionName($this->API->wrapper->session);
        Logger::getInstance()->info("Received update from session: {$session}");

        foreach (static::$eventListeners as $clientId => $callback) {
            Logger::getInstance()->notice("Pass update to callback. ClientId: {$clientId}");
            $callback($update, $session);
        }
    }
}