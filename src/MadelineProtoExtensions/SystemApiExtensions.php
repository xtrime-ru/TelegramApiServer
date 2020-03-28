<?php

namespace TelegramApiServer\MadelineProtoExtensions;

use Amp\Promise;
use danog\MadelineProto;
use danog\MadelineProto\MTProto;
use TelegramApiServer\Client;
use TelegramApiServer\Files;
use function Amp\call;

class SystemApiExtensions
{
    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function addSession(string $session, array $settings = []): Promise
    {
        return call(function() use($session, $settings) {
            $instance = $this->client->addSession($session, $settings);
            yield $this->client->runSession($instance);
            return $this->getSessionList();
        });
    }

    public function removeSession(string $session): array
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
                case MTProto::NOT_LOGGED_IN;
                    $status = 'NOT_LOGGED_IN';
                    break;
                case MTProto::WAITING_CODE:
                    $status = 'WAITING_CODE';
                    break;
                case MTProto::WAITING_PASSWORD:
                    $status = 'WAITING_PASSWORD';
                    break;
                case MTProto::WAITING_SIGNUP:
                    $status = 'WAITING_SIGNUP';
                    break;
                case MTProto::LOGGED_IN:
                    $status = 'LOGGED_IN';
                    break;
                default:
                    $status = $instance->API->authorized;
                    break;
            }

            $sessions[$session] = [
                'session' => $session,
                'file' => Files::getSessionFile($session),
                'status' => $status,
            ];
        }

        return [
            'sessions' => $sessions,
            'memory' => $this->bytesToHuman(memory_get_usage(true)),
        ];
    }

    public function removeSessionFile($session)
    {
        return call(static function() use($session) {
            $file = Files::getSessionFile($session);
            if (is_file($file)) {
                yield \Amp\File\unlink($file);
                yield \Amp\File\unlink($file . '.lock');
            }
        });
    }

    private function bytesToHuman($bytes)
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        for ($i = 0; $bytes > 1024; $i++) {
            $bytes /= 1024;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}