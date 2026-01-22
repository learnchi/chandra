<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Studiogau\Chandra\Logging\Logger;

require_once __DIR__ . '/../vendor/autoload.php';

final class LoggerTest extends TestCase
{
    /**
     * ログディレクトリが書き込み不可の場合に RuntimeException となることを確認する。
     */
    public function testConstructorThrowsWhenLogDirectoryNotWritable(): void
    {
        $tmpFile = tempnam(sys_get_temp_dir(), 'logger-file');

        $this->expectException(RuntimeException::class);

        try {
            new Logger($tmpFile, 'test.log');
        } finally {
            @unlink($tmpFile);
        }
    }
}
