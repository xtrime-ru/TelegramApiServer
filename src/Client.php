<?php

namespace TelegramApiServer;

use Amp\Loop;
use danog\MadelineProto;

class Client
{
    private static string $sessionExtension = '.madeline';
    public ?MadelineProto\CombinedAPI $MadelineProtoCombined = null;

    /**
     * Client constructor.
     *
     * @param array $sessions
     */
    public function __construct(array $sessions)
    {
        $config = (array) Config::getInstance()->get('telegram');

        if (empty($config['connection_settings']['all']['proxy_extra']['address'])) {
            $config['connection_settings']['all']['proxy'] = '\Socket';
            $config['connection_settings']['all']['proxy_extra'] = [];
        }

        foreach ($sessions as &$session) {
            $session = $config;
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
        return $session ? ($session . static::$sessionExtension) : null;
    }

    public static function getSessionName(?string $sessionFile): ?string
    {
        if (!$sessionFile) {
            return null;
        }

        $extensionPosition = strrpos($sessionFile, static::$sessionExtension);
        if($extensionPosition === false) {
           return null;
        }

        $sessionName = substr_replace($sessionFile, '', $extensionPosition, strlen(static::$sessionExtension));
        return $sessionName ?: null;
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
        $this->MadelineProtoCombined->loop(function() use($sessions) {
            $promises = [];
            foreach ($sessions as $session => $message) {
                MadelineProto\Logger::log("Starting session: {$session}", MadelineProto\Logger::WARNING);
                $promises[]= $this->MadelineProtoCombined->instances[$session]->start();
            }
            yield $this->MadelineProtoCombined::all($promises);

            $this->MadelineProtoCombined->setEventHandler(EventHandler::class);
        });

        Loop::defer(function() {
            $this->MadelineProtoCombined->loop();
        });

        $time = round(microtime(true) - $time, 3);
        $sessionsCount = count($sessions);
        MadelineProto\Logger::log(
            "\nTelegramApiServer ready."
            ."\nNumber of sessions: {$sessionsCount}."
            ."\nElapsed time: {$time} sec.\n",
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
        if (count($this->MadelineProtoCombined->instances) === 1) {
            $session = (string) array_key_first($this->MadelineProtoCombined->instances);
        } else {
            $session = static::getSessionFile($session);
        }

        if (!$session) {
            throw new \InvalidArgumentException('Multiple sessions detected. You need to specify which session to use');
        }

        if (empty($this->MadelineProtoCombined->instances[$session])) {
            throw new \InvalidArgumentException('Session not found');
        }

        return $this->MadelineProtoCombined->instances[$session];
    }


}
