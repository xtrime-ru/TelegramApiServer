<?php

namespace TelegramApiServer\Migrations;

class StartUpFixes
{
    public static function fix(): void
    {
        define('MADELINE_WORKER_TYPE', 'madeline-ipc');
    }
}