<?php

declare(strict_types=1);

namespace App;

use PDO;

/**
 * PDO connection for SQLite (zero-setup default) or MySQL/MariaDB.
 * The schema is created automatically on first connection.
 */
final class Database
{
    public static function fromEnv(string $rootDir): PDO
    {
        $driver = Env::get('DB_CONNECTION', 'sqlite');

        return match ($driver) {
            'sqlite' => self::sqlite(self::resolvePath(Env::get('DB_DATABASE', 'storage/database.sqlite'), $rootDir)),
            'mysql' => self::mysql(
                Env::get('DB_HOST', '127.0.0.1'),
                (int) Env::get('DB_PORT', '3306'),
                Env::get('DB_DATABASE', 'invoice_generator'),
                Env::get('DB_USERNAME', 'root'),
                Env::get('DB_PASSWORD', ''),
            ),
            default => throw new \RuntimeException("Unsupported DB_CONNECTION \"$driver\" (use sqlite or mysql)."),
        };
    }

    public static function sqlite(string $path): PDO
    {
        if ($path !== ':memory:') {
            $dir = dirname($path);
            if (!is_dir($dir) && !mkdir($dir, 0775, true) && !is_dir($dir)) {
                throw new \RuntimeException("Cannot create database directory: $dir");
            }
        }

        $pdo = new PDO('sqlite:' . $path, null, null, self::options());
        $pdo->exec('PRAGMA foreign_keys = ON');
        $pdo->exec('PRAGMA busy_timeout = 5000');

        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS invoices (
                id            INTEGER PRIMARY KEY AUTOINCREMENT,
                number        TEXT    NOT NULL UNIQUE COLLATE NOCASE,
                customer_name TEXT    NOT NULL,
                issue_date    TEXT    NOT NULL,
                due_date      TEXT    NOT NULL,
                total_cents   INTEGER NOT NULL,
                data          TEXT    NOT NULL,
                created_at    TEXT    NOT NULL,
                updated_at    TEXT    NOT NULL
            )
        SQL);
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS email_log (
                id          INTEGER PRIMARY KEY AUTOINCREMENT,
                invoice_id  INTEGER NOT NULL REFERENCES invoices(id) ON DELETE CASCADE,
                recipient   TEXT    NOT NULL,
                status      TEXT    NOT NULL,
                error       TEXT,
                created_at  TEXT    NOT NULL
            )
        SQL);

        return $pdo;
    }

    public static function mysql(string $host, int $port, string $database, string $user, string $password): PDO
    {
        $pdo = new PDO(
            "mysql:host=$host;port=$port;dbname=$database;charset=utf8mb4",
            $user,
            $password,
            // FOUND_ROWS: UPDATE reports matched rows, not only changed ones.
            self::options() + [PDO::MYSQL_ATTR_FOUND_ROWS => true],
        );

        // utf8mb4_unicode_ci is case-insensitive, so "inv-0001" and "INV-0001" collide like in SQLite.
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS invoices (
                id            INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                number        VARCHAR(40)  NOT NULL,
                customer_name VARCHAR(120) NOT NULL,
                issue_date    CHAR(10)     NOT NULL,
                due_date      CHAR(10)     NOT NULL,
                total_cents   BIGINT       NOT NULL,
                data          MEDIUMTEXT   NOT NULL,
                created_at    CHAR(20)     NOT NULL,
                updated_at    CHAR(20)     NOT NULL,
                UNIQUE KEY invoices_number_unique (number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);
        $pdo->exec(<<<'SQL'
            CREATE TABLE IF NOT EXISTS email_log (
                id          INT UNSIGNED NOT NULL AUTO_INCREMENT PRIMARY KEY,
                invoice_id  INT UNSIGNED NOT NULL,
                recipient   VARCHAR(254) NOT NULL,
                status      VARCHAR(20)  NOT NULL,
                error       TEXT         NULL,
                created_at  CHAR(20)     NOT NULL,
                CONSTRAINT email_log_invoice_fk FOREIGN KEY (invoice_id) REFERENCES invoices(id) ON DELETE CASCADE
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
        SQL);

        return $pdo;
    }

    /** @return array<int, mixed> */
    private static function options(): array
    {
        return [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
    }

    private static function resolvePath(string $path, string $rootDir): string
    {
        if ($path === ':memory:' || preg_match('#^([A-Za-z]:)?[\\\\/]#', $path)) {
            return $path;
        }

        return $rootDir . '/' . $path;
    }
}
