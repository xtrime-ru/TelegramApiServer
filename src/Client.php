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

    public function connect($sessionFiles)
    {
        //При каждой инициализации настройки обновляются из массива $config
        echo PHP_EOL . 'Starting MadelineProto...' . PHP_EOL;
        $time = microtime(true);

        foreach ($sessionFiles as $file) {
            $session = static::getSessionName($file);
            $this->addSession($session, true);
        }

        $time = round(microtime(true) - $time, 3);
        $sessionsCount = count($sessionFiles);

        echo
            "\nTelegramApiServer ready."
            . "\nNumber of sessions: {$sessionsCount}."
            . "\nElapsed time: {$time} sec.\n"
        ;
    }

    public function addSession(string $session, bool $startSession = false)
    {
        $settings = (array) Config::getInstance()->get('telegram');
        $file = static::getSessionFile($session);
        $instance = new MadelineProto\API($file, $settings);
        $instance->async(true);
        $this->instances[$session] = $instance;

        $isLoggedIn = ($instance->API->authorized ?? MTProto::NOT_LOGGED_IN) === MTProto::LOGGED_IN;
        if($isLoggedIn || $startSession === true) {
            $instance->loop(function() use($instance) {
                yield $instance->start();
            });
        }
        if ($isLoggedIn) {
            $instance->setEventHandler(EventHandler::class);
            Loop::defer(static function() use($instance) {
                $instance->loop();
            });
        }
    }

    public function removeSession($session)
    {
        if (empty($this->instances[$session])) {
            throw new InvalidArgumentException('Instance not found');
        }

        $this->instances[$session]->stop();
        unset($this->instances[$session], EventHandler::$instances[$session]);
    }

    /**
     * @param string|null $session
     *
     * @return MadelineProto\API
     */
    public function getInstance(?string $session = null): MadelineProto\API
    {
        if (!$this->instances) {
            throw new RuntimeException('No sessions available. Use combinedApi or restart server with --session option');
        }

        if (!$session) {
            if (count($this->instances) === 1) {
                $session = (string) array_key_first($this->instances);
            } else {
                throw new InvalidArgumentException('Multiple sessions detected. Specify which session to use. See README for examples.');
            }
        }

        if (empty($this->instances[$session])) {
            throw new InvalidArgumentException('Session not found.');
        }

        return $this->instances[$session];
    }

}
