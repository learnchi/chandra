<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Studiogau\Chandra\Database\PdoConnection;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/DbTestHelper.php';

final class PdoConnectionMySqlFailureTest extends TestCase
{
    public static function setUpBeforeClass(): void
    {
        DbTestHelper::createDatabase();
    }

    public static function tearDownAfterClass(): void
    {
        DbTestHelper::dropDatabase();
    }

    /**
     * 目的: 誤った認証情報で接続すると例外になることを確認する。
     * 期待: connect() で PDOException が発生する。
     */
    public function testConnectThrowsWithInvalidPassword(): void
    {
        $config = DbTestHelper::loadConfig();
        $dsn = sprintf('mysql:host=%s;dbname=%s', $config['dbhost'], $config['dbname']);

        $connection = new PdoConnection($dsn, $config['dbuser'], 'invalid-password', [], 'utf8');

        $this->expectException(PDOException::class);
        $connection->connect();
    }
}

