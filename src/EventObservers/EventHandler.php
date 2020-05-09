<?php

namespace TelegramApiServer\EventObservers;

use danog\MadelineProto\APIWrapper;
use TelegramApiServer\Files;

class EventHandler extends \danog\MadelineProto\EventHandler
{
    public static array $instances = [];
    private string $sessionName;

    public function __construct(APIWrapper $MadelineProto)
    {
        $this->sessionName = Files::getSessionName($MadelineProto->session);
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
        if (empty(static::$instances[$this->sessionName])) {
            warning("unsetEventHandler: {$this->sessionName}");
            $this->unsetEventHandler();
            return;
        }
        info("Received update from session: {$this->sessionName}");
        EventObserver::notify($update, $this->sessionName);
    }
}