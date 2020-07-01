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

    public static function isSessionLoggedIn(MadelineProto\API $instance): bool
    {
        return ($instance->API->authorized ?? MTProto::NOT_LOGGED_IN) === MTProto::LOGGED_IN;
    }

    public function connect(array $sessionFiles): \Generator
    {
        warning(PHP_EOL . 'Starting MadelineProto...' . PHP_EOL);

        $promises = [];
        foreach ($sessionFiles as $file) {
            $sessionName = Files::getSessionName($file);
            $this->addSession($sessionName);
            $promises[] = $this->startLoggedInSession($sessionName);
        }

        yield from $promises;

        Loop::defer(fn() => yield $this->startNotLoggedInSessions());

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

        if ($settings) {
            Files::saveSessionSettings($session, $settings);
        }
        $settings = array_replace_recursive(
            (array) Config::getInstance()->get('telegram'),
            Files::getSessionSettings($session),
        );
        $instance = new MadelineProto\API($file, $settings);
        if (self::isSessionLoggedIn($instance)) {
            $instance->unsetEventHandler();
        }
        $instance->async(true);

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
            $instance->stop();
        }
        unset($instance);
        gc_collect_cycles();
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
                foreach ($this->instances as $name => $instance) {
                    if (!static::isSessionLoggedIn($instance)) {
                        {
                            //Disable logging to stdout
                            $logLevel = Logger::getInstance()->minLevelIndex;
                            Logger::getInstance()->minLevelIndex = Logger::$levels[LogLevel::EMERGENCY];
                            $instance->echo("Authorizing session: {$name}\n");
                            yield $instance->start();

                            //Enable logging to stdout
                            Logger::getInstance()->minLevelIndex = $logLevel;
                        }
                        $this->startLoggedInSession($name);
                    }
                }
            }
        );
    }

    public function startLoggedInSession(string $sessionName): Promise
    {
        return call(
            function() use ($sessionName) {
                if (static::isSessionLoggedIn($this->instances[$sessionName])) {
                    yield $this->instances[$sessionName]->start();
                    $this->instances[$sessionName]->unsetEventHandler();
                    Loop::defer(function() use($sessionName) {
                        while (!empty($this->instances[$sessionName]) && static::isSessionLoggedIn($this->instances[$sessionName])) {
                            try {
                                warning('Loop started: ' . $sessionName);
                                $this->instances[$sessionName]->loop();
                                warning('Loop stopped: ' . $sessionName);
                            } catch (\Throwable $e) {
                                error('Error in Madeline Loop.', Logger::getExceptionAsArray($e));
                                Client::getInstance()->removeBrokenSessions();
                            }
                        }
                    });
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
