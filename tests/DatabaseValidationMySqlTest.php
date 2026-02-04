<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Studiogau\Chandra\Database\Database;
use Studiogau\Chandra\Database\PdoConnection;
use Studiogau\Chandra\Logging\Logger;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/DbTestHelper.php';

final class DatabaseValidationMySqlTest extends TestCase
{
    private static PdoConnection $connection;
    private static Database $database;

    public static function setUpBeforeClass(): void
    {
        DbTestHelper::createDatabase();
        self::$connection = DbTestHelper::createConnection();
        self::$database = new Database(self::$connection, new DatabaseValidationNullLogger());
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$connection)) {
            self::$connection->close();
        }
        DbTestHelper::dropDatabase();
    }

    /**
     * 目的: 空のユーザーIDが拒否されることを確認する。
     * 期待: setCurrentUserId() で InvalidArgumentException が発生する。
     */
    public function testSetCurrentUserIdRejectsEmptyString(): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::$database->setCurrentUserId('');
    }

    /**
     * 目的: 不正なテーブル名が拒否されることを確認する。
     * 期待: insert() で InvalidArgumentException が発生する。
     */
    public function testInsertRejectsInvalidTableName(): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::$database->insert('bad-name', [
            'name' => ['value' => 'x', 'datatype' => PDO::PARAM_STR],
        ]);
    }

    /**
     * 目的: 空の更新値が拒否されることを確認する。
     * 期待: update() で InvalidArgumentException が発生する。
     */
    public function testUpdateRejectsEmptyValues(): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::$database->update('test_items', [], [
            'id' => ['value' => 1, 'datatype' => PDO::PARAM_INT],
        ]);
    }

    /**
     * 目的: 空の条件が拒否されることを確認する。
     * 期待: update() で InvalidArgumentException が発生する。
     */
    public function testUpdateRejectsEmptyConditions(): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::$database->update('test_items', [
            'name' => ['value' => 'x', 'datatype' => PDO::PARAM_STR],
        ], []);
    }

    /**
     * 目的: 未対応の演算子が拒否されることを確認する。
     * 期待: update() で InvalidArgumentException が発生する。
     */
    public function testUpdateRejectsUnsupportedOperator(): void
    {
        $this->expectException(InvalidArgumentException::class);
        self::$database->update('test_items', [
            'name' => ['value' => 'x', 'datatype' => PDO::PARAM_STR],
        ], [
            'id' => ['operator' => 'IN', 'value' => 1, 'datatype' => PDO::PARAM_INT],
        ]);
    }
}

final class DatabaseValidationNullLogger extends Logger
{
    public function __construct()
    {
    }

    public function log($level, $message)
    {
        return true;
    }
}

