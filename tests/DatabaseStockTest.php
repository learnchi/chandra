<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Studiogau\Chandra\Database\Database;
use Studiogau\Chandra\Database\PdoConnection;
use Studiogau\Chandra\Logging\Logger;

require_once __DIR__ . '/../vendor/autoload.php';
$stockPath = __DIR__ . '/../src/dblogic/Stock.php';
$checkPath = __DIR__ . '/../src/dblogic/Check.php';
$hasDblogic = file_exists($stockPath) && file_exists($checkPath);

if ($hasDblogic) {
    require_once $stockPath;
    require_once $checkPath;
}

if (!class_exists('UtilSession', false)) {
    /**
     * Lightweight stand-in used by the legacy dblogic classes during tests.
     */
    final class UtilSession
    {
        public static function getAuthData(string $key)
        {
            return null;
        }
    }
}

/**
 * Swallows every log entry so that integration tests do not touch the filesystem.
 */
final class TestNullLogger extends Logger
{
    public function __construct()
    {
        // Skip parent constructor to avoid file handles.
    }

    public function log($level, $message)
    {
        return true;
    }
}

final class DatabaseStockTest extends TestCase
{
    private const DBCONFIG_PATH = __DIR__ . '/../src/util/dbconfig.ini';

    private PdoConnection $connection;
    private Database $database;
    /**
     * @var array<string, array{rows: array<int, array<string, mixed>>, auto_increment: int|null}>
     */
    private array $tableBackups = [];

    protected function setUp(): void
    {
        parent::setUp();

        global $hasDblogic;

        if (!$hasDblogic) {
            $this->markTestSkipped('Stock.php and Check.php are missing under src/dblogic; skipping DatabaseStockTest.');
        }

        if (!is_readable(self::DBCONFIG_PATH) || getenv('RUN_DATABASE_STOCK_TESTS') !== '1') {
            $this->markTestSkipped('Set RUN_DATABASE_STOCK_TESTS=1 and provide a readable src/util/dbconfig.ini to run DatabaseStockTest.');
        }

        $this->connection = PdoConnection::fromIni(self::DBCONFIG_PATH);
        $this->database = new Database($this->connection, new TestNullLogger());
        $this->database->setCurrentUserId('phpunit');

        foreach (self::TABLES_TO_BACKUP as $table) {
            $this->tableBackups[$table] = $this->createBackup($table);
        }
    }

    protected function tearDown(): void
    {
        if (isset($this->connection)) {
            foreach (self::TABLES_TO_BACKUP as $table) {
                if (isset($this->tableBackups[$table])) {
                    $this->restoreTable($table, $this->tableBackups[$table]);
                }
            }
        }

        parent::tearDown();
    }

    public function testStockSelectQuantityReturnsSeededValue(): void
    {
        $stock = $this->createStock();
        $managementNo = $this->generateManagementNo();
        $locationNo = random_int(1000, 9999);
        $expectedQuantity = 7;

        $this->insertStockRow($managementNo, $locationNo, $expectedQuantity);

        $actual = $stock->selectQuantity($managementNo, $locationNo);

        $this->assertSame($expectedQuantity, (int) $actual);
    }

    public function testCheckInsertPersistsCount(): void
    {
        $check = $this->createCheck();

        $payload = [
            'user_id' => (string) random_int(8000, 8999),
            'location_no' => random_int(500, 600),
            'management_no' => $this->generateManagementNo(),
            'check_count' => 3,
            'result_flag' => 1,
        ];

        $affected = $check->insert($payload);
        $this->assertSame(1, $affected, 'Insert should affect exactly one row.');

        $storedCount = $this->fetchCheckCountFromDatabase(
            $payload['user_id'],
            $payload['location_no'],
            $payload['management_no']
        );

        $this->assertSame($payload['check_count'], $storedCount);
    }

    public function testSelect4Check(): void
    {
        // $check = $this->createCheck();
        $stock = $this->createStock();

        // t_check に事前データがある前提で select4Check を確認する
        $user_id = 'phpunit';
        $location_no = 10;
        $management_no = 'ABC001';

        $actual = $stock->select4Check($user_id, $location_no, $management_no);

        // null でなければ 1 レコード分の情報が返る想定
        $expected = [
            'MANAGEMENT_NO' => 'ABC001',
            'CATEGORY_NAME' => 'カテゴリー10',
            'MAKER_NAME' => 'メーカー10',
            'PRODUCT_NAME' => '商品ABC001',
            'QUANTITY' => 60,
            'check_count' => 0,
            'result_flag' => 0,
        ];

        $this->assertSame($expected, $actual);
    }

