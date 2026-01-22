<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Studiogau\Chandra\Config\ChandraConst;

require_once __DIR__ . '/../vendor/autoload.php';

final class ChandraConstTest extends TestCase
{
    /**
     * LOGIN_TIMEOUT が 0 であることを確認する。
     */
    public function testLoginTimeoutIsZero(): void
    {
        $this->assertSame(0, ChandraConst::LOGIN_TIMEOUT);
    }
}
