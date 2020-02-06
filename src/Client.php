<?php

namespace TelegramApiServer;

use Amp\Loop;
use danog\MadelineProto;
use danog\MadelineProto\MTProto;
use InvalidArgumentException;
use RuntimeException;
use TelegramApiServer\EventObservers\EventHandler;

class Client
{
    public static string $sessionExtension = '.madeline';
    public static string $sessionFolder = 'sessions';
    /** @var MadelineProto\API[] */
    public array $instances = [];

    /**
     * @param string|null $session
     *
     * @return string|null
     */
    public static function getSessionFile(?string $session): ?string
    {
        if (!$session) {
            return null;
        }
        $session = rtrim(trim($session), '/');
        $session = static::$sessionFolder . '/' . $session . static::$sessionExtension;
        $session = str_replace('//', '/', $session);
        return $session;
    }

    public static function getSessionName(?string $sessionFile): ?string
    {
        if (!$sessionFile) {
            return null;
        }

        preg_match(
            '~' . static::$sessionFolder . "/(?'sessionName'.*?)" . static::$sessionExtension . '$~',
            $sessionFile,
            $matches
        );

        return $matches['sessionName'] ?? null;
    }

    public static function checkOrCreateSessionFolder($session, $rootDir): void
    {
        $directory = dirname($session);
        if ($directory && $directory !== '.' && !is_dir($directory)) {
            $parentDirectoryPermissions = fileperms($rootDir);
            if (!mkdir($directory, $parentDirectoryPermissions, true) && !is_dir($directory)) {
                throw new RuntimeException(sprintf('Directory "%s" was not created', $directory));
            }
        }
    }

    private static function isInstanceLoggedIn($instance): bool
    {
        return ($instance->API->authorized ?? MTProto::NOT_LOGGED_IN) === MTProto::LOGGED_IN;
    }

    public function connect($sessionFiles): void
    {
        Logger::getInstance()->warning(PHP_EOL . 'Starting MadelineProto...' . PHP_EOL);

        foreach ($sessionFiles as $file) {
            $session = static::getSessionName($file);
            $this->addSession($session);
        }
        $this->startSessions();

        $sessionsCount = count($sessionFiles);
        Logger::getInstance()->warning(
            "\nTelegramApiServer ready."
            . "\nNumber of sessions: {$sessionsCount}."
        );
    }

    public function addSession(string $session, array $settings = []): void
    {
        if (isset($this->instances[$session])) {
            throw new InvalidArgumentException('Session already exists');
        }
        $file = static::getSessionFile($session);
        $settings = array_replace_recursive((array) Config::getInstance()->get('telegram'), $settings);
        $instance = new MadelineProto\API($file, $settings);
        $instance->async(true);
        $this->instances[$session] = $instance;

        Loop::defer(
            function() use ($instance) {
                if (static::isInstanceLoggedIn($instance)) {
                    $instance->setEventHandler(EventHandler::class);
                    $this->loop($instance);
                }
            }
        );
    }

    public function removeSession($session): void
    {
        if (empty($this->instances[$session])) {
            throw new InvalidArgumentException('Session not found');
        }

        $this->instances[$session]->stop();
        unset(
            $this->instances[$session],
            EventHandler::$instances[$session]
        );
    }

    /**
     * @param string|null $session
     *
     * @return MadelineProto\API
     */
    public function getInstance(?string $session = null): MadelineProto\API
    {
        if (!$this->instances) {
            throw new RuntimeException(
                'No sessions available. Use combinedApi or restart server with --session option'
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

    private function startSessions(): void
    {
        Loop::defer(
            function() {
                foreach ($this->instances as $instance) {
                    if (!static::isInstanceLoggedIn($instance)) {
                        $this->loop(
                            $instance,
                            static function() use ($instance) {
                                yield $instance->start();
                            }
                        );
                    }
                }
            }
        );
    }

    private function loop(MadelineProto\API $instance, callable $callback = null): bool
    {
        $sessionName = self::getSessionName($instance->session);
        try {
            if ($callback) {
                $instance->loop($callback);
            } else {
                $instance->loop();
            }
        } catch (\Throwable $e) {
            Logger::getInstance()->critical(
                $e->getMessage(),
                [
                    'session' => $sessionName,
                    'exception' => [
                        'message' => $e->getMessage(),
                        'code' => $e->getCode(),
                        'file' => $e->getFile(),
                        'line' => $e->getLine(),
                    ],
                ]
            )
            ;
            $this->removeSession($sessionName);
            return false;
        }
        return true;
    }

}
