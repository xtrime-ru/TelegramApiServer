<?php

namespace TelegramApiServer\Migrations;

class StartUpFixes
{
    public static function fix(): void
    {
        define('MADELINE_WORKER_TYPE', 'madeline-ipc');
    }

    public static function removeBrokenIpc(string $session): void
    {
        info('Removing ipc sockets from sessions to fix startup' . PHP_EOL);
        foreach (glob(ROOT_DIR . "/$session/*ipc") as $file) {
            info("removing: $file");
            unlink($file);
        }
    }
}