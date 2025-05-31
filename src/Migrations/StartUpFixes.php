<?php

namespace TelegramApiServer\Migrations;

use danog\MadelineProto\Magic;

final class StartUpFixes
{
    public static function fix(): void
    {
        \define('MADELINE_WORKER_TYPE', 'madeline-ipc');
        Magic::$isIpcWorker = true;
    }

    public static function removeBrokenIpc(string $session): void
    {
        info('Removing ipc sockets from sessions to fix startup' . PHP_EOL);
        foreach (glob(ROOT_DIR . "/$session/*ipc") as $file) {
            info("removing: $file");
            unlink($file);
        }
    }

    public static function removeOldSettings(string $session): void
    {
        info('Removing old settings file to fix startup if db settings changed' . PHP_EOL);
        foreach (glob(ROOT_DIR . "/$session/safe.php*") as $file) {
            info("removing: $file");
            unlink($file);
        }
    }
}
