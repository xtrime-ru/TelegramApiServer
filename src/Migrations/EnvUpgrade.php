<?php

namespace TelegramApiServer\Migrations;

class EnvUpgrade
{
    public static function mysqlToDbPrefix()
    {
        foreach (glob(ROOT_DIR . '/.env*') as $envFile) {

            $text = file_get_contents($envFile);
            if (str_contains($text, 'MYSQL_')) {
                $text = preg_replace('/^MYSQL_/m', 'DB_', $text);
                file_put_contents($envFile, $text);
            }
        }

    }

}