<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Studiogau\Chandra\Database\Database;
use Studiogau\Chandra\Database\PdoConnection;
use Studiogau\Chandra\Logging\Logger;

require_once __DIR__ . '/../vendor/autoload.php';
require_once __DIR__ . '/DbTestHelper.php';

final class DatabaseMySqlIntegrationTest extends TestCase
{
    private static PdoConnection $connection;
    private static Database $database;

    public static function setUpBeforeClass(): void
    {
        DbTestHelper::createDatabase();
        self::$connection = DbTestHelper::createConnection();
        self::$database = new Database(self::$connection, new DatabaseMySqlNullLogger());
    }

    public static function tearDownAfterClass(): void
    {
        if (isset(self::$connection)) {
            self::$connection->close();
        }
        DbTestHelper::dropDatabase();
    }

    protected function setUp(): void
    {
        parent::setUp();
        $pdo = self::$connection->getPdo();
        $pdo->exec('DROP TABLE IF EXISTS test_items');
        $pdo->exec(
            'CREATE TABLE test_items (
                id INT AUTO_INCREMENT PRIMARY KEY,
                name VARCHAR(100) NOT NULL,
                quantity INT NOT NULL,
                created_at DATETIME NOT NULL,
                created_by VARCHAR(50) NOT NULL,
                updated_at DATETIME NOT NULL,
                updated_by VARCHAR(50) NOT NULL
            ) ENGINE=InnoDB'
        );
    }

    /**
     * 目的: トランザクションのコミットでデータが永続化されることを確認する。
     * 期待: commit 後に挿入行数が 1 件になる。
     */
    public function testTransactionCommitPersistsRow(): void
    {
        self::$connection->begin();

        $affected = self::$database->insert('test_items', [
            'name' => ['value' => 'Alpha', 'datatype' => PDO::PARAM_STR],
            'quantity' => ['value' => 1, 'datatype' => PDO::PARAM_INT],
        ]);

        $this->assertSame(1, $affected);
        self::$connection->commit();

        $count = self::$database->fetchCount(
            'SELECT COUNT(*) FROM test_items WHERE name = :name',
            [':name' => ['value' => 'Alpha', 'datatype' => PDO::PARAM_STR]]
        );
        $this->assertSame(1, $count);
    }

    /**
     * 目的: トランザクションのロールバックでデータが取り消されることを確認する。
     * 期待: rollback 後に挿入行数が 0 件になる。
     */
    public function testTransactionRollbackDiscardsRow(): void
    {
        self::$connection->begin();

        $affected = self::$database->insert('test_items', [
            'name' => ['value' => 'Beta', 'datatype' => PDO::PARAM_STR],
            'quantity' => ['value' => 2, 'datatype' => PDO::PARAM_INT],
        ]);

        $this->assertSame(1, $affected);
        self::$connection->rollback();

        $count = self::$database->fetchCount(
            'SELECT COUNT(*) FROM test_items WHERE name = :name',
            [':name' => ['value' => 'Beta', 'datatype' => PDO::PARAM_STR]]
        );
        $this->assertSame(0, $count);
    }

    /**
     * 目的: SQL エラーが発生した場合に例外が送出されることを確認する。
     * 期待: 存在しないテーブルの参照で PDOException が発生する。
     */
    public function testSqlErrorThrowsException(): void
    {
        $this->expectException(PDOException::class);
        self::$database->fetchList('SELECT * FROM no_such_table');
    }
}

final class DatabaseMySqlNullLogger extends Logger
{
    public function __construct()
    {
    }

    public function log($level, $message)
    {
        return true;
    }
}

