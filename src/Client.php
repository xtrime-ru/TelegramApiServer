<?php

namespace TelegramApiServer;

use Amp\Delayed;
use Amp\Loop;
use Amp\Promise;
use danog\MadelineProto;
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

    public static function getInstance(): Client {
        if (empty(static::$self)) {
            static::$self = new static();
        }
        return static::$self;
    }

    public static function isSessionLoggedIn(MadelineProto\API $instance)
    {
        return (bool) (yield  $instance->getSelf());
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

        yield $promises;

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
            $instance->API->unreference();
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
                    while(null === $instance->API) {
                        yield (new Delayed(100));
                    }
                    if (! yield from static::isSessionLoggedIn($instance)) {
                        {
                            //Disable logging to stdout
                            $logLevel = Logger::getInstance()->minLevelIndex;
                            Logger::getInstance()->minLevelIndex = Logger::$levels[LogLevel::ERROR];
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
                if (yield from static::isSessionLoggedIn($this->instances[$sessionName])) {
                    if (empty(EventObserver::$sessionClients[$sessionName])) {
                        $this->instances[$sessionName]->unsetEventHandler();
                    }
                    yield $this->instances[$sessionName]->start();
                    $this->instances[$sessionName]->loopFork();
					$this->instances[$sessionName]->echo("Started session: {$sessionName}\n");
                }
            }
        );
    }

}
