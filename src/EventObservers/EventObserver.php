<?php
declare(strict_types=1);

namespace TelegramApiServer\EventObservers;

use danog\MadelineProto\APIWrapper;
use ReflectionProperty;
use TelegramApiServer\Client;
use TelegramApiServer\Config;
use TelegramApiServer\Logger;
use Throwable;

final class EventObserver
{
    use ObserverTrait;

    /** @var int[] */
    public static array $sessionClients = [];

    public static function notify(array $update, string $sessionName)
    {
        $activeClients = 0;
        foreach (self::$subscribers as $clientId => $callback) {
            $activeClients++;
            notice("Pass update to callback. ClientId: {$clientId}");
            $callback($update, $sessionName);
        }
        if ($activeClients === 0 && !empty(EventHandler::$redisDb)) {
            if (Config::getInstance()->get('laravel.handle_old_data')) {
                $update['subs'] = count(static::$subscribers);
                $update['auto_start'] = json_encode(Config::getInstance()->get('laravel.auto_start'));
                if ($update['subs'] == 0) {
                    if (isset($update['message']['peer_id'])) {
                        try {
                            EventHandler::$redisDb->getList('missed_updates_'.$sessionName)->pushTail(
                                json_encode($update)
                            );
                        } catch (Throwable $exception) {
                            error("Redis: {$exception->getMessage()}");
                        }
                    }
                }
            }
        }
    }

    private static function addSessionClient(string $session): void
    {
        if (empty(self::$sessionClients[$session])) {
            self::$sessionClients[$session] = 0;
        }
        ++self::$sessionClients[$session];
    }

    private static function removeSessionClient(string $session): void
    {
        if (!empty(self::$sessionClients[$session])) {
            --self::$sessionClients[$session];
        }
    }

    public static function startEventHandler(?string $requestedSession = null): void
    {
        $sessions = [];
        if ($requestedSession === null) {
            $sessions = \array_keys(Client::getInstance()->instances);
        } else {
            $sessions[] = $requestedSession;
        }

        foreach ($sessions as $session) {
            self::addSessionClient($session);
            if (self::$sessionClients[$session] === 1) {
                warning("Start EventHandler: {$session}");
                try {
                    $instance = Client::getInstance()->getSession($session);
                    $property = new ReflectionProperty($instance, "wrapper");
                    /** @var APIWrapper $wrapper */
                    $wrapper = $property->getValue($instance);
                    EventHandler::cachePlugins(EventHandler::class);
                    $wrapper->getAPI()->setEventHandler(EventHandler::class);
                } catch (Throwable $e) {
                    self::removeSessionClient($session);
                    error('Cant set EventHandler', [
                        'session' => $session,
                        'exception' => Logger::getExceptionAsArray($e),
                    ]);
                }
            }
        }
    }

    public static function stopEventHandler(?string $requestedSession = null, bool $force = false): void
    {
        $sessions = [];
        if ($requestedSession === null) {
            $sessions = \array_keys(Client::getInstance()->instances);
        } else {
            $sessions[] = $requestedSession;
        }
        foreach ($sessions as $session) {
            self::removeSessionClient($session);
            if (empty(self::$sessionClients[$session]) || $force) {
                warning("Stopping EventHandler: {$session}");
                Client::getInstance()->instances[$session]->unsetEventHandler();
                unset(EventHandler::$instances[$session], self::$sessionClients[$session]);
            }
        }
    }

}
