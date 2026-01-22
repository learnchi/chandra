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
     * @param string      $username 接続ユーザー
     * @param string      $password 接続パスワード
     * @param array       $options  PDO オプション
     * @param string|null $charset  SET NAMES で設定するcharset
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
            throw new RuntimeException('DB 設定ファイルを読み込めません: ' . $path);
        }

        foreach (array('dbhost', 'dbname', 'dbuser', 'dbpass') as $key) {
            if (!array_key_exists($key, $config)) {
                throw new RuntimeException('DB 設定ファイルに必要なキーが不足しています: ' . $key);
            }
        }

        $dsn = sprintf('mysql:host=%s;dbname=%s', $config['dbhost'], $config['dbname']);
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
     * 実際の接続を開始する。
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
     * 現在の PDO インスタンスを取得する。
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
}
