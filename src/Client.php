<?php

namespace TelegramApiServer;

use Amp\Loop;
use danog\MadelineProto;
use TelegramApiServer\EventObservers\EventHandler;

class Client
{
    public static string $sessionExtension = '.madeline';
    public static string $sessionFolder = 'sessions';
    private ?MadelineProto\CombinedAPI $MadelineProtoCombined = null;

    /**
     * Client constructor.
     *
     * @param array $sessions
     */
    public function __construct(array $sessions)
    {
        $settings = (array) Config::getInstance()->get('telegram');

        foreach ($sessions as &$session) {
            $session = $settings;
        }
        unset($session);

        $this->connect($sessions);
    }

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
                throw new \RuntimeException(sprintf('Directory "%s" was not created', $directory));
            }
        }
    }

    /**
     * @param array $sessions
     */
    public function connect(array $sessions): void
    {
        //При каждой инициализации настройки обновляются из массива $config
        echo PHP_EOL . 'Starting MadelineProto...' . PHP_EOL;
        $time = microtime(true);

        $this->MadelineProtoCombined = new MadelineProto\CombinedAPI('combined_session.madeline', $sessions);
        //В сессии могут быть ссылки на несуществующие классы после обновления кода. Она нам не нужна.
        $this->MadelineProtoCombined->session = null;

        $this->MadelineProtoCombined->async(true);
        $this->MadelineProtoCombined->loop(
            function() use ($sessions) {
                $promises = [];
                foreach (array_keys($sessions) as $session) {
                    MadelineProto\Logger::log("Starting session: {$session}", MadelineProto\Logger::WARNING);
                    $promises[] = $this->MadelineProtoCombined->instances[$session]->start();
                }
                yield $this->MadelineProtoCombined::all($promises);


            }
        );


        $time = round(microtime(true) - $time, 3);
        $sessionsCount = count($sessions);
        MadelineProto\Logger::log(
            "\nTelegramApiServer ready."
            . "\nNumber of sessions: {$sessionsCount}."
            . "\nElapsed time: {$time} sec.\n",
            MadelineProto\Logger::WARNING
        );
    }

    /**
     * @param string|null $session
     *
     * @return MadelineProto\API
     */
    public function getInstance(?string $session = null): MadelineProto\API
    {
        if (!$this->MadelineProtoCombined->instances) {
            throw new \RuntimeException('No sessions available. Use combinedApi or restart server with --session option');
        }

        if (!$session) {
            if (count($this->MadelineProtoCombined->instances) === 1) {
                $session = (string) array_key_first($this->MadelineProtoCombined->instances);
            } else {
                throw new \InvalidArgumentException('Multiple sessions detected. Specify which session to use. See README for examples.');
            }
        } else {
            $session = static::getSessionFile($session);
        }

        if (empty($this->MadelineProtoCombined->instances[$session])) {
            throw new \InvalidArgumentException('Session not found.');
        }

        return $this->MadelineProtoCombined->instances[$session];
    }

    public function getCombinedInstance(): MadelineProto\CombinedAPI
    {
        return $this->MadelineProtoCombined;
    }

}
