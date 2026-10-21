<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Studiogau\Chandra\Logging\Logger;

require_once __DIR__ . '/../vendor/autoload.php';

final class LoggerTest extends TestCase
{
    private string $workDir;
    private string $errorLogPath;
    private string $previousErrorLog;

    protected function setUp(): void
    {
        parent::setUp();
        $this->workDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'logger-' . uniqid();
        mkdir($this->workDir, 0777, true);
        $this->errorLogPath = $this->workDir . DIRECTORY_SEPARATOR . 'php-error.log';
        file_put_contents($this->errorLogPath, '');
        $this->previousErrorLog = (string) ini_get('error_log');
        ini_set('error_log', $this->errorLogPath);
    }

    protected function tearDown(): void
    {
        ini_set('error_log', $this->previousErrorLog);
        $this->removeDirectory($this->workDir);
        parent::tearDown();
    }

    /**
     * ディレクトリを作れなくてもLoggerを生成し、準備失敗の理由と保存先を標準ログへ記録することを確認する。
     */
    public function testConstructorReportsDirectoryCreationFailureWithoutThrowing(): void
    {
        // 親パスをファイルにして、OSの権限設定に依存せずディレクトリ作成を失敗させる。
        $blocker = $this->workDir . DIRECTORY_SEPARATOR . 'blocker';
        file_put_contents($blocker, 'block');
        $logDirectory = $blocker . DIRECTORY_SEPARATOR . 'logs';

        $logger = new Logger($logDirectory, 'test.log');

        $this->assertInstanceOf(Logger::class, $logger);
        $diagnostic = file_get_contents($this->errorLogPath);
        $this->assertStringContainsString($logDirectory, $diagnostic);
        $this->assertMatchesRegularExpression('/(creat|director|mkdir)/i', $diagnostic);

        // 初期化に失敗していても、書き込み時には元のメッセージを標準ログへ退避する。
        file_put_contents($this->errorLogPath, '');
        $this->assertFalse($logger->error('constructor-failure-message'));
        $this->assertFallbackContains($logDirectory, 'constructor-failure-message');
    }

    /**
     * 初期化時に保存先を準備できなくても、保存先が復旧すれば同じLoggerで書き込めることを確認する。
     */
    public function testLoggerRecoversAfterConstructorPreparationFailure(): void
    {
        $logDirectory = $this->workDir . DIRECTORY_SEPARATOR . 'logs';
        file_put_contents($logDirectory, 'block');
        $logger = new Logger($logDirectory, 'test.log');

        unlink($logDirectory);
        mkdir($logDirectory);
        file_put_contents($this->errorLogPath, '');

        $this->assertTrue($logger->info('recovered-after-construction'));
        $this->assertStringContainsString('recovered-after-construction', file_get_contents($this->logPath($logDirectory)));
        $this->assertSame('', file_get_contents($this->errorLogPath));
    }

    /**
     * 書き込み失敗時はfalseを返して保存先と元のログを退避し、復旧後は通常の書き込みに戻ることを確認する。
     */
    public function testWriteFailureFallsBackAndCanRecover(): void
    {
        $logger = new Logger($this->workDir, 'test.log');
        $logPath = $this->logPath($this->workDir);
        // ログファイルと同名のディレクトリを置き、書き込みだけを失敗させる。
        mkdir($logPath);

        $this->assertFalse($logger->error('write-failure-message'));
        $this->assertFallbackContains($logPath, 'write-failure-message');

        rmdir($logPath);
        file_put_contents($this->errorLogPath, '');
        $this->assertTrue($logger->info('recovered-after-write'));
        $this->assertStringContainsString('recovered-after-write', file_get_contents($logPath));
        $this->assertSame('', file_get_contents($this->errorLogPath));
    }

    /**
     * 書き込み成功時はtrueを返し、PHP標準ログへ重複して記録しないことを確認する。
     */
    public function testSuccessfulWriteDoesNotUseFallback(): void
    {
        $logger = new Logger($this->workDir, 'test.log');

        $this->assertTrue($logger->info('normal-message'));
        $this->assertStringContainsString('INFO normal-message', file_get_contents($this->logPath($this->workDir)));
        $this->assertSame('', file_get_contents($this->errorLogPath));
    }

    /**
     * ログ無効・しきい値未満・未定義レベルでは、書き込み失敗時の退避処理を実行しないことを確認する。
     */
    public function testSkippedAndInvalidLevelsDoNotUseFallback(): void
    {
        $disabled = new Logger($this->workDir, 'test.log', 0);
        $logger = new Logger($this->workDir, 'test.log', Logger::LEVEL_INFO);
        mkdir($this->logPath($this->workDir));

        $this->assertTrue($disabled->error('disabled'));
        $this->assertTrue($logger->debug('below-threshold'));
        $this->assertFalse($logger->log(999, 'unknown-level'));
        $this->assertSame('', file_get_contents($this->errorLogPath));
    }

    private function assertFallbackContains(string $path, string $message): void
    {
        $contents = file_get_contents($this->errorLogPath);
        $this->assertStringContainsString($path, $contents);
        $this->assertMatchesRegularExpression('/(fail|unable|cannot)/i', $contents);
        $this->assertMatchesRegularExpression('/\d{4}\/\d{2}\/\d{2} \d{2}:\d{2}:\d{2} ERROR ' . preg_quote($message, '/') . '/', $contents);
    }

    private function logPath(string $directory): string
    {
        return $directory . DIRECTORY_SEPARATOR . date('Ym') . '_test.log';
    }

    private function removeDirectory(string $directory): void
    {
        foreach (new FilesystemIterator($directory) as $entry) {
            if ($entry->isDir()) {
                $this->removeDirectory($entry->getPathname());
            } else {
                unlink($entry->getPathname());
            }
        }
        rmdir($directory);
    }
}
