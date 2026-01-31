<?php

namespace Studiogau\Chandra\Logging;

use RuntimeException;

/**
 * ロガー。
 */
class Logger
{
    public const LEVEL_DEBUG = 1;
    public const LEVEL_INFO = 2;
    public const LEVEL_WARN = 3;
    public const LEVEL_ERROR = 4;
    public const LEVEL_FATAL = 5;

    /** @var string */
    private $logDirectory;

    /** @var string */
    private $fileName;

    /** @var int */
    private $threshold;

    /** @var array<int, string> */
    private $levelLabels;

    /**
     * @param string             $logDirectory ログ出力先ディレクトリ
     * @param string             $fileName     出力するログファイル名
     * @param int                $threshold    書き込む最小レベル（0でログ無効）
     * @param array<int, string> $levelLabels  レベル名を上書きしたい場合のラベル
     */
    public function __construct($logDirectory, $fileName = 'Chandra.log', $threshold = self::LEVEL_INFO, array $levelLabels = array())
    {
        $this->logDirectory = rtrim($logDirectory, DIRECTORY_SEPARATOR);
        $this->fileName = $fileName;
        $this->threshold = (int) $threshold;
        $this->levelLabels = $levelLabels + $this->getDefaultLabels();

        if ($this->threshold < 0) {
            $this->threshold = 0;
        }

        if (!is_dir($this->logDirectory)) {
            if (!@mkdir($this->logDirectory, 0777, true) && !is_dir($this->logDirectory)) {
                throw new RuntimeException('Unable to create log directory: ' . $this->logDirectory);
            }
        }

        if (!is_writable($this->logDirectory)) {
            throw new RuntimeException('Log directory is not writable: ' . $this->logDirectory);
        }
    }

    /**
     * プロジェクト直下の log ディレクトリを使うファクトリメソッド。
     *
     * @param int $threshold 出力する最小レベル
     * @return self
     */
    public static function createDefault(?string $projectRoot = null, string $fileName = 'Chandra.log', $threshold = self::LEVEL_INFO)
    {
        $root = $projectRoot ?? dirname(__DIR__, 2);
        $logDir = $root . DIRECTORY_SEPARATOR . 'log';

        return new self($logDir, $fileName, $threshold);
    }
    /**
     * ログを1行書き込む（年月ごとのファイルに日付・レベルを付与）。
     *
     * @param int    $level   出力するレベル
     * @param string $message 出力メッセージ
     * @return bool  書き込み成功またはスキップ時はtrue、失敗時はfalse
     */
    public function log($level, $message)
    {
        if ($this->threshold === 0) {
            return true;
        }

        if (!isset($this->levelLabels[$level])) {
            return false;
        }

        if ($level < $this->threshold) {
            return true;
        }

        $line = sprintf('%s %s %s%s', date('Y/m/d H:i:s'), $this->levelLabels[$level], $message, PHP_EOL);
        $filePath = $this->buildFilePath();

        $result = @file_put_contents($filePath, $line, FILE_APPEND | LOCK_EX);

        return $result !== false;
    }

    /**
     * DEBUGレベルのログを書き込む。
     *
     * @param string $message ログメッセージ
     * @return bool
     */
    public function debug($message)
    {
        return $this->log(self::LEVEL_DEBUG, $message);
    }

    /**
     * INFOレベルのログを書き込む。
     *
     * @param string $message ログメッセージ
     * @return bool
     */
    public function info($message)
    {
        return $this->log(self::LEVEL_INFO, $message);
    }

    /**
     * WARNレベルのログを書き込む。
     *
     * @param string $message ログメッセージ
     * @return bool
     */
    public function warn($message)
    {
        return $this->log(self::LEVEL_WARN, $message);
    }

    /**
     * ERRORレベルのログを書き込む。
     *
     * @param string $message ログメッセージ
     * @return bool
     */
    public function error($message)
    {
        return $this->log(self::LEVEL_ERROR, $message);
    }

    /**
     * FATALレベルのログを書き込む。
     *
     * @param string $message ログメッセージ
     * @return bool
     */
    public function fatal($message)
    {
        return $this->log(self::LEVEL_FATAL, $message);
    }

    /**
     * 出力ファイルのフルパスを作成する（年月+ファイル名）。
     *
     * @return string
     */
    private function buildFilePath()
    {
        return $this->logDirectory . DIRECTORY_SEPARATOR . date('Ym') . '_' . $this->fileName;
    }

    /**
     * デフォルトのレベルラベルを返す。
     *
     * @return array<int, string>
     */
    private function getDefaultLabels()
    {
        return array(
            self::LEVEL_DEBUG => 'DEBUG',
            self::LEVEL_INFO => 'INFO',
            self::LEVEL_WARN => 'WARN',
            self::LEVEL_ERROR => 'ERROR',
            self::LEVEL_FATAL => 'FATAL',
        );
    }
}
