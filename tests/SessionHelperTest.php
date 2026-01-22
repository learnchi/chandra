<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Studiogau\Chandra\Auth\LoginUser;
use Studiogau\Chandra\Support\SessionHelper;

require_once __DIR__ . '/../vendor/autoload.php';

final class SessionHelperTest extends TestCase
{
    private array $backupSession = [];
    private array $backupServer = [];
    private array $backupCookies = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $this->backupSession = $_SESSION ?? [];
        $this->backupServer = $_SERVER;
        $this->backupCookies = $_COOKIE ?? [];

        $_SESSION = [];
        $_COOKIE = [];
        $_SERVER['SCRIPT_NAME'] = '/chandra/index.php';
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->backupSession;
        $_SERVER = $this->backupServer;
        $_COOKIE = $this->backupCookies;
        parent::tearDown();
    }

    /**
     * flushError/flushSuccess が一度だけ取得でき、取得後に消えることを確認する。
     */
    public function testFlashMessages(): void
    {
        SessionHelper::flushError('error message');
        $this->assertTrue(SessionHelper::hasFlushError());
        $this->assertSame('error message', SessionHelper::getFlushError());
        $this->assertFalse(SessionHelper::hasFlushError());

        SessionHelper::flushSuccess('success message');
        $this->assertTrue(SessionHelper::hasFlushSuccess());
        $this->assertSame('success message', SessionHelper::getFlushSuccess());
        $this->assertFalse(SessionHelper::hasFlushSuccess());
    }
}
