<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Studiogau\Chandra\Database\PdoConnection;

require_once __DIR__ . '/../vendor/autoload.php';

final class PdoConnectionTest extends TestCase
{
    /**
     * fromIni で必須キーが欠けていると RuntimeException を投げることを確認する。
     */
    public function testFromIniThrowsWhenRequiredKeyMissing(): void
    {
        $iniPath = tempnam(sys_get_temp_dir(), 'pdo-missing-ini');
        file_put_contents($iniPath, "dbhost=localhost\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dbname');

        try {
            PdoConnection::fromIni($iniPath);
        } finally {
            @unlink($iniPath);
        }
    }

    /**
     * fromEnv で必須環境変数が欠けていると RuntimeException を投げることを確認する。
     */
    public function testFromEnvThrowsWhenRequiredEnvMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing required environment variable: DB_HOST');

        $this->withTemporaryEnv([
            'DB_HOST' => null,
            'DB_NAME' => null,
            'DB_USER' => null,
            'DB_PASS' => null,
        ], static function (): void {
            PdoConnection::fromEnv();
        });
    }

    /**
     * 切替スイッチが env のときは INI ではなく環境変数経由で解決することを確認する。
     */
    public function testFromConfiguredSourceUsesEnvWhenSwitched(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing required environment variable: DB_HOST');

        $this->withTemporaryEnv([
            PdoConnection::CONFIG_SOURCE_ENV => 'env',
            'DB_HOST' => null,
            'DB_NAME' => null,
            'DB_USER' => null,
            'DB_PASS' => null,
        ], static function (): void {
            PdoConnection::fromConfiguredSource(__FILE__);
        });
    }

    /**
     * 未対応の設定ソースを指定した場合は RuntimeException を投げることを確認する。
     */
    public function testFromConfiguredSourceThrowsWhenSourceUnsupported(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported DB config source: invalid');

        $this->withTemporaryEnv([
            PdoConnection::CONFIG_SOURCE_ENV => 'invalid',
        ], static function (): void {
            PdoConnection::fromConfiguredSource(__FILE__);
        });
    }

    /**
     * @param array<string, string|null> $values
     * @param callable                   $callback
     * @return void
     */
    private function withTemporaryEnv(array $values, callable $callback): void
    {
        $previous = [];
        foreach ($values as $name => $value) {
            $current = getenv($name);
            $previous[$name] = ($current === false) ? null : (string) $current;

            if ($value === null) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }

        try {
            $callback();
        } finally {
            foreach ($previous as $name => $value) {
                if ($value === null) {
                    putenv($name);
                } else {
                    putenv($name . '=' . $value);
                }
            }
        }
    }
}
