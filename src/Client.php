<?php

namespace TelegramApiServer;

use danog\MadelineProto\API;
use danog\MadelineProto\APIWrapper;
use danog\MadelineProto\MTProto;
use InvalidArgumentException;
use Psr\Log\LogLevel;
use ReflectionProperty;
use Revolt\EventLoop;
use RuntimeException;
use TelegramApiServer\EventObservers\EventObserver;
use function Amp\async;
use function Amp\Future\await;
use function Amp\Future\awaitAll;

class Client
{
    public static Client $self;
    /** @var API[] */
    public array $instances = [];

	public static function getInstance(): Client {
        if (empty(static::$self)) {
            static::$self = new static();
        }
        return static::$self;
    }

    public function connect(array $sessionFiles)
    {
        warning(PHP_EOL . 'Starting MadelineProto...' . PHP_EOL);

        foreach ($sessionFiles as $file) {
            $sessionName = Files::getSessionName($file);
            $this->addSession($sessionName);
            $this->startLoggedInSession($sessionName);
        }

        $this->startNotLoggedInSessions();

        $sessionsCount = count($sessionFiles);
        warning(
            "\nTelegramApiServer ready."
            . "\nNumber of sessions: {$sessionsCount}."
        );
    }

    public function addSession(string $session, array $settings = []): API
    {
        if (isset($this->instances[$session])) {
            throw new InvalidArgumentException('Session already exists');
        }
        $file = Files::getSessionFile($session);
        Files::checkOrCreateSessionFolder($file);

        if ($settings) {
            Files::saveSessionSettings($session, $settings);
        }
        $settings = array_replace_recursive(
            (array) Config::getInstance()->get('telegram'),
            Files::getSessionSettings($session),
        );
        $instance = new API($file, $settings);

        $this->instances[$session] = $instance;
        return $instance;
    }

    public function removeSession(string $session): void
    {
        if (empty($this->instances[$session])) {
            throw new InvalidArgumentException('Session not found');
        }

        EventObserver::stopEventHandler($session, true);

        $instance = $this->instances[$session];
        unset($this->instances[$session]);

        if (!empty($instance->API)) {
            $instance->unsetEventHandler();
        }
        unset($instance);
        gc_collect_cycles();
    }

    /**
     * @param string|null $session
     *
     * @return API
     */
    public function getSession(?string $session = null): API
    {
        if (!$this->instances) {
            throw new RuntimeException(
                'No sessions available. Call /system/addSession?session=%session_name% or restart server with --session option'
            );
        }

        if (!$session) {
            if (count($this->instances) === 1) {
                $session = (string) array_key_first($this->instances);
            } else {
                throw new InvalidArgumentException(
                    'Multiple sessions detected. Specify which session to use. See README for examples.'
                );
            }
        }

        if (empty($this->instances[$session])) {
            throw new InvalidArgumentException('Session not found.');
        }

        return $this->instances[$session];
    }

    private function startNotLoggedInSessions(): void
    {
        foreach ($this->instances as $name => $instance) {
            if ($instance->getAuthorization() !== MTProto::LOGGED_IN) {
                {
                    //Disable logging to stdout
                    $logLevel = Logger::getInstance()->minLevelIndex;
                    Logger::getInstance()->minLevelIndex = Logger::$levels[LogLevel::ERROR];
                    $instance->echo("Authorizing session: {$name}\n");
                    $instance->start();

                    //Enable logging to stdout
                    Logger::getInstance()->minLevelIndex = $logLevel;
                }
                $this->startLoggedInSession($name);
            }
        }
    }

    public function startLoggedInSession(string $sessionName): void
    {
        if ($this->instances[$sessionName]->getAuthorization() === MTProto::LOGGED_IN) {
            if (empty(EventObserver::$sessionClients[$sessionName])) {
                $this->instances[$sessionName]->unsetEventHandler();
            }
            $this->instances[$sessionName]->start();
            $this->instances[$sessionName]->echo("Started session: {$sessionName}\n");
        }
    }

    public static function getWrapper(API $madelineProto): APIWrapper
    {
        $property = new ReflectionProperty($madelineProto, "wrapper");
        /** @var APIWrapper $wrapper */
        $wrapper = $property->getValue($madelineProto);
        return $wrapper;
    }

}
