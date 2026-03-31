<?php

declare(strict_types=1);

namespace Studiogau\Chandra\Auth;

use RuntimeException;
use Throwable;
use Studiogau\Chandra\Config\ChandraConst;
use Studiogau\Chandra\Database\RecordNotFoundException;
use Studiogau\Chandra\Logging\Logger;
use Studiogau\Chandra\Support\SessionHelper;

class AuthException extends RuntimeException
{
}

/**
 * 認証処理の中核サービス。
 *
 * 公開するログイン入口は login() のみとし、
 * すべてのログイン要求を
 * 「資格情報(LoginCredentials) + 試行コンテキスト(LoginAttempt)」
 * として扱う。
 *
 * Chandra 側は以下だけを担当する。
 * - 認証フロー全体の順序制御
 * - セッション確立
 * - guard 呼び出し契約の提供
 *
 * 一方で、試行回数制限・ロック・保存先・閾値は
 * アプリ側の LoginGuard 実装に委ねる。
 */
final class AuthService
{
    private UserRepositoryInterface $repo;

    /** @var Logger */
    private $logger;

    /** @var list<LoginGuardInterface> */
    private array $loginGuards;

    /**
     * @param UserRepositoryInterface $repo        認証本体を担当する repository
     * @param Logger|null             $logger      ロガー。未指定なら既定ロガー
     * @param iterable<mixed>         $loginGuards ログイン時に差し込む guard 一覧
     */
    public function __construct(
        UserRepositoryInterface $repo,
        ?Logger $logger = null,
        iterable $loginGuards = []
    ) {
        $this->repo = $repo;
        $this->logger = $logger ?? Logger::createDefault();
        $this->loginGuards = $this->normalizeLoginGuards($loginGuards);
    }

    /**
     * 現在のセッションに有効なログインユーザーがいるかを確認する。
     *
     * LOGIN_TIMEOUT が有効な場合は、ここで最終アクセス時刻も更新する。
     */
    public function checkUserSession(): bool
    {
        $loginUser = $this->getCurrentUser();
        if ($loginUser === null) {
            return false;
        }

        if (ChandraConst::LOGIN_TIMEOUT > 0) {
            $startTime = $loginUser->getStartTime();
            if (empty($startTime)) {
                $loginUser->setStartTime(time());
            } else {
                $progressTime = time() - $startTime;
                if ($progressTime >= ChandraConst::LOGIN_TIMEOUT) {
                    $this->logout();
                    return false;
                }
                $loginUser->setStartTime(time());
            }
        }

        $this->setCurrentUser($loginUser);
        return true;
    }

    /**
     * ログインの唯一の公開入口。
     *
     * 処理順:
     * 1. LoginAttempt を確定する
     * 2. guard に事前判定させる
     * 3. repository で資格情報照合を行う
     * 4. LoginUser を生成する
     * 5. セッションIDを再生成し、セッションへ保存する
     * 6. guard に成功通知する
     *
     * guard 未設定なら、実質的には従来の単純なログイン処理と同じ動きになる。
     *
     * @throws AuthException
     */
    public function login(LoginCredentials $credentials, ?LoginAttempt $attempt = null): LoginUser
    {
        $attempt = $attempt ?? new LoginAttempt($credentials->getUserId());

        // 資格情報と試行情報の userId が食い違うと、
        // アカウント単位の制限集計先が壊れるため先に弾く。
        $this->assertAttemptMatchesCredentials($credentials, $attempt);

        // レート制限やロック判定は資格情報照合より前に行う。
        $this->runBeforeLoginGuards($attempt);

        try {
            $row = $this->repo->findByCredentials(
                $credentials->getUserId(),
                $credentials->getPassword()
            );
            if (!$row) {
                throw new AuthException('Invalid user ID or password');
            }
        } catch (RecordNotFoundException | AuthException $e) {
            // 通常の認証失敗は、guard 側で回数加算できるよう型付きで通知する。
            $this->logger->fatal(
                __METHOD__
                . ' invalid credentials'
                . ' user_id=' . $credentials->getUserId()
                . ' exception=' . get_class($e)
            );
            $this->runFailedLoginGuards(
                $attempt,
                new LoginFailure(LoginFailureReason::INVALID_CREDENTIALS, $e)
            );
            throw new AuthException('Invalid user ID or password', 0, $e);
        } catch (Throwable $e) {
            // 想定外エラーも失敗として通知するが、
            // guard 側では通常の認証失敗と分けて扱える。
            $this->logger->fatal(
                __METHOD__
                . ' repository failure'
                . ' user_id=' . $credentials->getUserId()
                . ' exception=' . get_class($e)
            );
            $this->runFailedLoginGuards(
                $attempt,
                new LoginFailure(LoginFailureReason::INTERNAL_ERROR, $e)
            );
            throw new AuthException('Failed to complete login process', 0, $e);
        }

        // repository の返却値を Chandra 標準の LoginUser に正規化する。
        $loginUser = $this->buildLoginUser($row);

        if (!SessionHelper::regenerateSessionId()) {
            // セッション固定攻撃対策のため、再生成失敗時はログイン不成立とする。
            SessionHelper::delSessionAll();
            $this->logger->fatal(
                __METHOD__
                . ' failed to regenerate session id'
                . ' login_id=' . $credentials->getUserId()
            );
            $this->runFailedLoginGuards(
                $attempt,
                new LoginFailure(LoginFailureReason::SESSION_ESTABLISHMENT_FAILED)
            );
            throw new AuthException('Failed to establish a secure session');
        }

        // 成功後フックから現在ユーザーを参照できるよう、先にセッションへ入れる。
        SessionHelper::setUser($loginUser);

        try {
            $this->runSuccessfulLoginGuards($attempt, $loginUser);
        } catch (Throwable $e) {
            // 成功後処理に失敗したら中途半端なログイン状態を残さない。
            SessionHelper::delSessionAll();
            $this->logger->fatal(
                __METHOD__
                . ' success guard failed'
                . ' login_id=' . $credentials->getUserId()
                . ' exception=' . get_class($e)
            );

            if ($e instanceof AuthException) {
                throw $e;
            }

            throw new AuthException('Failed to complete login process', 0, $e);
        }

        $this->logger->info(
            __METHOD__
            . ' login succeeded'
            . ' login_id=' . $credentials->getUserId()
        );

        return $loginUser;
    }

