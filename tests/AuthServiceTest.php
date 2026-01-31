<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Studiogau\Chandra\Auth\AuthException;
use Studiogau\Chandra\Auth\AuthService;
use Studiogau\Chandra\Auth\LoginUser;
use Studiogau\Chandra\Auth\UserRepositoryInterface;
use Studiogau\Chandra\Database\Database;
use Studiogau\Chandra\Database\RecordNotFoundException;
use Studiogau\Chandra\Logging\Logger;
use Studiogau\Chandra\Support\SessionHelper;

require_once __DIR__ . '/../vendor/autoload.php';

/**
 * ファイル出力を行わないテスト用ロガー。
 */
final class AuthServiceNullLogger extends Logger
{
    public function __construct()
    {
        // 親のコンストラクタを呼ばずファイル出力を抑止
    }

    public function log($level, $message)
    {
        return true;
    }
}

final class AuthServiceTest extends TestCase
{
    private array $backupSession = [];
    private array $backupServer = [];

    protected function setUp(): void
    {
        parent::setUp();

        // RecordNotFoundException 定義を読み込むために Database をロード
        class_exists(Database::class);

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
     * ログイン成功時に LoginUser がセッションへ保存されることを確認する。
     */
    public function testLoginStoresUserIntoSession(): void
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

        $service = new AuthService($repo, new AuthServiceNullLogger());

        $result = $service->login('user01', 'secret');

        $this->assertTrue($result);
        $currentUser = $service->getCurrentUser();
        $this->assertInstanceOf(LoginUser::class, $currentUser);
        $this->assertSame('user01', $currentUser->getUserId());
        $this->assertTrue($currentUser->can('write'));
    }

    public function testLoginAcceptsPermissionsArray(): void
    {
        $repo = new class implements UserRepositoryInterface {
            public function findByCredentials(string $userId, string $password): ?array
            {
                return [
                    'user_id' => $userId,
                    'user_name' => 'ArrayTester',
                    'permissions' => ['read', ' write ', ''],
                ];
            }
        };

        $service = new AuthService($repo, new AuthServiceNullLogger());

        $result = $service->login('user02', 'secret');

        $this->assertTrue($result);
        $currentUser = $service->getCurrentUser();
        $this->assertInstanceOf(LoginUser::class, $currentUser);
        $this->assertSame('user02', $currentUser->getUserId());
        $this->assertTrue($currentUser->can('read'));
        $this->assertTrue($currentUser->can('write'));
        $this->assertFalse($currentUser->can(''));
    }

    /**
     * 認証リポジトリが null を返した場合に AuthException が投げられることを確認する。
     */
    public function testLoginThrowsAuthExceptionWhenRepositoryReturnsNull(): void
    {
        $repo = new class implements UserRepositoryInterface {
            public function findByCredentials(string $userId, string $password): ?array
            {
                return null;
            }
        };

        $service = new AuthService($repo, new AuthServiceNullLogger());

        $this->expectException(AuthException::class);
        $service->login('missing', 'password');
    }

    /**
     * リポジトリが RecordNotFoundException を投げた場合に AuthException にラップされることを確認する。
     */
    public function testLoginWrapsRecordNotFoundException(): void
    {
        $repo = new class implements UserRepositoryInterface {
            public function findByCredentials(string $userId, string $password): ?array
            {
                throw new RecordNotFoundException('not found');
            }
        };

        $service = new AuthService($repo, new AuthServiceNullLogger());

        $this->expectException(AuthException::class);
        $service->login('user', 'pw');
    }

    /**
     * セッションにユーザーが無い場合 checkUserSession が false を返すことを確認する。
     */
    public function testCheckUserSessionReturnsFalseWhenNoUser(): void
    {
        $repo = $this->createStub(UserRepositoryInterface::class);
        $service = new AuthService($repo, new AuthServiceNullLogger());

        $this->assertFalse($service->checkUserSession());
    }

    /**
     * セッションに LoginUser がある場合 checkUserSession が true を返し復元できることを確認する。
     */
    public function testCheckUserSessionReturnsTrueWhenUserStored(): void
    {
        $repo = $this->createStub(UserRepositoryInterface::class);
        $service = new AuthService($repo, new AuthServiceNullLogger());

        $loginUser = new LoginUser('valid-user', 'Tester', ['read'], 1234567890);
        SessionHelper::setUser($loginUser);

        $this->assertTrue($service->checkUserSession());

        $stored = $service->getCurrentUser();
        $this->assertInstanceOf(LoginUser::class, $stored);
        $this->assertSame('valid-user', $stored->getUserId());
    }

    /**
     * logout でセッションからユーザーが削除されることを確認する。
     */
    public function testLogoutClearsUserFromSession(): void
    {
        $repo = $this->createStub(UserRepositoryInterface::class);
        $service = new AuthService($repo, new AuthServiceNullLogger());

        SessionHelper::setUser(new LoginUser('logout-user', 'Tester'));
        $service->logout();

        $this->assertNull($service->getCurrentUser());
    }

    /**
     * セッションのシリアライズ文字列が不正な場合 getCurrentUser が null を返すことを確認する。
     */
    public function testGetCurrentUserReturnsNullWhenSessionPayloadInvalid(): void
    {
        $repo = $this->createStub(UserRepositoryInterface::class);
        $service = new AuthService($repo, new AuthServiceNullLogger());

        $prefix = 'chandra' . SessionHelper::USER_SESSION_KEY;
        $_SESSION[$prefix] = 'not-serialized';

        $this->assertNull($service->getCurrentUser());
    }
}
