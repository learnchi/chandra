<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Studiogau\Chandra\Auth\AuthException;
use Studiogau\Chandra\Auth\AuthService;
use Studiogau\Chandra\Auth\LoginAttempt;
use Studiogau\Chandra\Auth\LoginCredentials;
use Studiogau\Chandra\Auth\LoginFailure;
use Studiogau\Chandra\Auth\LoginFailureReason;
use Studiogau\Chandra\Auth\LoginGuardInterface;
use Studiogau\Chandra\Auth\LoginUser;
use Studiogau\Chandra\Auth\UserRepositoryInterface;
use Studiogau\Chandra\Logging\Logger;

require_once __DIR__ . '/../vendor/autoload.php';

final class AuthLoginGuardTest extends TestCase
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
     * 認証成功時に success guard が呼ばれ、
     * LoginAttempt に載せた IP や追加属性がそのまま渡ることを確認する。
     *
     * noblestock 側では、この経路で
     * - 失敗回数のクリア
     * - 最終ログイン記録
     * などを実装する想定。
     */
    public function testLoginRunsSuccessGuardWithAttemptContext(): void
    {
        $repo = new class implements UserRepositoryInterface {
            public function findByCredentials(string $userId, string $password): ?array
            {
                return [
                    'user_id' => $userId,
                    'user_name' => 'Tester',
                    'permissions' => ['read'],
                ];
            }
        };

        $guard = new AuthLoginGuardSpy();
        $service = new AuthService($repo, new AuthLoginGuardNullLogger(), [$guard]);

        $user = $service->login(
            new LoginCredentials('user01', 'secret'),
            new LoginAttempt('user01', '203.0.113.10', 'UA', ['route' => 'menu.php'])
        );

        $this->assertInstanceOf(LoginUser::class, $user);
        $this->assertCount(1, $guard->successes);
        $this->assertSame('user01', $guard->successes[0]['attempt']->getUserId());
        $this->assertSame('203.0.113.10', $guard->successes[0]['attempt']->getClientIp());
        $this->assertSame('menu.php', $guard->successes[0]['attempt']->getAttribute('route'));
        $this->assertSame('user01', $guard->successes[0]['user']->getUserId());
    }

    /**
     * 資格情報不一致のときに failure guard が呼ばれ、
     * 失敗理由が INVALID_CREDENTIALS になることを確認する。
     *
     * これにより guard 実装側は
     * 「通常の認証失敗だけ回数加算する」
     * という分岐を書ける。
     */
    public function testLoginRunsFailureGuardWhenCredentialsAreInvalid(): void
    {
        $repo = new class implements UserRepositoryInterface {
            public function findByCredentials(string $userId, string $password): ?array
            {
                return null;
            }
        };

        $guard = new AuthLoginGuardSpy();
        $service = new AuthService($repo, new AuthLoginGuardNullLogger(), [$guard]);

        $this->expectException(AuthException::class);

        try {
            $service->login(
                new LoginCredentials('missing', 'secret'),
                new LoginAttempt('missing', '203.0.113.20')
            );
        } finally {
            $this->assertCount(1, $guard->failures);
            $this->assertSame(
                LoginFailureReason::INVALID_CREDENTIALS,
                $guard->failures[0]['failure']->getReason()
            );
            $this->assertSame('missing', $guard->failures[0]['attempt']->getUserId());
        }
    }

    /**
     * guard が認証前に試行を拒否した場合、
     * repository による資格情報照合まで進まないことを確認する。
     *
     * アカウントロックや IP ブロックはこの段階で止めたいので、
     * 「DB照合より前に止まる」こと自体が重要な契約になる。
     */
    public function testLoginAllowsGuardToBlockBeforeRepositoryLookup(): void
    {
        $repo = new class implements UserRepositoryInterface {
            public int $callCount = 0;

            public function findByCredentials(string $userId, string $password): ?array
            {
                $this->callCount++;
                return null;
            }
        };

        $guard = new class implements LoginGuardInterface {
            public function assertCanAttempt(LoginAttempt $attempt): void
            {
                throw new AuthException('Account is locked');
            }

            public function recordSuccessfulLogin(LoginAttempt $attempt, LoginUser $user): void
            {
            }

            public function recordFailedLogin(LoginAttempt $attempt, LoginFailure $failure): void
            {
            }
        };

        $service = new AuthService($repo, new AuthLoginGuardNullLogger(), [$guard]);

        $this->expectException(AuthException::class);
        $this->expectExceptionMessage('Account is locked');

        try {
            $service->login(
                new LoginCredentials('locked-user', 'secret'),
                new LoginAttempt('locked-user')
            );
        } finally {
            $this->assertSame(0, $repo->callCount);
        }
    }

    /**
     * LoginCredentials と LoginAttempt の userId が不一致なら拒否することを確認する。
     *
     * ここが許されると、あるアカウントの試行情報で別アカウントを認証する形になり、
     * アカウント単位ロックや試行回数集計の整合性が壊れる。
     */
    public function testLoginRejectsMismatchedAttemptUserId(): void
    {
        $repo = $this->createStub(UserRepositoryInterface::class);
        $service = new AuthService($repo, new AuthLoginGuardNullLogger());

        $this->expectException(InvalidArgumentException::class);
        $service->login(
            new LoginCredentials('user01', 'secret'),
            new LoginAttempt('user02')
        );
    }
}

/**
 * guard 呼び出し結果を後で検証するためのテスト用 Spy。
 *
 * 成功時・失敗時に渡された引数を配列へ記録し、
 * テスト本体から中身を検証できるようにする。
 */
final class AuthLoginGuardSpy implements LoginGuardInterface
{
    /** @var array<int, array{attempt: LoginAttempt, user: LoginUser}> */
    public array $successes = [];

    /** @var array<int, array{attempt: LoginAttempt, failure: LoginFailure}> */
    public array $failures = [];

    public function assertCanAttempt(LoginAttempt $attempt): void
    {
    }

    public function recordSuccessfulLogin(LoginAttempt $attempt, LoginUser $user): void
    {
        $this->successes[] = [
            'attempt' => $attempt,
            'user' => $user,
        ];
    }

    public function recordFailedLogin(LoginAttempt $attempt, LoginFailure $failure): void
    {
        $this->failures[] = [
            'attempt' => $attempt,
            'failure' => $failure,
        ];
    }
}

/**
 * テスト中の不要なログ出力を抑えるためのダミーロガー。
 */
final class AuthLoginGuardNullLogger extends Logger
{
    public function __construct()
    {
    }

    public function log($level, $message)
    {
        return true;
    }
}
