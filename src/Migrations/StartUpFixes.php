<?php

namespace TelegramApiServer\Migrations;

class StartUpFixes
{
    public static function fix(): void
    {
        define('MADELINE_WORKER_TYPE', 'madeline-ipc');

        info('Removing ipc sockets from sessions to fix startup' . PHP_EOL);
        foreach (glob(ROOT_DIR . '/sessions/*/*ipc') as $file) {
            info("removing: $file");
            unlink($file);
        }


        foreach (glob(ROOT_DIR . '/sessions/*/safe.php') as $file) {
            $content = file_get_contents($file);
            $oldLine = 'O:43:"danog\MadelineProto\Db\NullCache\MysqlArray"';
            if (str_contains($content, $oldLine)) {
                $content = str_replace('O:43:"danog\MadelineProto\Db\NullCache\MysqlArray"', 'O:33:"danog\MadelineProto\Db\MysqlArray"', $content);
                file_put_contents($file, $content);
            }
        }
    }
}