    private function createStock(): Stock
    {
        return new Stock($this->database, new TestNullLogger());
    }

    private function createCheck(): Check
    {
        return new Check($this->database, new TestNullLogger());
    }

    /**
     * Capture rows and AUTO_INCREMENT so we can revert destructive tests.
     */
    private function createBackup(string $table): array
    {
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->query(sprintf('SELECT * FROM %s', $this->quoteIdentifier($table)));
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $autoStmt = $pdo->prepare(
            'SELECT AUTO_INCREMENT 
             FROM information_schema.TABLES 
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = :table LIMIT 1'
        );
        $autoStmt->execute([':table' => $table]);
        $autoIncrement = $autoStmt->fetchColumn();

        return [
            'rows' => $rows,
            'auto_increment' => $autoIncrement !== false ? (int) $autoIncrement : null,
        ];
    }

    /**
     * Restore a single table inside a transaction to keep fixtures stable.
     *
     * @param array{rows: array<int, array<string, mixed>>, auto_increment: int|null} $backup
     */
    private function restoreTable(string $table, array $backup): void
    {
        $pdo = $this->connection->getPdo();
        $quotedTable = $this->quoteIdentifier($table);

        $pdo->beginTransaction();
        $foreignKeysDisabled = false;

        try {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
            $foreignKeysDisabled = true;

            $pdo->exec(sprintf('DELETE FROM %s', $quotedTable));

            if (!empty($backup['rows'])) {
                $columns = array_keys($backup['rows'][0]);
                $quotedColumns = array_map([$this, 'quoteIdentifier'], $columns);
                $placeholders = array_map(fn(string $column): string => ':' . $column, $columns);

                $insertSql = sprintf(
                    'INSERT INTO %s (%s) VALUES (%s)',
                    $quotedTable,
                    implode(', ', $quotedColumns),
                    implode(', ', $placeholders)
                );
                $stmt = $pdo->prepare($insertSql);
                foreach ($backup['rows'] as $row) {
                    $params = [];
                    foreach ($columns as $column) {
                        $params[':' . $column] = $row[$column];
                    }
                    $stmt->execute($params);
                }
            }

            if (!empty($backup['auto_increment'])) {
                $pdo->exec(sprintf(
                    'ALTER TABLE %s AUTO_INCREMENT = %d',
                    $quotedTable,
                    (int) $backup['auto_increment']
                ));
            }

            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            $foreignKeysDisabled = false;
            $pdo->commit();
        } catch (\Throwable $exception) {
            if ($foreignKeysDisabled) {
                $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
            }
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            throw $exception;
        }
    }

    private function insertStockRow(string $managementNo, int $locationNo, int $quantity): void
    {
        $values = [
            'MANAGEMENT_NO' => ['value' => $managementNo, 'datatype' => PDO::PARAM_STR],
            'LOCATION_NO' => ['value' => $locationNo, 'datatype' => PDO::PARAM_INT],
            'QUANTITY' => ['value' => $quantity, 'datatype' => PDO::PARAM_INT],
            'REMARKS' => ['value' => 'Seeded by PHPUnit', 'datatype' => PDO::PARAM_STR],
        ];

        $this->database->insert('pit_t_stk', $values);
    }

    private function fetchCheckCountFromDatabase(string $userId, int $locationNo, string $managementNo): int
    {
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->prepare(
            'SELECT check_count 
             FROM t_check 
             WHERE user_id = :user_id AND location_no = :location_no AND management_no = :management_no'
        );
        $stmt->execute([
            ':user_id' => $userId,
            ':location_no' => $locationNo,
            ':management_no' => $managementNo,
        ]);

        $value = $stmt->fetchColumn();
        if ($value === false) {
            $this->fail('Seeded check row was not found.');
        }

        return (int) $value;
    }

    private function generateManagementNo(): string
    {
        return 'TEST-MGMT-' . substr(bin2hex(random_bytes(8)), 0, 12);
    }

    private function quoteIdentifier(string $identifier): string
    {
        return '`' . str_replace('`', '``', $identifier) . '`';
    }
}
