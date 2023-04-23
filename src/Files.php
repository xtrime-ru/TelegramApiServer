<?php

namespace TelegramApiServer;

use RuntimeException;

class Files
{

    public const SESSION_EXTENSION = '.madeline';
    public const SETTINGS_EXTENSION = '.settings.json';
    private const SESSION_FOLDER = 'sessions';

    public static function checkOrCreateSessionFolder(string $session): void
    {
        $directory = dirname($session);
        if ($directory && $directory !== '.' && !is_dir($directory)) {
            $parentDirectoryPermissions = fileperms(ROOT_DIR);
            if (!mkdir($directory, $parentDirectoryPermissions, true) && !is_dir($directory)) {
                throw new RuntimeException(sprintf('Directory "%s" was not created', $directory));
            }
        }
    }

    public static function getSessionName(?string $sessionFile): ?string
    {
        if (!$sessionFile) {
            return null;
        }

        preg_match(
            '~' . static::SESSION_FOLDER . "/(?'sessionName'.*?)" . static::SESSION_EXTENSION . '~',
            $sessionFile,
            $matches
        );

        return $matches['sessionName'] ?? null;
    }

    /**
     * @param string|null $session
     *
     * @param string $extension
     *
     * @return string|null
     */
    public static function getSessionFile(?string $session, string $extension = self::SESSION_EXTENSION): ?string
    {
        if (!$session) {
            return null;
        }
        $session = trim(trim($session), '/');
        $session = static::SESSION_FOLDER . '/' . $session . $extension;
        $session = str_replace('//', '/', $session);
        return $session;
    }

    public static function getSessionSettings(string $session): array
    {
        $settingsFile = static::getSessionFile($session, static::SETTINGS_EXTENSION);
        $settings = [];
        if (file_exists($settingsFile)) {
            $settings = json_decode(
                file_get_contents($settingsFile),
                true,
                10,
                JSON_THROW_ON_ERROR
            );
        }

        return $settings;
    }

    public static function saveSessionSettings(string $session, array $settings = []): void
    {
        $settingsFile = static::getSessionFile($session, static::SETTINGS_EXTENSION);
        file_put_contents(
            $settingsFile,
            json_encode(
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
        $files = glob($pattern, $flags) ?: [];
        foreach (glob(dirname($pattern) . '/*', GLOB_ONLYDIR | GLOB_NOSORT) as $dir) {
            $files = [...$files, ...static::globRecursive($dir . '/' . basename($pattern), $flags)];
        }
        return $files;
    }

}