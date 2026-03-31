<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Studiogau\Chandra\Auth\AuthService;
use Studiogau\Chandra\Auth\LoginCredentials;
use Studiogau\Chandra\Auth\LoginUser;
use Studiogau\Chandra\Auth\UserRepositoryInterface;
use Studiogau\Chandra\Logging\Logger;
use Studiogau\Chandra\Support\SessionHelper;

require_once __DIR__ . '/../vendor/autoload.php';

final class AuthSessionIntegrationTest extends TestCase
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
     * AuthService と SessionHelper の連携で、
     * ログイン後にセッションが成立し、logout 後に消えることを確認する。
     */
    public function testLoginCheckLogoutFlow(): void
    {
        $repo = new class implements UserRepositoryInterface {
            public function findByCredentials(string $userId, string $password): ?array
            {
                return [
                    'user_id' => $userId,
                    'user_name' => 'Tester',
                    'permissions' => 'read,write',
                ];
            }
        };

        $service = new AuthService($repo, new AuthSessionNullLogger());

        $this->assertInstanceOf(LoginUser::class, $service->login(new LoginCredentials('user01', 'secret')));
        $this->assertTrue($service->checkUserSession());

        $user = $service->getCurrentUser();
        $this->assertInstanceOf(LoginUser::class, $user);
        $this->assertNotNull(SessionHelper::getUser());

        $service->logout();

        $this->assertNull($service->getCurrentUser());
        $this->assertNull(SessionHelper::getUser());
    }

    /**
     * logout でセッション全体が無効化され、セッション Cookie も削除されることを確認する。
     */
    public function testLogoutInvalidatesSessionAndExpiresSessionCookie(): void
    {
        $repo = new class implements UserRepositoryInterface {
            public function findByCredentials(string $userId, string $password): ?array
            {
                return [
                    'user_id' => $userId,
                    'user_name' => 'Tester',
                    'permissions' => 'read,write',
                ];
            }
        };

        $service = new AuthService($repo, new AuthSessionNullLogger());

        $this->assertInstanceOf(LoginUser::class, $service->login(new LoginCredentials('user01', 'secret')));
        SessionHelper::setPref('theme', 'dark');
        $_COOKIE[session_name()] = session_id();

        $service->logout();

        $this->assertSame([], $_SESSION);
        $this->assertArrayNotHasKey(session_name(), $_COOKIE);
        $this->assertNull($service->getCurrentUser());
        $this->assertNull(SessionHelper::getUser());
        $this->assertNull(SessionHelper::getPref('theme'));
    }
}

final class AuthSessionNullLogger extends Logger
{
    public function __construct()
    {
    }

    public function log($level, $message)
    {
        return true;
    }
}
