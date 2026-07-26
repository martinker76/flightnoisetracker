<?php

declare(strict_types=1);

namespace App\Config;

use PDO;
use PDOException;

/**
 * Singleton PDO database connection manager.
 */
class Database
{
    private static ?PDO $instance = null;

    /**
     * Get the shared PDO instance.
     */
    public static function getConnection(): PDO
    {
        if (self::$instance === null) {
            $config = require __DIR__ . '/../../config/app.php';
            $db = $config['db'];

            $dsn = sprintf(
                'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
                $db['host'],
                $db['port'],
                $db['name']
            );

            try {
                self::$instance = new PDO($dsn, $db['user'], $db['pass'], [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false,
                    PDO::MYSQL_ATTR_INIT_COMMAND => "SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci",
                ]);
            } catch (PDOException $e) {
                throw new PDOException('Database connection failed: ' . $e->getMessage(), (int)$e->getCode());
            }
        }

        return self::$instance;
    }

    /**
     * Reset the connection (useful for testing).
     */
    public static function reset(): void
    {
        self::$instance = null;
    }
}
