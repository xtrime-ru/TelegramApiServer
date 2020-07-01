<?php

namespace TelegramApiServer\Migrations;

use TelegramApiServer\Files;

class SessionsMigration
{
    public static function move($rootDir = ROOT_DIR)
    {
        foreach (glob("$rootDir/*" . Files::SESSION_EXTENSION) as $oldFile) {
            preg_match(
                '~^' . "{$rootDir}(?'session'.*)" . preg_quote(Files::SESSION_EXTENSION, '\\') . '$~',
                $oldFile,
                $matches
            );

            if ($session = $matches['session'] ?? null) {
                $session = Files::getSessionFile($session);
                Files::checkOrCreateSessionFolder($session);

                rename($oldFile, "{$rootDir}/{$session}");
                rename("{$oldFile}.lock", "{$rootDir}/{$session}.lock");
            }
        }
    }

}