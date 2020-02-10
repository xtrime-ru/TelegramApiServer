<?php

namespace TelegramApiServer\EventObservers;

use danog\MadelineProto\API;
use TelegramApiServer\Client;

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
            warning("Event observer CONSTRUCTED: {$this->sessionName}");
        }
    }

    public function __destruct()
    {
        unset(static::$instances[$this->sessionName]);
        warning("Event observer DESTRUCTED: {$this->sessionName}");
    }

    public function onAny($update): void
    {
        info("Received update from session: {$this->sessionName}");
        EventObserver::notify($update, $this->sessionName);
    }
}