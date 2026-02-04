<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Studiogau\Chandra\Logging\Logger;

require_once __DIR__ . '/../vendor/autoload.php';

final class LoggerExtendedTest extends TestCase
{
    /**
     * 目的: しきい値以上のログだけが出力されることを確認する。
     * 期待: DEBUGは出力されず、INFOが1行だけ書き込まれる。
     */
    public function testLogWritesOnlyAboveThreshold(): void
    {
        $dir = $this->createTempDir('logger-threshold-');
        try {
            $logger = new Logger($dir, 'test.log', Logger::LEVEL_INFO);

            $this->assertTrue($logger->debug('skip'));
            $this->assertTrue($logger->info('keep'));

            $logPath = $dir . DIRECTORY_SEPARATOR . date('Ym') . '_test.log';
            $this->assertFileExists($logPath);
            $contents = file_get_contents($logPath);
            $this->assertStringNotContainsString('DEBUG', $contents);
            $this->assertStringContainsString('INFO', $contents);
        } finally {
            $this->removeTempDir($dir);
        }
    }

    /**
     * 目的: 未定義レベルのログが拒否されることを確認する。
     * 期待: log() は false を返し、ファイルは作成されない。
     */
    public function testLogReturnsFalseForUnknownLevel(): void
    {
        $dir = $this->createTempDir('logger-unknown-');
        try {
            $logger = new Logger($dir, 'test.log', Logger::LEVEL_INFO);

            $result = $logger->log(999, 'invalid');

            $this->assertFalse($result);
            $logPath = $dir . DIRECTORY_SEPARATOR . date('Ym') . '_test.log';
            $this->assertFileDoesNotExist($logPath);
        } finally {
            $this->removeTempDir($dir);
        }
    }

    private function createTempDir(string $prefix): string
    {
        $dir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . $prefix . uniqid();
        mkdir($dir, 0777, true);
        return $dir;
    }

    private function removeTempDir(string $dir): void
    {
        if (!is_dir($dir)) {
            return;
        }

        foreach ((array) glob($dir . DIRECTORY_SEPARATOR . '*') as $file) {
            if (is_file($file)) {
                @unlink($file);
            }
        }
        @rmdir($dir);
    }
}

