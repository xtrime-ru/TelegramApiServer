<?php
declare(strict_types=1);

namespace TelegramApiServer\EventObservers;

use Amp\Redis\RedisClient;
use Revolt\EventLoop;
use TelegramApiServer\Config;
use TelegramApiServer\Files;
use Throwable;

use function Amp\Redis\createRedisClient;

final class EventHandler extends \danog\MadelineProto\EventHandler
{
    public static array $instances = [];
    private string $sessionName;

    public static RedisClient|null $redisDb = null;
    private array $sessionSettings = [];
    public ?int $sessionId = null;

    public function onStart()
    {
        $this->sessionName = Files::getSessionName($this->wrapper->getSession()->getSessionPath());
        $this->sessionId = $this->getSelf()['id'];
        $this->initRedisDb();
        // $this->report('Session '.$this->sessionName . ' started with settings:'.PHP_EOL . json_encode($this->sessionSettings));
        EventLoop::repeat(60.0, function () {
            $this->initRedisDb();
        });

        if (empty(self::$instances[$this->sessionName])) {
            self::$instances[$this->sessionName] = true;
            warning("Event observer CONSTRUCTED: {$this->sessionName}");
        }
    }

    public function __destruct()
    {
        if (empty($this->sessionName)) {
            return;
        }
        unset(self::$instances[$this->sessionName]);
        warning("Event observer DESTRUCTED: {$this->sessionName}");
    }


    public function onAny($update)
    {
        if (isset($update['message'])) {
            if (isset($update['message']['out']) && $update['message']['out']) {
                return;
            }
            try {
                $peerId = $this->getId($update['message']['peer_id']);
                $info = $this->getInfo($peerId);
                if ($info['Chat']['left'] ?? false) {
                    return;
                }
            } catch (\Throwable $exception) {
            }
        } else {
            return;
        }


        info("Received update from session: {$this->sessionName}");
        $update['fromAny'] = true;
        EventObserver::notify($update, $this->sessionName);
    }

    public function initRedisDb(): void
    {
        if (($redisUrl = Config::getInstance()->get('laravel.redis_url'))) {
            try {
                self::$redisDb ??= createRedisClient($redisUrl);
                if (!empty(self::$redisDb)) {
                    try {
                        $this->sessionSettings = EventHandler::$redisDb->getMap(
                            'session:settings:'.$this->sessionName
                        )->getAll();
                        return;
                    } catch (Throwable $exception) {
                        self::$redisDb = null;
                        $this->report($exception->getMessage());
                    }
                }
                return;
            } catch (Throwable $exception) {
                self::$redisDb = null;
                $this->report($exception->getMessage());
            }
        }
    }
}
