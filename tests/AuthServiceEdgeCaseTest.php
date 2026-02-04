<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Studiogau\Chandra\Auth\AuthService;
use Studiogau\Chandra\Auth\LoginUser;
use Studiogau\Chandra\Auth\UserRepositoryInterface;
use Studiogau\Chandra\Logging\Logger;

require_once __DIR__ . '/../vendor/autoload.php';

final class AuthServiceEdgeCaseTest extends TestCase
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
     * 目的: LOGIN_TIMEOUT が 0 の場合に開始時刻が null になることを確認する。
     * 期待: ログイン後の LoginUser の startTime が null のままになる。
     */
    public function testLoginDoesNotSetStartTimeWhenTimeoutIsDisabled(): void
    {
        $repo = new class implements UserRepositoryInterface {
            public function findByCredentials(string $userId, string $password): ?array
            {
                return [
                    'user_id' => $userId,
                    'user_name' => 'Tester',
                    'permissions' => 'read',
                ];
            }
        };

        $service = new AuthService($repo, new AuthServiceNullLoggerForEdgeCase());
        $this->assertTrue($service->login('user01', 'secret'));

        $user = $service->getCurrentUser();
        $this->assertInstanceOf(LoginUser::class, $user);
        $this->assertNull($user->getStartTime());
    }

    /**
     * 目的: permissions が null の場合に空配列として扱われることを確認する。
     * 期待: いかなる権限も付与されず can() が false を返す。
     */
    public function testLoginHandlesNullPermissionsAsEmpty(): void
    {
        $repo = new class implements UserRepositoryInterface {
            public function findByCredentials(string $userId, string $password): ?array
            {
                return [
                    'user_id' => $userId,
                    'user_name' => 'NoPerm',
                    'permissions' => null,
                ];
            }
        };

        $service = new AuthService($repo, new AuthServiceNullLoggerForEdgeCase());
        $this->assertTrue($service->login('user02', 'secret'));

        $user = $service->getCurrentUser();
        $this->assertInstanceOf(LoginUser::class, $user);
        $this->assertFalse($user->can('read'));
    }
}

/**
 * テスト用にログ出力を無効化した Logger。
 */
final class AuthServiceNullLoggerForEdgeCase extends Logger
{
    public function __construct()
    {
    }

    public function log($level, $message)
    {
        return true;
    }
}

