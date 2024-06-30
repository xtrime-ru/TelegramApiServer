<?php declare(strict_types=1);

namespace TelegramApiServer\EventObservers;

use TelegramApiServer\Logger;
use Throwable;

final class LogObserver
{
    use ObserverTrait;

    public static function notify(string $level, string $message, array $context = []): void
    {
        foreach (self::$subscribers as $clientId => $callback) {
            $callback($level, $message, $context);
        }
    }

    /**
     * @param mixed|array|string $message
     */
    public static function log($message, int $level)
    {
        if (\is_scalar($message)) {
            Logger::getInstance()->log(Logger::$madelineLevels[$level], (string) $message);
        } else {
            if ($message instanceof Throwable) {
                $message = Logger::getExceptionAsArray($message);
            }
            if (\is_array($message)) {
                Logger::getInstance()->log(Logger::$madelineLevels[$level], '', $message);
            } else {
                Logger::getInstance()->log(
                    Logger::$madelineLevels[$level],
                    \json_encode(
                        $message,
                        JSON_UNESCAPED_UNICODE |
                        JSON_PRETTY_PRINT |
                        JSON_INVALID_UTF8_SUBSTITUTE |
                        JSON_UNESCAPED_LINE_TERMINATORS
                    )
                );
            }
        }
    }
}