    /**
     * ログアウト時はセッション情報を全削除する。
     */
    public function logout(): void
    {
        SessionHelper::delSessionAll();
    }

    /**
     * セッションに保持された現在ユーザーを取得する。
     */
    public function getCurrentUser(): ?LoginUser
    {
        $user = SessionHelper::getUser();
        if ($user === null || $user === '') {
            return null;
        }

        $loginUser = is_string($user)
            ? @unserialize($user, ['allowed_classes' => [LoginUser::class]])
            : null;

        return $loginUser instanceof LoginUser ? $loginUser : null;
    }

    /**
     * 現在ユーザーをセッションへ保存する。
     */
    public function setCurrentUser($loginUser)
    {
        SessionHelper::setUser($loginUser);
    }

    /**
     * @param iterable<mixed> $loginGuards
     * @return list<LoginGuardInterface>
     */
    private function normalizeLoginGuards(iterable $loginGuards): array
    {
        $normalized = [];

        foreach ($loginGuards as $guard) {
            if (!$guard instanceof LoginGuardInterface) {
                throw new \InvalidArgumentException(
                    'All login guards must implement ' . LoginGuardInterface::class
                );
            }

            $normalized[] = $guard;
        }

        return $normalized;
    }

    private function assertAttemptMatchesCredentials(
        LoginCredentials $credentials,
        LoginAttempt $attempt
    ): void {
        if ($credentials->getUserId() !== $attempt->getUserId()) {
            throw new \InvalidArgumentException('LoginAttempt user ID must match LoginCredentials');
        }
    }

    private function buildLoginUser(array $row): LoginUser
    {
        $startTime = ChandraConst::LOGIN_TIMEOUT > 0 ? time() : null;
        $rawPermissions = $row['permissions'] ?? [];

        // permissions は配列でも CSV 文字列でも受けられるようにする。
        if (is_array($rawPermissions)) {
            $permissions = $rawPermissions;
        } else {
            $permissions = explode(',', (string) $rawPermissions);
        }

        // 権限名の前後空白と空要素を除去して正規化する。
        $permissions = array_values(array_filter(
            array_map('trim', $permissions),
            static fn($s) => $s !== ''
        ));

        return new LoginUser(
            $row['user_id'],
            $row['user_name'],
            $permissions,
            $startTime
        );
    }

    private function runBeforeLoginGuards(LoginAttempt $attempt): void
    {
        foreach ($this->loginGuards as $guard) {
            try {
                $guard->assertCanAttempt($attempt);
            } catch (Throwable $e) {
                if ($e instanceof AuthException) {
                    throw $e;
                }

                throw new AuthException('Login request was rejected', 0, $e);
            }
        }
    }

    private function runSuccessfulLoginGuards(LoginAttempt $attempt, LoginUser $loginUser): void
    {
        foreach ($this->loginGuards as $guard) {
            $guard->recordSuccessfulLogin($attempt, $loginUser);
        }
    }

    private function runFailedLoginGuards(LoginAttempt $attempt, LoginFailure $failure): void
    {
        foreach ($this->loginGuards as $guard) {
            try {
                $guard->recordFailedLogin($attempt, $failure);
            } catch (Throwable $e) {
                if ($e instanceof AuthException) {
                    throw $e;
                }

                throw new AuthException('Failed to complete login process', 0, $e);
            }
        }
    }
}
