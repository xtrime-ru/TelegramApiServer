<?php declare(strict_types=1);

namespace TelegramApiServer\EventObservers;

use TelegramApiServer\Files;

final class EventHandler extends \danog\MadelineProto\EventHandler
{
    public static array $instances = [];
    private string $sessionName;

    public function onStart()
    {
        $this->sessionName = Files::getSessionName($this->wrapper->getSession()->getSessionPath());
        if (empty(self::$instances[$this->sessionName])) {
            self::$instances[$this->sessionName] = true;
            warning("Event observer CONSTRUCTED: {$this->sessionName}");
        }
    }

    public function __destruct()
    {
        if (empty($this->sessionName)) {
            return;
        }
        unset(self::$instances[$this->sessionName]);
        warning("Event observer DESTRUCTED: {$this->sessionName}");
    }

    public function onAny($update)
    {
        info("Received update from session: {$this->sessionName}");
        EventObserver::notify($update, $this->sessionName);
    }
}
