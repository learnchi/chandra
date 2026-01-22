<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Studiogau\Chandra\Database\PdoConnection;

require_once __DIR__ . '/../vendor/autoload.php';

final class PdoConnectionTest extends TestCase
{
    /**
     * fromIni で必須キーが欠けていると RuntimeException を投げることを確認する。
     */
    public function testFromIniThrowsWhenRequiredKeyMissing(): void
    {
        $iniPath = tempnam(sys_get_temp_dir(), 'pdo-missing-ini');
        file_put_contents($iniPath, "dbhost=localhost\n");

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('dbname');

        try {
            PdoConnection::fromIni($iniPath);
        } finally {
            @unlink($iniPath);
        }
    }
}
