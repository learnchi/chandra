<?php
declare(strict_types=1);

use Studiogau\Chandra\Database\PdoConnection;

final class DbTestHelper
{
    public const CONFIG_PATH = __DIR__ . '/dbconfig.local.ini';

    public static function loadConfig(): array
    {
        if (!is_file(self::CONFIG_PATH)) {
            throw new RuntimeException('DB config file not found: ' . self::CONFIG_PATH);
        }

        $config = parse_ini_file(self::CONFIG_PATH, false, INI_SCANNER_RAW);
        if ($config === false) {
            throw new RuntimeException('Unable to load DB config file: ' . self::CONFIG_PATH);
        }

        foreach (['dbhost', 'dbuser', 'dbpass', 'dbname'] as $key) {
            if (!array_key_exists($key, $config) || $config[$key] === '') {
                throw new RuntimeException('Missing required key in DB config file: ' . $key);
            }
        }

        return $config;
    }

    public static function createDatabase(): void
    {
        $config = self::loadConfig();
        $pdo = self::connectToServer($config);
        $dbName = self::quoteIdentifier((string) $config['dbname']);
        $pdo->exec('CREATE DATABASE IF NOT EXISTS ' . $dbName);
    }

    public static function dropDatabase(): void
    {
        $config = self::loadConfig();
        $pdo = self::connectToServer($config);
        $dbName = self::quoteIdentifier((string) $config['dbname']);
        $pdo->exec('DROP DATABASE IF EXISTS ' . $dbName);
    }

    public static function createConnection(): PdoConnection
    {
        return PdoConnection::fromIni(self::CONFIG_PATH);
    }

    private static function connectToServer(array $config): PDO
    {
        $dsn = sprintf('mysql:host=%s', $config['dbhost']);
        return new PDO(
            $dsn,
            $config['dbuser'],
            $config['dbpass'],
            [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
        );
    }

    private static function quoteIdentifier(string $name): string
    {
        return '`' . str_replace('`', '``', $name) . '`';
    }
}

