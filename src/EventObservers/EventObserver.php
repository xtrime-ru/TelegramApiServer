<?php

namespace TelegramApiServer\EventObservers;


use Amp\Promise;
use TelegramApiServer\Client;
use TelegramApiServer\Logger;
use function Amp\call;

class EventObserver
{
    use ObserverTrait;

    /** @var int[]  */
    private static array $sessionClients = [];

    public static function notify(array $update, string $sessionName) {
        foreach (static::$subscribers as $clientId => $callback) {
            notice("Pass update to callback. ClientId: {$clientId}");
            $callback($update, $sessionName);
        }
    }

    private static function addSessionClient(string $session): void
    {
        if (empty(static::$sessionClients[$session])) {
            static::$sessionClients[$session] = 0;
        }
        ++static::$sessionClients[$session];
    }

    private static function removeSessionClient(string $session): void
    {
        if (!empty(static::$sessionClients[$session])) {
            --static::$sessionClients[$session];
        }
    }

    public static function startEventHandler(?string $requestedSession = null): Promise
    {
        return call(static function() use($requestedSession) {
            $sessions = [];
            if ($requestedSession === null) {
                $sessions = array_keys(Client::getInstance()->instances);
            } else {
                $sessions[] = $requestedSession;
            }

            foreach ($sessions as $session) {
                static::addSessionClient($session);
                if (static::$sessionClients[$session] === 1) {
                    try {
                        warning("Start EventHandler: {$session}");
                        yield Client::getInstance()->getSession($session)->setEventHandler(EventHandler::class);
                    } catch (\Throwable $e) {
                        static::removeSessionClient($session);
                        error('Cant set EventHandler', [
                            'session' => $session,
                            'exception' => Logger::getExceptionAsArray($e)
                        ]);
                    }
                }
            }
        });
    }

    public static function stopEventHandler(?string $requestedSession = null, bool $force = false): void
    {
        $sessions = [];
        if ($requestedSession === null) {
            $sessions = array_keys(Client::getInstance()->instances);
        } else {
            $sessions[] = $requestedSession;
        }
        foreach ($sessions as $session) {
            static::removeSessionClient($session);
            if (empty(static::$sessionClients[$session]) || $force) {
                warning("Stop EventHandler: {$session}");
                Client::getInstance()->getSession($session)->setNoop();
                unset(EventHandler::$instances[$session]);
            }
        }

    }

}