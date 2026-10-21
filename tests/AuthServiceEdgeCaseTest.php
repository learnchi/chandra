<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Studiogau\Chandra\Auth\AuthService;
use Studiogau\Chandra\Auth\LoginCredentials;
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
                    'id' => '201',
                    'login_id' => $userId,
                    'user_name' => 'Tester',
                    'permissions' => 'read',
                ];
            }
        };

        $service = new AuthService($repo, new AuthServiceNullLoggerForEdgeCase());
        $this->assertInstanceOf(LoginUser::class, $service->login(new LoginCredentials('user01', 'secret')));

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
                    'id' => '202',
                    'login_id' => $userId,
                    'user_name' => 'NoPerm',
                    'permissions' => null,
                ];
            }
        };

        $service = new AuthService($repo, new AuthServiceNullLoggerForEdgeCase());
        $this->assertInstanceOf(LoginUser::class, $service->login(new LoginCredentials('user02', 'secret')));

        $user = $service->getCurrentUser();
        $this->assertInstanceOf(LoginUser::class, $user);
        $this->assertFalse($user->can('read'));
    }
    /**
     * 0を明示した場合は、古い最終アクセス時刻でも期限切れにせず、時刻も更新しないことを確認する。
     */
    public function testExplicitZeroTimeoutKeepsOldSessionValid(): void
    {
        $service = $this->createServiceWithTimeout(0);
        $service->setCurrentUser(new LoginUser('1', 'user01', 'Tester', ['read'], 1234567890));

        $this->assertTrue($service->checkUserSession());
        $this->assertSame(1234567890, $service->getCurrentUser()?->getStartTime());
    }

    /**
     * タイムアウト無効を明示した場合は、ログインしても最終アクセス時刻を設定しないことを確認する。
     */
    public function testLoginWithExplicitZeroTimeoutDoesNotSetStartTime(): void
    {
        $service = $this->createServiceWithTimeout(0);

        $user = $service->login(new LoginCredentials('user01', 'secret'));

        $this->assertNull($user->getStartTime());
        $this->assertNull($service->getCurrentUser()?->getStartTime());
    }

    /**
     * 正のタイムアウトを指定した場合は、ログイン時の時刻をセッションにも保存することを確認する。
     */
    public function testLoginWithTimeoutSetsStartTime(): void
    {
        $service = $this->createServiceWithTimeout(1800);
        $before = time();

        $user = $service->login(new LoginCredentials('user01', 'secret'));

        $this->assertNotNull($user->getStartTime());
        $this->assertGreaterThanOrEqual($before, $user->getStartTime());
        $this->assertLessThanOrEqual(time(), $user->getStartTime());
        $this->assertSame($user->getStartTime(), $service->getCurrentUser()?->getStartTime());
    }

    /**
     * 期限内のセッションは有効とし、最終アクセス時刻を現在時刻へ更新して保存することを確認する。
     */
    public function testSessionWithinTimeoutRefreshesStartTime(): void
    {
        $service = $this->createServiceWithTimeout(1800);
        $service->setCurrentUser(new LoginUser('1', 'user01', 'Tester', ['read'], time() - 60));
        $before = time();

        $this->assertTrue($service->checkUserSession());

        $user = $service->getCurrentUser();
        $this->assertNotNull($user);
        $this->assertGreaterThanOrEqual($before, $user->getStartTime());
        $this->assertLessThanOrEqual(time(), $user->getStartTime());
        $this->assertSame('user01', $user->getLoginId());
    }

    /**
     * 最終アクセス時刻が未設定でも、有効なユーザーなら時刻を補完してセッションを維持することを確認する。
     */
    public function testSessionWithoutStartTimeInitializesItWhenTimeoutIsEnabled(): void
    {
        $service = $this->createServiceWithTimeout(1800);
        $service->setCurrentUser(new LoginUser('1', 'user01', 'Tester'));
        $before = time();

        $this->assertTrue($service->checkUserSession());

        $startTime = $service->getCurrentUser()?->getStartTime();
        $this->assertNotNull($startTime);
        $this->assertGreaterThanOrEqual($before, $startTime);
        $this->assertLessThanOrEqual(time(), $startTime);
    }

    /**
     * 指定秒数以上経過していればfalseを返し、ユーザーとほかのセッション情報も削除することを確認する。
     */
    public function testExpiredSessionLogsOut(): void
    {
        $service = $this->createServiceWithTimeout(1800);
        $service->setCurrentUser(new LoginUser('1', 'user01', 'Tester', [], time() - 1801));
        $_SESSION['timeout-test-marker'] = 'clear-me';

        $this->assertFalse($service->checkUserSession());
        $this->assertNull($service->getCurrentUser());
        $this->assertSame([], $_SESSION);
    }

    /**
     * 経過時間が指定秒数に達したセッションも、期限切れとして扱うことを確認する。
     */
    public function testSessionAtTimeoutBoundaryLogsOut(): void
    {
        $service = $this->createServiceWithTimeout(1800);
        $service->setCurrentUser(new LoginUser('1', 'user01', 'Tester', [], time() - 1800));

        $this->assertFalse($service->checkUserSession());
        $this->assertNull($service->getCurrentUser());
    }

    /**
     * タイムアウト有効時も、未ログインならfalseを返してユーザーを生成しないことを確認する。
     */
    public function testSessionWithoutUserIsInvalidWithTimeout(): void
    {
        $service = $this->createServiceWithTimeout(1800);

        $this->assertFalse($service->checkUserSession());
        $this->assertNull($service->getCurrentUser());
    }

    /**
     * 負のタイムアウト値は、サービス生成時にInvalidArgumentExceptionで拒否することを確認する。
     */
    public function testConstructorRejectsNegativeTimeout(): void
    {
        $this->expectException(InvalidArgumentException::class);

        $this->createServiceWithTimeout(-1);
    }

    private function createServiceWithTimeout(int $timeout): AuthService
    {
        $repo = new class implements UserRepositoryInterface {
            public function findByCredentials(string $userId, string $password): ?array
            {
                return [
                    'id' => '203',
                    'login_id' => $userId,
                    'user_name' => 'TimeoutTester',
                    'permissions' => ['read'],
                ];
            }
        };

        // 既存の第3引数を維持し、第4引数でタイムアウトを指定する。
        return new AuthService($repo, new AuthServiceNullLoggerForEdgeCase(), [], $timeout);
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

