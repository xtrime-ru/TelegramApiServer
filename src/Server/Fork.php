<?php

namespace TelegramApiServer\Server;

use RuntimeException;

class Fork
{
    public static function run(callable $callback)
    {
        $pid = pcntl_fork();
        if ($pid === -1) {
            throw new RuntimeException('Could not fork');
        }
        if ($pid !== 0) {
            return;
        }
        $callback();
        exit;
    }
}