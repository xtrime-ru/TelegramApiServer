<?php

namespace TelegramApiServer;

use Amp\Delayed;
use Amp\Loop;
use Amp\Promise;
use danog\MadelineProto;
use danog\MadelineProto\MTProto;
use InvalidArgumentException;
use Psr\Log\LogLevel;
use RuntimeException;
use TelegramApiServer\EventObservers\EventObserver;
use function Amp\call;

class Client
{
    public static Client $self;
    /** @var MadelineProto\API[] */
    public array $instances = [];
    private bool $sessionCheckRunning = false;

    public static function getInstance(): Client {
        if (empty(static::$self)) {
            static::$self = new static();
        }
        return static::$self;
    }

    private static function isSessionLoggedIn(MadelineProto\API $instance): bool
    {
        return ($instance->API->authorized ?? MTProto::NOT_LOGGED_IN) === MTProto::LOGGED_IN;
    }

    public function connect(array $sessionFiles): void
    {
        warning(PHP_EOL . 'Starting MadelineProto...' . PHP_EOL);

        foreach ($sessionFiles as $file) {
            $sessionName = Files::getSessionName($file);
            $instance = $this->addSession($sessionName);
            $this->startLoggedInSession($instance);
        }

        $this->startNotLoggedInSessions();

        $sessionsCount = count($sessionFiles);
        warning(
            "\nTelegramApiServer ready."
            . "\nNumber of sessions: {$sessionsCount}."
        );
    }

    public function addSession(string $session, array $settings = []): MadelineProto\API
    {
        if (isset($this->instances[$session])) {
            throw new InvalidArgumentException('Session already exists');
        }
        $file = Files::getSessionFile($session);
        Files::checkOrCreateSessionFolder($file);
        $settings = array_replace_recursive((array) Config::getInstance()->get('telegram'), $settings);
        $instance = new MadelineProto\API($file, $settings);
        $instance->async(true);

        $this->instances[$session] = $instance;
        return $instance;
    }

    public function removeSession($session): void
    {
        if (empty($this->instances[$session])) {
            throw new InvalidArgumentException('Session not found');
        }

        EventObserver::stopEventHandler($session, true);
        $this->instances[$session]->stop();

        /** @see startLoggedInSession() */
        //Mark this session as not logged in, so no other actions will be made.
        $this->instances[$session]->API->authorized = MTProto::NOT_LOGGED_IN;

        unset($this->instances[$session]);
    }

    /**
     * @param string|null $session
     *
     * @return MadelineProto\API
     */
    public function getSession(?string $session = null): MadelineProto\API
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

    private function startNotLoggedInSessions(): Promise
    {
        return call(
            function() {
                foreach ($this->instances as $instance) {
                    if (!static::isSessionLoggedIn($instance)) {
                        {
                            //Disable logging to stdout
                            $logLevel = Logger::getInstance()->minLevelIndex;
                            Logger::getInstance()->minLevelIndex = Logger::$levels[LogLevel::EMERGENCY];

                            yield $instance->start();

                            //Enable logging to stdout
                            Logger::getInstance()->minLevelIndex = $logLevel;
                        }
                        $this->startLoggedInSession($instance);
                    }
                }
            }
        );
    }

    public function startLoggedInSession(MadelineProto\API $instance): Promise
    {
        return call(
            static function() use ($instance) {
                if (static::isSessionLoggedIn($instance)) {
                    yield $instance->start();
                }
            }
        );
    }

    public function removeBrokenSessions(): void
    {
        Loop::defer(function() {
            if (!$this->sessionCheckRunning) {
                $this->sessionCheckRunning = true;
                foreach (yield static::getInstance()->getBrokenSessions() as $session) {
                    static::getInstance()->removeSession($session);
                }
                $this->sessionCheckRunning = false;
            }
        });
    }

    private function getBrokenSessions(): Promise
    {
        return call(function() {
            $brokenSessions = [];
            foreach ($this->instances as $session => $instance) {
                if (!static::checkSession($session, $instance)) {
                    $brokenSessions[] = $session;
                    yield new Delayed(1000);
                }
            }

            return $brokenSessions;
        });

    }

    private static function checkSession(string $session, MadelineProto\API $instance): bool
    {
        warning("Checking session: {$session}");
        try {
            $instance->getSelf(['async' => false]);
        } catch (\Throwable $e) {
            error("Session is broken: {$session}");
            return false;
        }

        return true;
    }

}
