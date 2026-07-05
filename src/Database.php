<?php
namespace App;

use Illuminate\Database\Capsule\Manager as Capsule;

class Database
{
    public static function boot(Settings $settings): void
    {
        $capsule = new Capsule;
        $capsule->addConnection([
            'driver'    => 'mysql',
            'host'      => $settings->get('DB_HOST', 'db'),
            'database'  => $settings->get('MYSQL_DATABASE', 'restaurant'),
            'username'  => $settings->get('MYSQL_USER', 'restuser'),
            'password'  => $settings->get('MYSQL_PASSWORD', 'restpass'),
            'charset'   => 'utf8mb4',
            'collation' => 'utf8mb4_unicode_ci',
            'prefix'    => '',
            'options'   => [
                \PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES 'utf8mb4'",
            ],
        ]);

        $capsule->setAsGlobal();
        $capsule->bootEloquent();
    }
}