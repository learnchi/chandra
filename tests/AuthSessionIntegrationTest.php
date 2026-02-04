<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Studiogau\Chandra\Auth\AuthService;
use Studiogau\Chandra\Auth\LoginUser;
use Studiogau\Chandra\Auth\UserRepositoryInterface;
use Studiogau\Chandra\Logging\Logger;
use Studiogau\Chandra\Support\SessionHelper;

require_once __DIR__ . '/../vendor/autoload.php';

final class AuthSessionIntegrationTest extends TestCase
{
    private array $backupSession = [];
    private array $backupServer = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $this->backupSession = $_SESSION ?? [];
        $this->backupServer = $_SERVER;

        $_SESSION = [];
        $_SERVER['SCRIPT_NAME'] = '/chandra/index.php';
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->backupSession;
        $_SERVER = $this->backupServer;
        parent::tearDown();
    }

    /**
     * 目的: AuthService と SessionHelper の連携でログイン〜チェック〜ログアウトが成立することを確認する。
     * 期待: login/checkUserSession が true、logout 後はユーザーが取得できない。
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

        $this->assertTrue($service->login('user01', 'secret'));
        $this->assertTrue($service->checkUserSession());

        $user = $service->getCurrentUser();
        $this->assertInstanceOf(LoginUser::class, $user);
        $this->assertNotNull(SessionHelper::getUser());

        $service->logout();

        $this->assertNull($service->getCurrentUser());
        $this->assertNull(SessionHelper::getUser());
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

