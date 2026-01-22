<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Studiogau\Chandra\Database\Database;
use Studiogau\Chandra\Database\PdoConnection;
use Studiogau\Chandra\Database\RecordNotFoundException;
use Studiogau\Chandra\Database\MultipleRecordsFoundException;
use Studiogau\Chandra\Logging\Logger;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * ファイルに書き込まないテスト用ロガー。
 */
final class DatabaseNullLogger extends Logger
{
    public function __construct()
    {
        // 親コンストラクタを呼ばずファイルアクセスを避ける
    }

    public function log($level, $message)
    {
        return true;
    }
}

final class DatabaseTest extends TestCase
{
    private Database $database;
    private PdoConnection $connection;

    protected function setUp(): void
    {
        parent::setUp();

        $this->connection = new PdoConnection('sqlite::memory:', '', '', [], null);
        $this->database = new Database($this->connection, new DatabaseNullLogger());

        $pdo = $this->connection->getPdo();
        $pdo->exec(
            'CREATE TABLE test_items (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                name TEXT NOT NULL,
                quantity INTEGER NOT NULL,
                remarks TEXT NULL,
                CREATE_DATE TEXT NULL,
                CREATE_BY TEXT NULL,
                UPDATE_DATE TEXT NULL,
                UPDATE_BY TEXT NULL
            )'
        );
    }

    private function insertSeed(string $name, int $quantity, ?string $remarks = null): int
    {
        $stmt = $this->connection->getPdo()->prepare(
            'INSERT INTO test_items (name, quantity, remarks, CREATE_DATE, CREATE_BY, UPDATE_DATE, UPDATE_BY)
             VALUES (:name, :quantity, :remarks, :cdate, :cby, :udate, :uby)'
        );
        $now = '2024-01-01 00:00:00';
        $stmt->execute([
            ':name' => $name,
            ':quantity' => $quantity,
            ':remarks' => $remarks,
            ':cdate' => $now,
            ':cby' => 'seed',
            ':udate' => $now,
            ':uby' => 'seed',
        ]);

        return (int) $this->connection->getPdo()->lastInsertId();
    }

    /**
     * fetchCount が件数を返すことを確認する。
     */
    public function testFetchCountReturnsRowCount(): void
    {
        $this->insertSeed('Alpha', 1);
        $this->insertSeed('Beta', 2);

        $count = $this->database->fetchCount('SELECT COUNT(*) FROM test_items');

        $this->assertSame(2, $count);
    }

    /**
     * fetchOne が単一行を返すことを確認する。
     */
    public function testFetchOneReturnsMatchedRow(): void
    {
        $id = $this->insertSeed('Gamma', 3);

        $row = $this->database->fetchOne(
            'SELECT id, name, quantity FROM test_items WHERE id = :id',
            [':id' => ['value' => $id, 'datatype' => \PDO::PARAM_INT]]
        );

        $this->assertSame($id, (int) $row['id']);
        $this->assertSame('Gamma', $row['name']);
        $this->assertSame(3, (int) $row['quantity']);
    }

    /**
     * fetchOne が 0 件の場合に RecordNotFoundException を投げることを確認する。
     */
    public function testFetchOneThrowsWhenNoRows(): void
    {
        $this->expectException(RecordNotFoundException::class);
        $this->database->fetchOne('SELECT * FROM test_items WHERE id = -1');
    }

    /**
     * fetchOne が複数件の場合に MultipleRecordsFoundException を投げることを確認する。
     */
    public function testFetchOneThrowsWhenMultipleRows(): void
    {
        $this->insertSeed('X', 1);
        $this->insertSeed('Y', 2);

        $this->expectException(MultipleRecordsFoundException::class);
        $this->database->fetchOne('SELECT * FROM test_items');
    }

    /**
     * insert が監査列を含め 1 行挿入することを確認する。
     */
    public function testInsertInsertsWithAuditColumns(): void
    {
        $affected = $this->database->insert('test_items', [
            'name' => ['value' => 'Delta', 'datatype' => \PDO::PARAM_STR],
            'quantity' => ['value' => 5, 'datatype' => \PDO::PARAM_INT],
        ]);

        $this->assertSame(1, $affected);

        $row = $this->connection->getPdo()
            ->query('SELECT name, quantity, CREATE_BY, UPDATE_BY FROM test_items WHERE name = "Delta"')
            ->fetch(\PDO::FETCH_ASSOC);

        $this->assertSame('Delta', $row['name']);
        $this->assertSame(5, (int) $row['quantity']);
        $this->assertSame('SYSTEM', $row['CREATE_BY']);
        $this->assertSame('SYSTEM', $row['UPDATE_BY']);
    }

    /**
     * update が単一行を更新し監査列も更新することを確認する。
     */
    public function testUpdateUpdatesSingleRow(): void
    {
        $id = $this->insertSeed('Echo', 2);
        $this->database->setCurrentUserId('tester');

        $affected = $this->database->update(
            'test_items',
            ['quantity' => ['value' => 7, 'datatype' => \PDO::PARAM_INT]],
            ['id' => ['value' => $id, 'datatype' => \PDO::PARAM_INT]]
        );

        $this->assertSame(1, $affected);

        $row = $this->connection->getPdo()
            ->query('SELECT quantity, UPDATE_BY FROM test_items WHERE id = ' . $id)
            ->fetch(\PDO::FETCH_ASSOC);

        $this->assertSame(7, (int) $row['quantity']);
        $this->assertSame('tester', $row['UPDATE_BY']);
    }

    /**
     * delete が 1 行削除されることを確認する。
     */
    public function testDeleteDeletesSingleRow(): void
    {
        $keepId = $this->insertSeed('Keep', 1);
        $deleteId = $this->insertSeed('Drop', 1);

        $affected = $this->database->delete(
            'test_items',
            ['id' => ['value' => $deleteId, 'datatype' => \PDO::PARAM_INT]]
        );

        $this->assertSame(1, $affected);

        $count = $this->database->fetchCount('SELECT COUNT(*) FROM test_items WHERE id = :id', [
            ':id' => ['value' => $keepId, 'datatype' => \PDO::PARAM_INT],
        ]);
        $this->assertSame(1, $count);
    }

    /**
     * WHERE に null を渡すと IS NULL で評価されることを確認する。
     */
    public function testUpdateHandlesNullConditionAsIsNull(): void
    {
        $this->insertSeed('NullRow', 4, null);

        $affected = $this->database->update(
            'test_items',
            ['quantity' => ['value' => 9, 'datatype' => \PDO::PARAM_INT]],
            ['remarks' => ['value' => null]]
        );

        $this->assertSame(1, $affected);

        $row = $this->connection->getPdo()
            ->query('SELECT quantity FROM test_items WHERE remarks IS NULL')
            ->fetch(\PDO::FETCH_ASSOC);

        $this->assertSame(9, (int) $row['quantity']);
    }
}
