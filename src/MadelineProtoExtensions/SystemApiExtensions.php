<?php

namespace TelegramApiServer\MadelineProtoExtensions;

use Amp\Loop;
use TelegramApiServer\Client;
use function Amp\call;
use \danog\MadelineProto;

class SystemApiExtensions
{
    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function addSession(string $session)
    {
        $this->client->addSession($session);
        return $this->getSessionList();
    }


    public function removeSession(string $session):array
    {
        $this->client->removeSession($session);
        return $this->getSessionList();
    }

    public function getSessionList(): array
    {
        $sessions = [];
        foreach ($this->client->instances as $session => $instance) {
            /** @var MadelineProto\API $instance */
            switch ($instance->API->authorized) {
                case $instance->API::NOT_LOGGED_IN;
                    $status = 'NOT_LOGGED_IN';
                    break;
                case $instance->API::WAITING_CODE:
                    $status = 'WAITING_CODE';
                    break;
                case $instance->API::WAITING_PASSWORD:
                    $status = 'WAITING_PASSWORD';
                    break;
                case $instance->API::WAITING_SIGNUP:
                    $status = 'WAITING_SIGNUP';
                    break;
                case $instance->API::LOGGED_IN:
                    $status = 'LOGGED_IN';
                    break;
                default:
                    $status = $instance->API->authorized;
                    break;
            }

            $sessions[$session] = [
                'session' => $session,
                'file' => Client::getSessionFile($session),
                'status' => $status,
            ];
        }

        return $sessions;
    }

    public function removeSessionFile($session)
    {
        return call(static function() use($session) {
            $file = Client::getSessionFile($session);
            if (is_file($file)) {
                yield \Amp\File\unlink($file);
                yield \Amp\File\unlink($file . '.lock');
            }
        });
    }
}