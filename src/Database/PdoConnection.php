<?php

namespace Studiogau\Chandra\Database;

use PDO;
use PDOException;
use RuntimeException;

/**
 * PDO 接続をインスタンスベースで管理する。
 */
class PdoConnection
{
    public const CONFIG_SOURCE_ENV = 'CHANDRA_DB_SOURCE';

    /** @var array<string, string> */
    public const DEFAULT_ENV_MAP = array(
        'dbhost' => 'DB_HOST',
        'dbport' => 'DB_PORT',
        'dbname' => 'DB_NAME',
        'dbuser' => 'DB_USER',
        'dbpass' => 'DB_PASS',
        'charset' => 'DB_CHARSET',
    );

    /**
     * @var PDO|null
     */
    private $pdo = null;

    /**
     * @var string
     */
    private $dsn;

    /**
     * @var string
     */
    private $username;

    /**
     * @var string
     */
    private $password;

    /**
     * @var array
     */
    private $options;

    /**
     * @var string|null
     */
    private $charset;

    /**
     * @param string      $dsn      接続文字列
     * @param string      $username DBユーザー
     * @param string      $password DBパスワード
     * @param array       $options  PDO オプション
     * @param string|null $charset  SET NAMES で設定する charset
     */
    public function __construct($dsn, $username, $password, array $options = array(), $charset = 'utf8')
    {
        $this->dsn = $dsn;
        $this->username = $username;
        $this->password = $password;
        $this->options = $options;
        $this->charset = $charset;
    }

    /**
     * dbconfig.ini 互換の INI ファイルからインスタンスを生成する。
     *
     * @param string $path INIファイルパス
     * @return self
     */
    public static function fromIni($path)
    {
        $config = parse_ini_file($path);
        if ($config === false) {
            // DB 設定ファイルを読み込めません:
            throw new RuntimeException('Unable to load DB config file: ' . $path);
        }

        foreach (array('dbhost', 'dbname', 'dbuser', 'dbpass') as $key) {
            if (!array_key_exists($key, $config)) {
                // DB 設定ファイルに必要なキーが不足しています
                throw new RuntimeException('Missing required key in DB config file: ' . $key);
            }
        }

        $dsn = self::buildMySqlDsn(
            (string) $config['dbhost'],
            (string) $config['dbname'],
            $config['dbport'] ?? null
        );
        $charset = isset($config['charset']) ? $config['charset'] : 'utf8';

        $options = array();
        if (isset($config['options']) && is_array($config['options'])) {
            $options = $config['options'];
        }

        $connection = new self($dsn, $config['dbuser'], $config['dbpass'], $options, $charset);
        $connection->connect();

        return $connection;
    }

    /**
     * 環境変数からインスタンスを生成する。
     *
     * @param array<string, string> $envMap 設定キー => 環境変数名
     * @return self
     */
    public static function fromEnv(array $envMap = array())
    {
        $map = array_replace(self::DEFAULT_ENV_MAP, $envMap);

        $config = array();
        foreach (array('dbhost', 'dbname', 'dbuser', 'dbpass') as $key) {
            $envName = $map[$key] ?? '';
            $value = self::getEnvValue($envName);
            if ($value === null) {
                throw new RuntimeException('Missing required environment variable: ' . $envName);
            }
            $config[$key] = $value;
        }

        $dsn = self::buildMySqlDsn(
            $config['dbhost'],
            $config['dbname'],
            self::getEnvValue($map['dbport'] ?? '')
        );
        $charset = self::getEnvValue($map['charset'] ?? '') ?? 'utf8';

        $connection = new self($dsn, $config['dbuser'], $config['dbpass'], array(), $charset);
        $connection->connect();

        return $connection;
    }

    /**
     * 設定ソースを切り替えて接続を生成する。
     *
     * @param string               $path    INI ファイルパス
     * @param array<string, mixed> $options switch_env_name/default_source/env_map を指定可能
     * @return self
     */
    public static function fromConfiguredSource($path, array $options = array())
    {
        $switchEnvName = (string) ($options['switch_env_name'] ?? self::CONFIG_SOURCE_ENV);
        $defaultSource = strtolower(trim((string) ($options['default_source'] ?? 'ini')));
        $source = self::getEnvValue($switchEnvName);
        $source = strtolower(trim($source ?? $defaultSource));

        if ($source === '' || $source === 'ini') {
            return self::fromIni($path);
        }

        if ($source === 'env') {
            $envMap = $options['env_map'] ?? array();
            if (!is_array($envMap)) {
                throw new RuntimeException('env_map must be an array.');
            }

            return self::fromEnv($envMap);
        }

        throw new RuntimeException('Unsupported DB config source: ' . $source);
    }

    /**
     * 現在の接続を開始する。
     *
     * @return void
     */
    public function connect()
    {
        if ($this->pdo instanceof PDO) {
            return;
        }

        $defaults = array(
            PDO::ATTR_EMULATE_PREPARES => true,
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        );
        $options = array_replace($defaults, $this->options);

        try {
            $pdo = new PDO($this->dsn, $this->username, $this->password, $options);
            if ($this->charset !== null && $this->charset !== '') {
                $pdo->exec('SET NAMES ' . $this->charset);
            }
            $this->pdo = $pdo;
        } catch (PDOException $exception) {
            throw $exception;
        }
    }

    /**
     * 現在の PDO インスタンスを返す。
     *
     * @return PDO
     */
    public function getPdo()
    {
        if (!($this->pdo instanceof PDO)) {
            $this->connect();
        }

        return $this->pdo;
    }

    /**
     * 接続を明示的に閉じる。
     *
     * @return void
     */
    public function close()
    {
        $this->pdo = null;
    }

    /**
     * トランザクションを開始する。
     *
     * @return bool
     */
    public function begin()
    {
        return $this->getPdo()->beginTransaction();
    }

    /**
     * コミットする。
     *
     * @return bool
     */
    public function commit()
    {
        return $this->getPdo()->commit();
    }

    /**
     * ロールバックする。
     *
     * @return bool
     */
    public function rollback()
    {
        return $this->getPdo()->rollBack();
    }

    /**
     * @param string      $host
     * @param string      $dbName
     * @param string|null $port
     * @return string
     */
    private static function buildMySqlDsn($host, $dbName, $port = null)
    {
        $dsn = sprintf('mysql:host=%s;dbname=%s', $host, $dbName);
        if ($port !== null && $port !== '') {
            $dsn .= ';port=' . $port;
        }

        return $dsn;
    }

    /**
     * @param string $envName
     * @return string|null
     */
    private static function getEnvValue($envName)
    {
        if (!is_string($envName) || $envName === '') {
            return null;
        }

        $value = getenv($envName);
        if ($value === false || $value === '') {
            return null;
        }

        return (string) $value;
    }
}
