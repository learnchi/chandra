<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Studiogau\Chandra\Support\CookieHelper;
use Symfony\Component\HttpFoundation\Request;

require_once __DIR__ . '/../vendor/autoload.php';

final class CookieHelperSecurityTest extends TestCase
{
    private array $backupServer = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->backupServer = $_SERVER;
    }

    protected function tearDown(): void
    {
        $_SERVER = $this->backupServer;
        parent::tearDown();
    }

    /**
     * 目的: Cookie 値が暗号化され、取得時に復号されることを確認する。
     * 期待: Cookie の保存値は平文と異なり、get() で平文が戻る。
     */
    public function testMakeEncryptsValueAndGetDecrypts(): void
    {
        $cookie = CookieHelper::make('token', 'secret', 3600);

        $this->assertNotSame('secret', $cookie->getValue());

        $request = new Request([], [], [], ['token' => $cookie->getValue()]);
        $this->assertSame('secret', CookieHelper::get($request, 'token'));
    }

    /**
     * 目的: Cookie が存在しない場合にデフォルト値が返ることを確認する。
     * 期待: get() は指定したデフォルト値を返す。
     */
    public function testGetReturnsDefaultWhenMissing(): void
    {
        $request = new Request();

        $this->assertSame('default', CookieHelper::get($request, 'missing', 'default'));
    }

    /**
     * 目的: HTTPS 環境では secure 属性が有効になることを確認する。
     * 期待: Cookie の secure フラグが true になる。
     */
    public function testMakeSetsSecureWhenHttps(): void
    {
        $_SERVER['HTTPS'] = 'on';

        $cookie = CookieHelper::make('token', 'secret', 3600);
        $this->assertTrue($cookie->isSecure());
    }
}

