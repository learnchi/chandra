<?php

namespace Studiogau\Chandra\Database;

use PDO;
use PDOException;
use RuntimeException;
use InvalidArgumentException;
use DateTimeImmutable;
use Studiogau\Chandra\Logging\Logger;

// fetchOneなどで使用
class RecordNotFoundException extends RuntimeException {}
class MultipleRecordsFoundException extends RuntimeException {}

/**
 * 業務ロジックから利用するDBユーティリティ。
 */
class Database
{
    private const DEFAULT_USER_ID = 'SYSTEM';
    private const COL_created_at = 'created_at';
    private const COL_created_by = 'created_by';
    private const COL_updated_at = 'updated_at';
    private const COL_updated_by = 'updated_by';

    /** @var PdoConnection */
    private $connection;
    private $logger;
    private $currentUserId;

    /**
     * @param PdoConnection $connection DB接続ラッパー
     * @param Logger|null   $logger     ログ出力先（未指定時はデフォルトロガー）
     */
    public function __construct(PdoConnection $connection, ?Logger $logger = null)
    {
        $this->connection = $connection;
        $this->logger = $logger ?? Logger::createDefault();
        $this->currentUserId = self::DEFAULT_USER_ID;
    }

    /**
     * INIファイルの設定からインスタンスを生成する。
     *
     * @param string $path 設定ファイルパス
     * @return self
     *
     * @throws PDOException
     * @throws RuntimeException
     */
    public static function fromIni(string $path, ?Logger $logger = null)
    {
        $connection = PdoConnection::fromIni($path);
        return new self($connection, $logger);
    }

    /**
     * 件数を取得するための実行ヘルパー。
     *
     * @param string $sql       実行するSQL
     * @param array  $bindings  bindValue用の指定配列
     * @return int
     */
    public function fetchCount($sql, array $bindings = [])
    {
        $rows = 0;
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->prepare($sql);

        foreach ($bindings as $parameter => $binding) {
            if (!is_array($binding)) {
                continue;
            }

            $key = (is_string($parameter) && $parameter !== '')
                ? $parameter
                : ($binding['key'] ?? null);
            if ($key === null || $key === '') {
                continue;
            }

            $value = $binding['value'] ?? null;
            $datatype = $binding['datatype'] ?? null;

            if ($datatype !== null) {
                $stmt->bindValue($key, $value, $datatype);
            } else {
                $stmt->bindValue($key, $value);
            }
        }

        $stmt->execute();
        $count = $stmt->fetchColumn();
        $rows =  ($count === false) ? 0 : (int)$count;

        return $rows;
    }

