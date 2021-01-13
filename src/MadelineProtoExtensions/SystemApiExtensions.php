<?php

namespace TelegramApiServer\MadelineProtoExtensions;

use Amp\Loop;
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
        if (!empty($settings['app_info']['api_id'])) {
            $settings['app_info']['api_id'] = (int) $settings['app_info']['api_id'];
        }

        return call(function() use($session, $settings) {
            $instance = $this->client->addSession($session, $settings);
            /** @var null|MadelineProto\Settings $fullSettings */
            $fullSettings = $instance->API ? yield $instance->getSettings() : null;
            try {
                if ($fullSettings !== null && !Client::isSessionLoggedIn($instance)) {
                    $fullSettings->getAppInfo()->getApiId();
                    $fullSettings->getAppInfo()->getApiHash();
                }
            } catch (\Throwable $e) {
                unset($fullSettings, $instance);
                $this->removeSession($session);
                $this->unlinkSessionFile($session);
                throw $e;
            }

            yield $this->client->startLoggedInSession($session);
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
            $authorized = $instance->API->authorized ?? null;
            switch ($authorized) {
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
                case null:
                    $status = 'LOADING';
                    break;
                default:
                    $status = $authorized;
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

    public function unlinkSessionFile($session): Promise
    {
        return call(function() use($session) {
            $file = Files::getSessionFile($session);

            if (is_file($file)) {
                $promises = [];
                foreach (glob("$file*") as $file) {
                    $promises[] = \Amp\File\unlink($file);
                }
                yield from $promises;
            } else {
                throw new \InvalidArgumentException('Session file not found');
            }

            yield $this->unlinkSessionSettings($session);

            return 'ok';
        });
    }

    public function saveSessionSettings(string $session, array $settings = [])
    {
        Files::saveSessionSettings($session, $settings);

        return 'ok';
    }

    public function unlinkSessionSettings($session): Promise
    {
        return call(static function() use($session) {
            $settings = Files::getSessionFile($session, Files::SETTINGS_EXTENSION);
            if (is_file($settings)) {
                yield \Amp\File\unlink($settings);
            }

            return 'ok';
        });
    }

    public function exit(): string {
        Loop::defer(static fn() => exit());
        return 'ok';
    }

    private function bytesToHuman($bytes): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        for ($i = 0; $bytes > 1024; $i++) {
            $bytes /= 1024;
        }
        return round($bytes, 2) . ' ' . $units[$i];
    }
}