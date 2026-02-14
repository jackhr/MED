<?php

declare(strict_types=1);

final class Database
{
    private static ?\PDO $connection = null;

    public static function connection(): \PDO
    {
        if (self::$connection instanceof \PDO) {
            return self::$connection;
        }

        $driver = Env::get('DB_DRIVER', 'mysql');
        if ($driver !== 'mysql') {
            throw new \RuntimeException('Unsupported DB_DRIVER. Use "mysql".');
        }

        $host = Env::get('DB_HOST', '127.0.0.1');
        $port = Env::get('DB_PORT', '3306');
        $dbName = Env::get('DB_NAME', 'medicine_log');
        $charset = Env::get('DB_CHARSET', 'utf8mb4');
        $username = Env::get('DB_USER', 'root');
        $password = Env::get('DB_PASS', '');

        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=%s',
            $host,
            $port,
            $dbName,
            $charset
        );

        self::$connection = new \PDO($dsn, $username, $password, [
            \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
            \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
            \PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        return self::$connection;
    }
}
