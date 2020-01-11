<?php

namespace TelegramApiServer;

use danog\MadelineProto;

class Client
{
    /** @var MadelineProto\CombinedAPI */
    public MadelineProto\CombinedAPI $MadelineProto;
    private ?string $defaultSession = null;

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

        if (count($sessions) === 1) {
            $this->defaultSession = (string) array_key_first($sessions);
        }
        $this->connect($sessions);
    }

    /**
     * @param string|null $session
     *
     * @return string|null
     */
    public static function getSessionFileName(?string $session): ?string
    {
        return $session ? "{$session}.madeline" : null;
    }

    /**
     * @param array $sessions
     */
    public function connect(array $sessions): void
    {
        //При каждой инициализации настройки обновляются из массива $config
        echo PHP_EOL . 'Starting MadelineProto...' . PHP_EOL;
        $time = microtime(true);
        $this->MadelineProto = new MadelineProto\CombinedAPI('combined_session.madeline', $sessions);

        $this->MadelineProto->async(true);
        $this->MadelineProto->loop(function() use($sessions) {
            $res = [];
            foreach ($sessions as $session => $message) {
                MadelineProto\Logger::log("Starting session: {$session}", MadelineProto\Logger::WARNING);
                $res[] = $this->MadelineProto->instances[$session]->start();
            }
            yield $this->MadelineProto->all($res);
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
    public function getInstance(?string $session): MadelineProto\API
    {
        $session = static::getSessionFileName($session) ?: $this->defaultSession;

        if (!$session) {
            throw new \InvalidArgumentException('Multiple sessions detected. You need to specify which session to use');
        }

        if (empty($this->MadelineProto->instances[$session])) {
            throw new \InvalidArgumentException('Session not found');
        }

        return $this->MadelineProto->instances[$session];
    }



}
