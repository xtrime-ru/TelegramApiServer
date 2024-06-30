<?php declare(strict_types=1);

namespace TelegramApiServer;

use RuntimeException;

final class Files
{

    public const SESSION_EXTENSION = '.madeline';
    public const SETTINGS_EXTENSION = '.settings.json';
    private const SESSION_FOLDER = 'sessions';

    public static function checkOrCreateSessionFolder(string $session): void
    {
        $directory = \dirname($session);
        if ($directory && $directory !== '.' && !\is_dir($directory)) {
            $parentDirectoryPermissions = \fileperms(ROOT_DIR);
            if (!\mkdir($directory, $parentDirectoryPermissions, true) && !\is_dir($directory)) {
                throw new RuntimeException(\sprintf('Directory "%s" was not created', $directory));
            }
        }
    }

    public static function getSessionName(?string $sessionFile): ?string
    {
        if (!$sessionFile) {
            return null;
        }

        \preg_match(
            '~' . self::SESSION_FOLDER . "/(?'sessionName'.*?)" . self::SESSION_EXTENSION . '~',
            $sessionFile,
            $matches
        );

        return $matches['sessionName'] ?? null;
    }

    public static function getSessionFile(?string $session, string $extension = self::SESSION_EXTENSION): ?string
    {
        if (!$session) {
            return null;
        }
        $session = \trim(\trim($session), '/');
        $session = self::SESSION_FOLDER . '/' . $session . $extension;
        $session = \str_replace('//', '/', $session);
        return $session;
    }

    public static function getSessionSettings(string $session): array
    {
        $settingsFile = self::getSessionFile($session, self::SETTINGS_EXTENSION);
        $settings = [];
        if (\file_exists($settingsFile)) {
            $settings = \json_decode(
                \file_get_contents($settingsFile),
                true,
                10,
                JSON_THROW_ON_ERROR
            );
        }

        return $settings;
    }

    public static function saveSessionSettings(string $session, array $settings = []): void
    {
        $settingsFile = self::getSessionFile($session, self::SETTINGS_EXTENSION);
        \file_put_contents(
            $settingsFile,
            \json_encode(
                $settings,
                JSON_THROW_ON_ERROR |
                JSON_INVALID_UTF8_SUBSTITUTE |
                JSON_UNESCAPED_SLASHES |
                JSON_UNESCAPED_UNICODE |
                JSON_PRETTY_PRINT
            )
        );
    }

    public static function globRecursive($pattern, $flags = 0): array
    {
        $files = \glob($pattern, $flags) ?: [];
        foreach (\glob(\dirname($pattern) . '/*', GLOB_ONLYDIR | GLOB_NOSORT) as $dir) {
            $files = [...$files, ...self::globRecursive($dir . '/' . \basename($pattern), $flags)];
        }
        return $files;
    }

}
