<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Studiogau\Chandra\Support\CookieHelper;
use Symfony\Component\HttpFoundation\Request;

require_once __DIR__ . '/../vendor/autoload.php';

final class CookieHelperTest extends TestCase
{
    /**
     * forget が期限切れの Cookie を返すことを確認する。
     */
    public function testForgetReturnsExpiredCookie(): void
    {
        $cookie = CookieHelper::forget('token');

        $this->assertLessThan(time(), $cookie->getExpiresTime());
        $this->assertSame('', $cookie->getValue());
    }
}
