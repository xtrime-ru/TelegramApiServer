<?php

namespace TelegramApiServer;

class Files
{

    public static string $sessionExtension = '.madeline';
    public static string $sessionFolder = 'sessions';

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
            '~' . Files::$sessionFolder . "/(?'sessionName'.*?)" . Files::$sessionExtension . '$~',
            $sessionFile,
            $matches
        );

        return $matches['sessionName'] ?? null;
    }

    /**
     * @param string|null $session
     *
     * @return string|null
     */
    public static function getSessionFile(?string $session): ?string
    {
        if (!$session) {
            return null;
        }
        $session = trim(trim($session), '/');
        $session = Files::$sessionFolder . '/' . $session . Files::$sessionExtension;
        $session = str_replace('//', '/', $session);
        return $session;
    }

    public static function globRecursive($pattern, $flags = 0): array
    {
        $files = glob($pattern, $flags) ?: [];
        foreach (glob(dirname($pattern).'/*', GLOB_ONLYDIR|GLOB_NOSORT) as $dir) {
            $files = [...$files, ...static::globRecursive($dir.'/'.basename($pattern), $flags)];
        }
        return $files;
    }

}