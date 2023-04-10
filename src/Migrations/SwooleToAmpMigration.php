<?php

namespace TelegramApiServer\Migrations;

use UnexpectedValueException;

class SwooleToAmpMigration
{
    public static function check()
    {
        if (getenv('SWOOLE_SERVER_ADDRESS')) {
            throw new UnexpectedValueException('Please, update .env file! See .env.example');
        }
    }

}