<?php

namespace TelegramApiServer\Server;

use Amp\Future;
use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Revolt\EventLoop;
use RuntimeException;
use TelegramApiServer\Config;
use TelegramApiServer\Logger;
use Throwable;
use UnexpectedValueException;
use function Amp\async;
use function Amp\Future\awaitAll;
use function Amp\trapSignal;

class HealthCheck
{
    private static string $host = '127.0.0.1';
    private static int $port = 9503;
    private static int $checkInterval = 30;
    private static int $requestTimeout = 60;

    /**
     * Sends requests to /system and /api
     * In case of failure will shut down main process.
     *
     * @param int $parentPid
     *      Pid of process to shut down in case of failure.
     */
    public static function start(int $parentPid): void
    {
        static::$host = (string)Config::getInstance()->get('server.address');
        if (static::$host === '0.0.0.0') {
            static::$host = '127.0.0.1';
        }
        static::$port = (int)Config::getInstance()->get('server.port');

        static::$checkInterval = (int)Config::getInstance()->get('health_check.interval');
        static::$requestTimeout = (int)Config::getInstance()->get('health_check.timeout');

        EventLoop::repeat(static::$checkInterval, static function () use ($parentPid) {
            try {
                Logger::getInstance()->info('Start health check');
                if (!self::isProcessAlive($parentPid)) {
                    throw new RuntimeException('Parent process died');
                }
                $sessions = static::getSessionList();
                $sessionsForCheck = static::getLoggedInSessions($sessions);
                $futures = [];
                foreach ($sessionsForCheck as $session) {
                    $futures[] = static::checkSession($session);
                }
                awaitAll($futures);

                Logger::getInstance()->info('Health check ok. Sessions checked: ' . count($sessionsForCheck));
            } catch (Throwable $e) {
                Logger::getInstance()->error($e->getMessage());
                Logger::getInstance()->critical('Health check failed');
                if (self::isProcessAlive($parentPid)) {
                    Logger::getInstance()->critical('Killing parent process');

                    exec("kill -2 $parentPid");
                    if (self::isProcessAlive($parentPid)) {
                        exec("kill -9 $parentPid");
                    }
                }
                exit(1);
            }
        });

        trapSignal([SIGINT, SIGTERM]);
        Logger::getInstance()->critical('Health check process exit');

    }

    private static function getSessionList(): array
    {
        $url = sprintf("http://%s:%s/system/getSessionList", static::$host, static::$port);

        $response = static::sendRequest($url);

        if ($response === false) {
            throw new UnexpectedValueException('No response from /system');
        }

        return json_decode($response, true, 10, JSON_THROW_ON_ERROR)['response']['sessions'];
    }

    private static function getLoggedInSessions(array $sessions): array
    {
        $loggedInSessions = [];
        foreach ($sessions as $sessionName => $session) {
            if ($session['status'] === 'LOGGED_IN') {
                $loggedInSessions[] = $sessionName;
            }
        }

        return $loggedInSessions;
    }

    private static function checkSession(string $sessionName): Future
    {
        return async(function () use ($sessionName) {
            $url = sprintf("http://%s:%s/api/%s/getSelf", static::$host, static::$port, $sessionName);
            $response = static::sendRequest($url);
            $response = json_decode($response, true, 10, JSON_THROW_ON_ERROR);
            if (empty($response['response'])) {
                Logger::getInstance()->error('Health check response: ', $response);
                throw new RuntimeException("Failed health check: $url");
            }
            return $response;
        });
    }


    private static function sendRequest(string $url): string
    {
        $client = (new HttpClientBuilder)::buildDefault();
        $request = new Request($url);
        $request->setInactivityTimeout(static::$requestTimeout);
        $request->setTransferTimeout(static::$requestTimeout);

        $response = $client->request($request);
        return $response->getBody()->buffer();
    }

    private static function isProcessAlive(int $pid): bool
    {
        $result = exec("ps -p $pid | grep $pid");
        return !empty($result);
    }
}