    /**
     * 一覧取得用の実行ヘルパー。
     *
     * @param string $sql       実行するSQL
     * @param array  $bindings  bindValue用の指定配列
     * @return array
     */
    public function fetchList($sql, array $bindings = [])
    {
        $rows = [];
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->prepare($sql);

        foreach ($bindings as $parameter => $binding) {
            if (!is_array($binding)) {
                continue;
            }

            $key = (is_string($parameter) && $parameter !== '')
                ? $parameter
                : ($binding['key'] ?? null);
            if ($key === null || $key === '') {
                continue;
            }

            $value = $binding['value'] ?? null;
            $datatype = $binding['datatype'] ?? null;

            if ($datatype !== null) {
                $stmt->bindValue($key, $value, $datatype);
            } else {
                $stmt->bindValue($key, $value);
            }
        }

        $stmt->execute();
        if ($stmt->rowCount() > 0) {
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        return $rows;
    }

    /**
     * 一件取得用の実行ヘルパー。
     *
     * @param string $sql       実行するSQL
     * @param array  $bindings  bindValueで利用する内容を配列で持たせる
     * @return array
     *
     * @throws RecordNotFoundException
     * @throws MultipleRecordsFoundException
     * @throws PDOException
     * @throws RuntimeException
     */
    public function fetchOne($sql, array $bindings = [])
    {
        $pdo = $this->connection->getPdo();
        $stmt = $pdo->prepare($sql);

        foreach ($bindings as $parameter => $binding) {
            if (!is_array($binding)) {
                continue;
            }

            $key = (is_string($parameter) && $parameter !== '')
                ? $parameter
                : ($binding['key'] ?? null);
            if ($key === null || $key === '') {
                continue;
            }

            $value = $binding['value'] ?? null;
            $datatype = $binding['datatype'] ?? null;

            if ($datatype !== null) {
                $stmt->bindValue($key, $value, $datatype);
            } else {
                $stmt->bindValue($key, $value);
            }
        }

        $stmt->execute();

        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        if ($row === false) {
            throw new RecordNotFoundException(' query returned no rows.');
        }

        if ($stmt->fetch(PDO::FETCH_ASSOC) !== false) {
            throw new MultipleRecordsFoundException('query returned more than one row.');
        }

        return $row;
    }

    /**
     * 接続インスタンスを取得する。
     *
     * @return PdoConnection
     */
    public function getConnection()
    {
        return $this->connection;
    }

    /**
     * 接続を明示的に閉じる。
     */
    public function close()
    {
        $this->connection->close();
    }

    /**
     * 監査項目 updated_by に設定するユーザーIDを指定する。
     *
     * @param string $userId ユーザーID
     * @return void
     */
    public function setCurrentUserId(string $userId): void
    {
        if ($userId === '') {
            throw new InvalidArgumentException('setCurrentUserId: userId cannot be empty.');
        }

        $this->currentUserId = $userId;
    }

    /**
     * UPDATE文を実行するヘルパー。
     *
     * 呼び出し元は更新するカラムをvaluesに、WHERE句の条件をconditionsに渡す。
     * 各カラムは ['value' => 値, 'datatype' => PDO::PARAM_*] または値のみを指定する。
     *
     * @param string $table       更新対象テーブル
     * @param array  $values      更新カラムと値
     * @param array  $conditions  WHERE句の条件
     * @return int 更新件数
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     * @throws PDOException
     */
    public function update(string $table, array $values, array $conditions): int
    {
        $this->assertValidTableName($table);

        if (empty($values)) {
            throw new InvalidArgumentException('update: values cannot be empty.');
        }

        if (empty($conditions)) {
            throw new InvalidArgumentException('update: conditions cannot be empty.');
        }

        $values = $this->injectAuditColumns($values);

        [$setClause, $setBindings] = $this->buildSetClause($values);
        [$whereClause, $whereBindings] = $this->buildWhereClause($conditions);

        $sql = sprintf('UPDATE %s SET %s WHERE %s', $table, $setClause, $whereClause);

        $pdo = $this->connection->getPdo();
        $stmt = $pdo->prepare($sql);

        $bindValues = '';
        foreach (array_merge($setBindings, $whereBindings) as $parameter => $binding) {
            if (!is_array($binding)) {
                continue;
            }

            $key = (is_string($parameter) && $parameter !== '')
                ? $parameter
                : ($binding['key'] ?? null);
            if ($key === null || $key === '') {
                continue;
            }

            $value = $binding['value'] ?? null;
            $datatype = $binding['datatype'] ?? null;

            $bindValues .= $key . '=' . $value . ',';
            if ($datatype !== null) {
                $stmt->bindValue($key, $value, $datatype);
            } else {
                $stmt->bindValue($key, $value);
            }
        }

        $logValues = rtrim($bindValues, ',');
        $this->logger->info(__METHOD__ . 'update executed: table=' . $table . ' set=[' . $logValues . ']');
        $stmt->execute();

        $affected = $stmt->rowCount();

        return $affected;
    }

    /**
     * INSERT文を実行するヘルパー。
     *
     * 値は ['value' => 値, 'datatype' => PDO::PARAM_*] 形式、または値のみで指定する。
     *
     * @param string $table  挿入対象テーブル
     * @param array  $values 挿入するカラムと値
     * @return int 挿入された行数
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     * @throws PDOException
     */
    public function insert(string $table, array $values): int
    {
        $this->assertValidTableName($table);

        if (empty($values)) {
            throw new InvalidArgumentException('insert: values cannot be empty.');
        }

        $values = $this->injectInsertAuditColumns($values);

        [$columnList, $placeholders, $bindings] = $this->buildInsertColumns($values);

        $sql = sprintf(
            'INSERT INTO %s (%s) VALUES (%s)',
            $table,
            $columnList,
            $placeholders
        );

        $pdo = $this->connection->getPdo();
        $stmt = $pdo->prepare($sql);

        $bindValues = '';    // ログ用
        foreach ($bindings as $parameter => $binding) {
            if (!is_array($binding)) {
                continue;
            }

            $key = (is_string($parameter) && $parameter !== '')
                ? $parameter
                : ($binding['key'] ?? null);
            if ($key === null || $key === '') {
                continue;
            }

            $value = $binding['value'] ?? null;
            $datatype = $binding['datatype'] ?? null;
            $bindValues .= $value . ',';

            if ($datatype !== null) {
                $stmt->bindValue($key, $value, $datatype);
            } else {
                $stmt->bindValue($key, $value);
            }
        }

        $this->logger->info(__METHOD__ . ' will execute: table=' . $table . ' columns=[' . $columnList . '] values=[' . $bindValues . ']');

        $stmt->execute();
        $affected = $stmt->rowCount();

        return $affected;
    }

    /**
     * DELETE文を実行するヘルパー。
     *
     * 条件は ['value' => 値, 'datatype' => PDO::PARAM_*] 形式で指定する。
     *
     * @param string $table      削除対象テーブル
     * @param array  $conditions WHERE句の条件（空配列で全削除）
     * @return int 削除された行数
     *
     * @throws InvalidArgumentException
     * @throws RuntimeException
     * @throws PDOException
     */
    public function delete(string $table, array $conditions = []): int
    {
        $this->assertValidTableName($table);

        if (empty($conditions)) {
            $sql = sprintf('DELETE FROM %s', $table);
        } else {
            [$whereClause, $whereBindings] = $this->buildWhereClause($conditions);
            $sql = sprintf('DELETE FROM %s WHERE %s', $table, $whereClause);
        }

        $pdo = $this->connection->getPdo();
        $stmt = $pdo->prepare($sql);

        if ($whereBindings ?? null) {
            $bindValues = '';    // ログ用
            foreach ($whereBindings as $parameter => $binding) {
                if (!is_array($binding)) {
                    continue;
                }

                $key = (is_string($parameter) && $parameter !== '')
                    ? $parameter
                    : ($binding['key'] ?? null);
                if ($key === null || $key === '') {
                    continue;
                }

                $value = $binding['value'] ?? null;
                $datatype = $binding['datatype'] ?? null;

                $bindValues .= $key . '=' . $value . ',';
                if ($datatype !== null) {
                    $stmt->bindValue($key, $value, $datatype);
                } else {
                    $stmt->bindValue($key, $value);
                }
            }
            $this->logger->info(__METHOD__ . ' executed: table=' . $table . ' WHERE=[' . $bindValues . ']');
        } else {
            $this->logger->info(__METHOD__ . ' executed: table=' . $table);
        }

        $stmt->execute();

        $affected = $stmt->rowCount();

        return $affected;
    }

    /**
     * UPDATE句のSET部分を組み立てる。
     *
     * @param array $values 更新値
     * @return array{0:string,1:array<string,array{value:mixed,datatype:int|null}>}
     */
    private function buildSetClause(array $values): array
    {
        $clauses = [];
        $bindings = [];
        $index = 0;

        foreach ($values as $column => $spec) {
            if (!is_string($column) || $column === '') {
                throw new InvalidArgumentException('update: value keys must be column names.');
            }

            $this->assertValidColumnName($column);

            [$value, $datatype] = $this->extractValueAndDatatype($spec);
            $placeholder = ':set_' . $index;

            $clauses[] = sprintf('%s = %s', $column, $placeholder);
            $bindings[$placeholder] = [
                'value' => $value,
                'datatype' => $datatype,
            ];

            $index++;
        }

        return [implode(', ', $clauses), $bindings];
    }

    /**
     * WHERE句を組み立てる。
     *
     * @param array $conditions 条件配列
     * @return array{0:string,1:array<string,array{value:mixed,datatype:int|null}>}
     */
    private function buildWhereClause(array $conditions): array
    {
        $clauses = [];
        $bindings = [];
        $index = 0;

        foreach ($conditions as $column => $spec) {
            if (!is_string($column) || $column === '') {
                throw new InvalidArgumentException('update: condition keys must be column names.');
            }

            $this->assertValidColumnName($column);

            $operator = '=';
            $value = $spec;
            $datatype = null;

            if (is_array($spec)) {
                $operator = strtoupper($spec['operator'] ?? '=');
                $value = $spec['value'] ?? null;
                $datatype = $spec['datatype'] ?? null;
            }

            $this->assertSupportedOperator($operator);

            if ($value === null) {
                if ($operator === '=') {
                    $clauses[] = sprintf('%s IS NULL', $column);
                    continue;
                }
                if ($operator === '!=' || $operator === '<>') {
                    $clauses[] = sprintf('%s IS NOT NULL', $column);
                    continue;
                }
            }

            [$value, $datatype] = $this->extractValueAndDatatype(['value' => $value, 'datatype' => $datatype]);
            $placeholder = ':where_' . $index;

            $clauses[] = sprintf('%s %s %s', $column, $operator, $placeholder);
            $bindings[$placeholder] = [
                'value' => $value,
                'datatype' => $datatype,
            ];

            $index++;
        }

        return [implode(' AND ', $clauses), $bindings];
    }

    /**
     * 値とPDOデータ型を取り出す。
     *
     * @param mixed $spec 値の指定（値そのもの、または['value'=>..., 'datatype'=>...]）
     * @return array{0:mixed,1:int|null}
     */
    private function extractValueAndDatatype($spec): array
    {
        $value = $spec;
        $datatype = null;

        if (is_array($spec)) {
            if (!array_key_exists('value', $spec)) {
                throw new InvalidArgumentException('extractValueAndDatatype: value specification must include a value key.');
            }

            $value = $spec['value'];
            $datatype = $spec['datatype'] ?? null;
        }

        if ($datatype !== null && !is_int($datatype)) {
            throw new InvalidArgumentException('extractValueAndDatatype: datatype must be an integer or null.');
        }

        return [$value, $datatype];
    }

    /**
     * INSERT用のカラム一覧とプレースホルダ、バインド情報を生成する。
     *
     * @param array $values 挿入値
     * @return array{0:string,1:string,2:array<string,array{value:mixed,datatype:int|null}>}
     */
    private function buildInsertColumns(array $values): array
    {
        $columns = [];
        $placeholders = [];
        $bindings = [];
        $index = 0;

        foreach ($values as $column => $spec) {
            if (!is_string($column) || $column === '') {
                throw new InvalidArgumentException('insert: value keys must be column names.');
            }

            $this->assertValidColumnName($column);

            [$value, $datatype] = $this->extractValueAndDatatype($spec);

            $placeholder = ':ins_' . $index;
            $columns[] = $column;
            $placeholders[] = $placeholder;
            $bindings[$placeholder] = [
                'value' => $value,
                'datatype' => $datatype,
            ];

            $index++;
        }

        return [
            implode(', ', $columns),
            implode(', ', $placeholders),
            $bindings,
        ];
    }

    /**
     * テーブル名のバリデーションを行う。
     *
     * @param string $table テーブル名
     * @return void
     */
    private function assertValidTableName(string $table): void
    {
        $pattern = '/^[A-Za-z_][A-Za-z0-9_]*(\.[A-Za-z_][A-Za-z0-9_]*)*$/';
        if (!preg_match($pattern, $table)) {
            throw new InvalidArgumentException(sprintf('assertValidTableName: invalid table name "%s".', $table));
        }
    }

    /**
     * カラム名のバリデーションを行う。
     *
     * @param string $column カラム名
     * @return void
     */
    private function assertValidColumnName(string $column): void
    {
        $pattern = '/^[A-Za-z_][A-Za-z0-9_]*$/';
        if (!preg_match($pattern, $column)) {
            throw new InvalidArgumentException(sprintf('assertValidColumnName: invalid column name "%s".', $column));
        }
    }

    /**
     * サポートされている演算子かを検証する。
     *
     * @param string $operator WHERE句で使用する演算子
     * @return void
     */
    private function assertSupportedOperator(string $operator): void
    {
        $allowed = ['=', '!=', '<>', '>', '<', '>=', '<=', 'LIKE'];
        if (!in_array($operator, $allowed, true)) {
            throw new InvalidArgumentException(sprintf('assertSupportedOperator: unsupported operator "%s".', $operator));
        }
    }

    /**
     * UPDATE用の監査項目を付与する。
     *
     * @param array $values 更新する値
     * @return array
     */
    private function injectAuditColumns(array $values): array
    {
        if (array_key_exists(self::COL_created_at, $values) || array_key_exists(self::COL_created_by, $values)) {
            throw new InvalidArgumentException(
                sprintf(
                    'injectAuditColumns: %s/%s cannot be updated.',
                    self::COL_created_at,
                    self::COL_created_by
                )
            );
        }

        $values[self::COL_updated_at] = [
            'value' => $this->getCurrentTimestamp(),
            'datatype' => PDO::PARAM_STR,
        ];
        $values[self::COL_updated_by] = [
            'value' => $this->currentUserId,
            'datatype' => PDO::PARAM_STR,
        ];

        return $values;
    }

    /**
     * INSERT用の監査項目を付与する。
     *
     * @param array $values 挿入する値
     * @return array
     */
    private function injectInsertAuditColumns(array $values): array
    {
        if (array_key_exists(self::COL_created_at, $values) && array_key_exists(self::COL_created_by, $values)) {
            return $this->ensureInsertUpdateColumns($values);
        }

        if (array_key_exists(self::COL_created_at, $values) xor array_key_exists(self::COL_created_by, $values)) {
            throw new InvalidArgumentException(
                sprintf(
                    'insert: %s and %s must be specified together.',
                    self::COL_created_at,
                    self::COL_created_by
                )
            );
        }

        $timestamp = $this->getCurrentTimestamp();
        $user = $this->currentUserId;

        $values[self::COL_created_at] = [
            'value' => $timestamp,
            'datatype' => PDO::PARAM_STR,
        ];
        $values[self::COL_created_by] = [
            'value' => $user,
            'datatype' => PDO::PARAM_STR,
        ];

        $values[self::COL_updated_at] = [
            'value' => $timestamp,
            'datatype' => PDO::PARAM_STR,
        ];
        $values[self::COL_updated_by] = [
            'value' => $user,
            'datatype' => PDO::PARAM_STR,
        ];

        return $this->ensureInsertUpdateColumns($values);
    }

    /**
     * INSERT/UPDATEの監査項目を揃える。
     *
     * @param array $values 挿入・更新する値
     * @return array
     */
    private function ensureInsertUpdateColumns(array $values): array
    {
        $timestamp = $this->getCurrentTimestamp();
        $user = $this->currentUserId;

        if (!array_key_exists(self::COL_updated_at, $values)) {
            $values[self::COL_updated_at] = [
                'value' => $timestamp,
                'datatype' => PDO::PARAM_STR,
            ];
        }

        if (!array_key_exists(self::COL_updated_by, $values)) {
            $values[self::COL_updated_by] = [
                'value' => $user,
                'datatype' => PDO::PARAM_STR,
            ];
        }

        return $values;
    }

    /**
     * 現在時刻の文字列を取得する。
     *
     * @return string
     */
    private function getCurrentTimestamp(): string
    {
        return (new DateTimeImmutable())->format('Y-m-d H:i:s');
    }
}
