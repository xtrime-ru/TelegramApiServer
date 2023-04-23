<?php

namespace TelegramApiServer\MadelineProtoExtensions;

use danog\MadelineProto;
use danog\MadelineProto\MTProto;
use InvalidArgumentException;
use Revolt\EventLoop;
use TelegramApiServer\Client;
use TelegramApiServer\Files;
use Throwable;
use function Amp\async;
use function Amp\File\deleteFile;
use function Amp\Future\awaitAll;

class SystemApiExtensions
{
    private Client $client;

    public function __construct(Client $client)
    {
        $this->client = $client;
    }

    public function addSession(string $session, array $settings = []): array
    {
        if (!empty($settings['app_info']['api_id'])) {
            $settings['app_info']['api_id'] = (int)$settings['app_info']['api_id'];
        }

        $instance = $this->client->addSession($session, $settings);
        /** @var null|MadelineProto\Settings $fullSettings */
        $fullSettings = $instance->getSettings();
        try {
            if ($fullSettings !== null && $instance->getAuthorization() !== MTProto::LOGGED_IN) {
                $fullSettings->getAppInfo()->getApiId();
                $fullSettings->getAppInfo()->getApiHash();
            }
        } catch (Throwable $e) {
            unset($fullSettings, $instance);
            $this->removeSession($session);
            $this->unlinkSessionFile($session);
            throw $e;
        }

        $this->client->startLoggedInSession($session);
        return $this->getSessionList();
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

    public function unlinkSessionFile($session): string
    {
        $file = Files::getSessionFile($session);

        if (is_file($file)) {
            $futures = [];
            foreach (glob("$file*") as $file) {
                $futures[] = async(fn() => deleteFile($file));
            }
            awaitAll($futures);
        } else {
            throw new InvalidArgumentException('Session file not found');
        }

        $this->unlinkSessionSettings($session);

        return 'ok';
    }

    public function saveSessionSettings(string $session, array $settings = [])
    {
        Files::saveSessionSettings($session, $settings);

        return 'ok';
    }

    public function unlinkSessionSettings($session): string
    {
        $settings = Files::getSessionFile($session, Files::SETTINGS_EXTENSION);
        if (is_file($settings)) {
            deleteFile($settings);
        }

        return 'ok';
    }

    public function exit(): string
    {
        EventLoop::defer(static fn() => exit());
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