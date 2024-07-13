<?php declare(strict_types=1);

namespace TelegramApiServer;

use Amp\Sync\LocalKeyedMutex;
use danog\MadelineProto\API;
use danog\MadelineProto\APIWrapper;
use danog\MadelineProto\Settings;
use danog\MadelineProto\Settings\Database\SerializerType;
use danog\MadelineProto\SettingsAbstract;
use InvalidArgumentException;
use Psr\Log\LogLevel;
use ReflectionProperty;
use RuntimeException;
use TelegramApiServer\EventObservers\EventObserver;

final class Client
{
    public static Client $self;
    /** @var API[] */
    public array $instances = [];

    public static function getInstance(): Client
    {
        if (empty(self::$self)) {
            self::$self = new static();
        }
        return self::$self;
    }

    public function connect(array $sessionFiles)
    {
        warning(PHP_EOL . 'Starting MadelineProto...' . PHP_EOL);

        foreach ($sessionFiles as $file) {
            $sessionName = Files::getSessionName($file);
            $this->addSession($sessionName);
            $this->startLoggedInSession($sessionName);
        }

        $this->startNotLoggedInSessions();

        $sessionsCount = \count($sessionFiles);
        warning(
            "\nTelegramApiServer ready."
            . "\nNumber of sessions: {$sessionsCount}."
        );
    }

    private static LocalKeyedMutex $mutex;

    public function addSession(string $session, array $settings = []): API
    {
        self::$mutex ??= new LocalKeyedMutex;
        $lock = self::$mutex->acquire($session);
        if (isset($this->instances[$session])) {
            throw new InvalidArgumentException('Session already exists');
        }
        $file = Files::getSessionFile($session);
        Files::checkOrCreateSessionFolder($file);

        if ($settings) {
            Files::saveSessionSettings($session, $settings);
        }
        $settings = \array_replace_recursive(
            (array) Config::getInstance()->get('telegram'),
            Files::getSessionSettings($session),
        );

        $settingsObject = self::getSettingsFromArray($session, $settings);

        $instance = new API($file, $settingsObject);
        $instance->updateSettings($settingsObject);

        $this->instances[$session] = $instance;
        return $instance;
    }

    public function removeSession(string $session): void
    {
        if (empty($this->instances[$session])) {
            throw new InvalidArgumentException('Session not found');
        }

        EventObserver::stopEventHandler($session, true);

        $instance = $this->instances[$session];
        unset($this->instances[$session]);

        if (!empty($instance->API)) {
            $instance->unsetEventHandler();
        }
        unset($instance);
        \gc_collect_cycles();
    }

    public function getSession(?string $session = null): API
    {
        if (!$this->instances) {
            throw new RuntimeException(
                'No sessions available. Call /system/addSession?session=%session_name% or restart server with --session option'
            );
        }

        if (!$session) {
            if (\count($this->instances) === 1) {
                $session = (string) \array_key_first($this->instances);
            } else {
                throw new InvalidArgumentException(
                    'Multiple sessions detected. Specify which session to use. See README for examples.'
                );
            }
        }

        if (empty($this->instances[$session])) {
            throw new InvalidArgumentException('Session not found.');
        }

        return $this->instances[$session];
    }

    private function startNotLoggedInSessions(): void
    {
        foreach ($this->instances as $name => $instance) {
            if ($instance->getAuthorization() !== API::LOGGED_IN) {
                {
                    //Disable logging to stdout
                    $logLevel = Logger::getInstance()->minLevelIndex;
                    Logger::getInstance()->minLevelIndex = Logger::$levels[LogLevel::ERROR];
                    $instance->echo("Authorizing session: {$name}\n");
                    $instance->start();

                    //Enable logging to stdout
                    Logger::getInstance()->minLevelIndex = $logLevel;
                }
                $this->startLoggedInSession($name);
            }
        }
    }

    public function startLoggedInSession(string $sessionName): void
    {
        if ($this->instances[$sessionName]->getAuthorization() === API::LOGGED_IN) {
            if (
                $this->instances[$sessionName]->getEventHandler() instanceof \__PHP_Incomplete_Class
            ) {
                $this->instances[$sessionName]->unsetEventHandler();
            }
            $this->instances[$sessionName]->start();
            $this->instances[$sessionName]->echo("Started session: {$sessionName}\n");
        }
    }

    public static function getWrapper(API $madelineProto): APIWrapper
    {
        $property = new ReflectionProperty($madelineProto, "wrapper");
        /** @var APIWrapper $wrapper */
        $wrapper = $property->getValue($madelineProto);
        return $wrapper;
    }

    private static function getSettingsFromArray(string $session, array $settings, SettingsAbstract $settingsObject = new Settings()): SettingsAbstract
    {
        foreach ($settings as $key => $value) {
            if (\is_array($value) && $key !== 'proxies') {
                if ($key === 'db' && isset($value['type'])) {
                    $type = match ($value['type']) {
                        'memory' => new Settings\Database\Memory(),
                        'mysql' => new Settings\Database\Mysql(),
                        'postgres' => new Settings\Database\Postgres(),
                        'redis' => new Settings\Database\Redis(),
                    };
                    $settingsObject->setDb($type);

                    if ($type instanceof Settings\Database\Memory) {
                        self::getSettingsFromArray($session, [], $type);
                    } else {
                        $type->setEphemeralFilesystemPrefix($session);
                        self::getSettingsFromArray($session, $value[$value['type']], $type);
                    }

                    unset($value[$value['type']], $value['type'],);
                    if (\count($value) === 0) {
                        continue;
                    }
                }

                $method = 'get' . \ucfirst(\str_replace('_', '', \ucwords($key, '_')));
                self::getSettingsFromArray($session, $value, $settingsObject->$method());
            } else {
                if ($key === 'serializer' && \is_string($value)) {
                    $value = SerializerType::from($value);
                }
                $method = 'set' . \ucfirst(\str_replace('_', '', \ucwords($key, '_')));
                $settingsObject->$method($value);
            }
        }
        return $settingsObject;
    }

}
