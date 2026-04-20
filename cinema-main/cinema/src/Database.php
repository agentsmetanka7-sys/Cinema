<?php

declare(strict_types=1);

namespace CinemaApp\Src;

use PDO;

final class Database
{
    private static ?PDO $connection = null;

    public static function connection(): PDO
    {
        if (self::$connection !== null) {
            return self::$connection;
        }

        $databaseUrl = getenv('DATABASE_URL') ?: '';
        if ($databaseUrl !== '') {
            $parts = parse_url($databaseUrl);
            $scheme = strtolower((string) ($parts['scheme'] ?? ''));
            if ($parts === false || !in_array($scheme, ['mysql', 'mariadb', 'postgres', 'postgresql'], true)) {
                throw new \RuntimeException('Unsupported DATABASE_URL format. Expected mysql://, mariadb://, postgres:// or postgresql://');
            }

            $host = (string) ($parts['host'] ?? 'localhost');
            $dbName = ltrim((string) ($parts['path'] ?? ''), '/');
            $user = (string) ($parts['user'] ?? '');
            $pass = (string) ($parts['pass'] ?? '');
            $query = [];
            parse_str((string) ($parts['query'] ?? ''), $query);

            if ($scheme === 'mysql' || $scheme === 'mariadb') {
                $port = (int) ($parts['port'] ?? 3306);
                $charset = (string) ($query['charset'] ?? getenv('DB_CHARSET') ?: 'utf8mb4');
                $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $host, $port, $dbName, $charset);
            } else {
                $port = (int) ($parts['port'] ?? 5432);
                $sslMode = (string) ($query['sslmode'] ?? getenv('PGSSLMODE') ?: 'require');
                $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s', $host, $port, $dbName, $sslMode);
            }

            self::$connection = self::createConnection($dsn, $user, $pass);
            return self::$connection;
        }

        $dbHost = trim((string) (getenv('DB_HOST') ?: ''));
        $dbName = trim((string) (getenv('DB_NAME') ?: ''));
        $dbUser = trim((string) (getenv('DB_USER') ?: ''));
        $dbPass = (string) (getenv('DB_PASSWORD') ?: '');
        if ($dbHost !== '' && $dbName !== '' && $dbUser !== '') {
            $driver = strtolower(trim((string) (getenv('DB_DRIVER') ?: 'mysql')));
            if (!in_array($driver, ['mysql', 'pgsql'], true)) {
                throw new \RuntimeException('DB_DRIVER must be mysql or pgsql.');
            }

            if ($driver === 'mysql') {
                $dbPort = (int) (getenv('DB_PORT') ?: 3306);
                $charset = trim((string) (getenv('DB_CHARSET') ?: 'utf8mb4'));
                $dsn = sprintf('mysql:host=%s;port=%d;dbname=%s;charset=%s', $dbHost, $dbPort, $dbName, $charset);
            } else {
                $dbPort = (int) (getenv('DB_PORT') ?: 5432);
                $sslMode = trim((string) (getenv('PGSSLMODE') ?: 'require'));
                $dsn = sprintf('pgsql:host=%s;port=%d;dbname=%s;sslmode=%s', $dbHost, $dbPort, $dbName, $sslMode);
            }

            self::$connection = self::createConnection($dsn, $dbUser, $dbPass);
            return self::$connection;
        }

        $sqlitePath = getenv('SQLITE_PATH') ?: __DIR__ . '/../storage/cinema.sqlite';
        $dir = dirname($sqlitePath);
        if (!is_dir($dir)) {
            mkdir($dir, 0777, true);
        }

        self::$connection = self::createConnection('sqlite:' . $sqlitePath, null, null);
        return self::$connection;
    }

    private static function createConnection(string $dsn, ?string $user, ?string $pass): PDO
    {
        return new PDO($dsn, $user, $pass, [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]);
    }
}
