<?php

namespace TelegramApiServer\Server;

use Amp\Http\Client\HttpClientBuilder;
use Amp\Http\Client\Request;
use Amp\Loop;
use Amp\Promise;
use RuntimeException;
use TelegramApiServer\Config;
use TelegramApiServer\Logger;
use function Amp\call;

class HealthCheck
{
    private static string $host = '127.0.0.1';
    private static int $port = 9503;
    private static int $checkInterval = 30;
    private static int $requestTimeout = 60;

    /**
     * Sends requests to /system and /api
     * In case of failure will shutdown main process.
     *
     * @param int $parentPid
     *      Pid of process to shutdown in case of failure.
     */
    public static function start(int $parentPid): void
    {
        static::$host = (string) Config::getInstance()->get('server.address');
        if (static::$host === '0.0.0.0') {
            static::$host = '127.0.0.1';
        }
        static::$port = (int) Config::getInstance()->get('server.port');

        static::$checkInterval = (int) Config::getInstance()->get('health_check.interval');
        static::$requestTimeout = (int) Config::getInstance()->get('health_check.timeout');

        try {
            Loop::repeat(static::$checkInterval*1000, function() use($parentPid){
                Logger::getInstance()->info('Start health check');
                if (!self::isProcessAlive($parentPid)) {
                    throw new RuntimeException('Parent process died');
                }
                $sessions = yield from static::getSessionList();
                $sessionsForCheck = static::getLoggedInSessions($sessions);
                $promises = [];
                foreach ($sessionsForCheck as $session) {
                    $promises[] = static::checkSession($session);
                }
                yield $promises;

                Logger::getInstance()->info('Health check ok. Sessions checked: ' . count($sessionsForCheck));
            });

            Loop::run();
        } catch (\Throwable $e) {
            Logger::getInstance()->error($e->getMessage());
            Logger::getInstance()->critical('Health check failed');
            if (self::isProcessAlive($parentPid)) {
                Logger::getInstance()->critical('Killing parent process');
                exec("kill -9 $parentPid");
            }
        }

        Logger::getInstance()->critical('Health check process exit');

    }

    private static function getSessionList()
    {
        $url = sprintf("http://%s:%s/system/getSessionList", static::$host, static::$port);

        $response = yield static::sendRequest($url);

        if ($response === false) {
            throw new \UnexpectedValueException('No response from /system');
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

    private static function checkSession(string $sessionName): Promise
    {
        return call(static function() use($sessionName) {
            $url = sprintf("http://%s:%s/api/%s/getSelf", static::$host, static::$port, $sessionName);
            $response = yield static::sendRequest($url);
            $response = json_decode($response, true, 10, JSON_THROW_ON_ERROR);
            if (empty($response['response'])) {
                Logger::getInstance()->error('Health check response: ', $response);
                throw new RuntimeException("Failed health check: $url");
            }
            return $response;
        });
    }


    private static function sendRequest(string $url): Promise
    {
        return call(function() use($url) {
            $client = (new HttpClientBuilder)::buildDefault();
            $request = new Request($url);
            $request->setInactivityTimeout(static::$requestTimeout*1000);
            $request->setTransferTimeout(static::$requestTimeout*1000);

            $response = yield $client->request($request);
            return yield $response->getBody()->buffer();
        });
    }

    private static function isProcessAlive(int $pid): bool
    {
        $result = exec("ps -p $pid | grep $pid");
        return !empty($result);
    }
}