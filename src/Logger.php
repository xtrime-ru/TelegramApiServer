<?php declare(strict_types=1);

namespace TelegramApiServer;

use danog\MadelineProto;
use DateTimeInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;
use TelegramApiServer\EventObservers\LogObserver;
use Throwable;

use const PHP_EOL;

final class Logger extends AbstractLogger
{
    private static ?Logger $instanse = null;

    public static array $levels = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    public static array $madelineLevels = [
        MadelineProto\Logger::ULTRA_VERBOSE => LogLevel::DEBUG,
        MadelineProto\Logger::VERBOSE => LogLevel::INFO,
        MadelineProto\Logger::NOTICE => LogLevel::NOTICE,
        MadelineProto\Logger::WARNING => LogLevel::WARNING,
        MadelineProto\Logger::ERROR => LogLevel::ERROR,
        MadelineProto\Logger::FATAL_ERROR => LogLevel::CRITICAL,
    ];

    private static string $dateTimeFormat = 'Y-m-d H:i:s';
    public int $minLevelIndex;
    private \Closure $formatter;

    protected function __construct(string $minLevel = LogLevel::WARNING, ?\Closure $formatter = null)
    {
        if (null === $minLevel) {
            if (isset($_ENV['SHELL_VERBOSITY']) || isset($_SERVER['SHELL_VERBOSITY'])) {
                switch ((int) (isset($_ENV['SHELL_VERBOSITY']) ? $_ENV['SHELL_VERBOSITY'] :
                    $_SERVER['SHELL_VERBOSITY'])) {
                    case -1:
                        $minLevel = LogLevel::ERROR;
                        break;
                    case 1:
                        $minLevel = LogLevel::NOTICE;
                        break;
                    case 2:
                        $minLevel = LogLevel::INFO;
                        break;
                    case 3:
                        $minLevel = LogLevel::DEBUG;
                        break;
                }
            }
        }

        if (!isset(self::$levels[$minLevel])) {
            throw new InvalidArgumentException(\sprintf('The log level "%s" does not exist.', $minLevel));
        }

        $this->minLevelIndex = self::$levels[$minLevel];
        $this->formatter = $formatter ?: $this->format(...);
    }

    public static function getInstance(): Logger
    {
        if (!self::$instanse) {
            $settings = Config::getInstance()->get('telegram');

            $loggerLevel = self::$madelineLevels[$settings['logger']['level']];
            self::$instanse = new static($loggerLevel);
        }

        return self::$instanse;
    }

    /**
     * {@inheritdoc}
     */
    public function log($level, $message, array $context = []): void
    {
        if (!isset(self::$levels[$level])) {
            throw new InvalidArgumentException(\sprintf('The log level "%s" does not exist.', $level));
        }

        LogObserver::notify($level, $message, $context);

        if (self::$levels[$level] < $this->minLevelIndex) {
            return;
        }

        $formatter = $this->formatter;
        echo $formatter($level, $message, $context);
    }

    private function format(string $level, string $message, array $context): string
    {
        if (false !== \strpos($message, '{')) {
            $replacements = [];
            foreach ($context as $key => $val) {
                if ($val instanceof Throwable) {
                    $context[$key] = self::getExceptionAsArray($val);
                }
                if (null === $val || \is_scalar($val) || (\is_object($val) && \method_exists($val, '__toString'))) {
                    $replacements["{{$key}}"] = $val;
                } else {
                    if ($val instanceof DateTimeInterface) {
                        $replacements["{{$key}}"] = $val->format(self::$dateTimeFormat);
                    } else {
                        if (\is_object($val)) {
                            $replacements["{{$key}}"] = '[object ' . \get_class($val) . ']';
                        } else {
                            $replacements["{{$key}}"] = '[' . \gettype($val) . ']';
                        }
                    }
                }
            }

            $message = \strtr($message, $replacements);
        }

        return \sprintf(
            '[%s] [%s] %s %s',
            \date(self::$dateTimeFormat),
            $level,
            $message,
            $context ?
                    "\n" .
                    \json_encode(
                        $context,
                        JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE | JSON_PRETTY_PRINT | JSON_UNESCAPED_LINE_TERMINATORS | JSON_UNESCAPED_SLASHES
                    )
                    : ''
        ) . PHP_EOL;
    }

    public static function getExceptionAsArray(Throwable $exception)
    {
        return [
            'exception' => \get_class($exception),
            'message' => $exception->getMessage(),
            'file' => $exception->getFile(),
            'line' => $exception->getLine(),
            'code' => $exception->getCode(),
            'backtrace' => \array_slice($exception->getTrace(), 0, 3),
            'previous_exception' => $exception->getPrevious(),
        ];
    }
}
