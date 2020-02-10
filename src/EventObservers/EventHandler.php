<?php

namespace TelegramApiServer\EventObservers;

use danog\MadelineProto\API;
use TelegramApiServer\Client;
use TelegramApiServer\Logger;

class EventHandler extends \danog\MadelineProto\EventHandler
{
    public static array $instances = [];
    private string $sessionName;

    public function __construct(API $MadelineProto)
    {
        $this->sessionName = Client::getSessionName($MadelineProto->session);
        if (empty(static::$instances[$this->sessionName])) {
            static::$instances[$this->sessionName] = true;
            parent::__construct($MadelineProto);
            Logger::warning("Event observer CONSTRUCTED: {$this->sessionName}");
        }
    }

    public function __destruct()
    {
        unset(static::$instances[$this->sessionName]);
        Logger::warning("Event observer DESTRUCTED: {$this->sessionName}");
    }

    public function onAny($update): void
    {
        Logger::info("Received update from session: {$this->sessionName}");
        EventObserver::notify($update, $this->sessionName);
    }
}