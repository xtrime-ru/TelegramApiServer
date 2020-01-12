<?php

/*
 * This file is part of the Symfony package.
 *
 * (c) Fabien Potencier <fabien@symfony.com>
 *
 * For the full copyright and license information, please view the LICENSE
 * file that was distributed with this source code.
 */

namespace TelegramApiServer;

use DateTimeInterface;
use Psr\Log\AbstractLogger;
use Psr\Log\InvalidArgumentException;
use Psr\Log\LogLevel;
use danog\MadelineProto;
use function get_class;
use function gettype;
use function is_object;
use const PHP_EOL;

/**
 * Minimalist PSR-3 logger designed to write in stderr or any other stream.
 *
 * @author Kévin Dunglas <dunglas@gmail.com>
 */
class Logger extends AbstractLogger
{
    private static ?Logger $instanse = null;

    private static array $levels = [
        LogLevel::DEBUG => 0,
        LogLevel::INFO => 1,
        LogLevel::NOTICE => 2,
        LogLevel::WARNING => 3,
        LogLevel::ERROR => 4,
        LogLevel::CRITICAL => 5,
        LogLevel::ALERT => 6,
        LogLevel::EMERGENCY => 7,
    ];

    private static array $madelineLevels = [
        LogLevel::DEBUG => MadelineProto\Logger::ULTRA_VERBOSE,
        LogLevel::INFO => MadelineProto\Logger::VERBOSE,
        LogLevel::NOTICE => MadelineProto\Logger::NOTICE,
        LogLevel::WARNING => MadelineProto\Logger::WARNING,
        LogLevel::ERROR => MadelineProto\Logger::ERROR,
        LogLevel::CRITICAL => MadelineProto\Logger::FATAL_ERROR,
        LogLevel::ALERT => MadelineProto\Logger::FATAL_ERROR,
        LogLevel::EMERGENCY => MadelineProto\Logger::FATAL_ERROR,
    ];

    private static string $dateTimeFormat = 'Y-m-d H:i:s';
    private int $minLevelIndex;
    private array $formatter;

    public static function getInstance(): Logger
    {
        if (!static::$instanse) {
            $settings = Config::getInstance()->get('telegram');
            MadelineProto\Logger::$default = null;
            MadelineProto\Logger::constructorFromSettings($settings);

            $conversionTable = array_flip(static::$madelineLevels);
            $loggerLevel = $conversionTable[$settings['logger']['logger_level']];
            static::$instanse = new static($loggerLevel);
        }

        return static::$instanse;
    }

    protected function __construct(string $minLevel = LogLevel::WARNING, callable $formatter = null)
    {
        if (null === $minLevel) {
            if (isset($_ENV['SHELL_VERBOSITY']) || isset($_SERVER['SHELL_VERBOSITY'])) {
                switch ((int) (isset($_ENV['SHELL_VERBOSITY']) ? $_ENV['SHELL_VERBOSITY'] : $_SERVER['SHELL_VERBOSITY'])) {
                    case -1: $minLevel = LogLevel::ERROR; break;
                    case 1: $minLevel = LogLevel::NOTICE; break;
                    case 2: $minLevel = LogLevel::INFO; break;
                    case 3: $minLevel = LogLevel::DEBUG; break;
                }
            }
        }

        if (!isset(self::$levels[$minLevel])) {
            throw new InvalidArgumentException(sprintf('The log level "%s" does not exist.', $minLevel));
        }

        $this->minLevelIndex = self::$levels[$minLevel];
        $this->formatter = $formatter ?: [$this, 'format'];
    }

    /**
     * {@inheritdoc}
     */
    public function log($level, $message, array $context = []): void
    {
        if (!isset(self::$levels[$level])) {
            throw new InvalidArgumentException(sprintf('The log level "%s" does not exist.', $level));
        }

        if (self::$levels[$level] < $this->minLevelIndex) {
            return;
        }

        $formatter = $this->formatter;

        MadelineProto\Logger::log($formatter($level, $message, $context), static::$madelineLevels[$level]);
    }

    private function format(string $level, string $message, array $context): string
    {
        if (false !== strpos($message, '{')) {
            $replacements = [];
            foreach ($context as $key => $val) {
                if (null === $val || is_scalar($val) || (is_object($val) && method_exists($val, '__toString'))) {
                    $replacements["{{$key}}"] = $val;
                } elseif ($val instanceof DateTimeInterface) {
                    $replacements["{{$key}}"] = $val->format(static::$dateTimeFormat);
                } elseif (is_object($val)) {
                    $replacements["{{$key}}"] = '[object '. get_class($val).']';
                } else {
                    $replacements["{{$key}}"] = '['. gettype($val).']';
                }
            }

            $message = strtr($message, $replacements);
        }

        return sprintf('[%s] [%s] %s', date(static::$dateTimeFormat), $level, $message). PHP_EOL;
    }
}