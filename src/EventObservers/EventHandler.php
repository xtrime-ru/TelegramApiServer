<?php

namespace TelegramApiServer\EventObservers;

use danog\MadelineProto\API;
use TelegramApiServer\Client;
use TelegramApiServer\Logger;

class EventHandler extends \danog\MadelineProto\EventHandler
{
    /** @var callable[] */
    public static array $eventListeners = [];
    private string $sessionName;

    public function __construct(API $MadelineProto)
    {
        parent::__construct($MadelineProto);
        $this->sessionName = Client::getSessionName($MadelineProto->session);
        Logger::getInstance()->warning("Event observer CONSTRUCTED: {$this->sessionName}");
    }

    public function __destruct()
    {
        Logger::getInstance()->warning("Event observer DESTRUCTED {$this->sessionName}");
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
        Logger::getInstance()->info("Received update from session: {$this->sessionName}");

        foreach (static::$eventListeners as $clientId => $callback) {
            Logger::getInstance()->notice("Pass update to callback. ClientId: {$clientId}");
            $callback($update, $this->sessionName);
        }
    }
}