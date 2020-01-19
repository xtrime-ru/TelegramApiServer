<?php

namespace TelegramApiServer\MadelineProtoExtensions;

use Amp\Loop;
use TelegramApiServer\Client;
use TelegramApiServer\Config;
use TelegramApiServer\EventObservers\EventHandler;
use function Amp\call;
use \danog\MadelineProto;

class CombinedApiExtensions
{
    private MadelineProto\CombinedAPI $madelineProtoCombined;

    public function __construct(MadelineProto\CombinedAPI $madelineProtoCombined)
    {
        $this->madelineProtoCombined = $madelineProtoCombined;
    }

    public function addInstance(string $session)
    {
        $this->madelineProtoCombined->loop(function() use($session) {
            $file = Client::getSessionFile($session);
            $settings = Config::getInstance()->get('telegram');
            $this->madelineProtoCombined->addInstance($file, $settings);

            /** @var MadelineProto\API $madelineProto */
            $madelineProto = $this->madelineProtoCombined->instances[$file];
            $madelineProto->async(true);
            yield $madelineProto->getSelf();
            Loop::defer(static function() use($madelineProto) {
                $madelineProto->setEventHandler(EventHandler::class);
                $madelineProto->loop();
            });
        });

        return $this->getInstanceList();
    }


    public function removeInstance(string $session):array
    {
        $file = Client::getSessionFile($session);
        /** @var MadelineProto\API $madelineProto */
        $madelineProto = $this->madelineProtoCombined->instances[$file];
        $madelineProto->setNoop();
        $this->madelineProtoCombined->removeInstance($file);

        return $this->getInstanceList();
    }

    public function getInstanceList(): array
    {
        $sessions = [];
        foreach ($this->madelineProtoCombined->instances as $file => $instance) {
            /** @var MadelineProto\API $instance */
            $status = '';
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

            $session = Client::getSessionName($file);
            $sessions[$session] = [
                'session' => $session,
                'file' => $file,
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