<?php

declare(strict_types=1);

namespace Studiogau\Chandra\Auth;

use RuntimeException;
use Studiogau\Chandra\Logging\Logger;
use Studiogau\Chandra\Config\ChandraConst;
use Studiogau\Chandra\Support\SessionHelper;
use Studiogau\Chandra\Database\RecordNotFoundException;

class AuthException extends RuntimeException
{
}

/**
 * 認証に関するサービスクラス。
 */
final class AuthService
{
    private UserRepositoryInterface $repo;

    /** @var Logger */
    private $logger;

    /**
     * @param UserRepositoryInterface $repo   ユーザー情報取得用リポジトリ
     * @param Logger|null             $logger ログ出力先（省略時はデフォルト）
     */
    public function __construct(UserRepositoryInterface $repo, ?Logger $logger = null)
    {
        $this->repo = $repo;
        $this->logger = $logger ?? Logger::createDefault();
    }

    /**
     * セッションに有効なユーザーがいるか確認する。
     *
     * @return bool 認証済みならtrue
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
     * ログイン処理を行う。
     *
     * @param string $userId   ユーザーID
     * @param string $password パスワード
     * @return bool 認証成功ならtrue
     *
     * @throws AuthException
     */
    public function login(string $userId, string $password): bool
    {
        try {
            $row = $this->repo->findByCredentials($userId, $password);
            if (!$row) {
                $this->logger->fatal(__METHOD__ . " select user returned " . $row . " user id=" . $userId . " password=" . $password);
                throw new AuthException('Invalid user ID or password');
            }
        } catch (RecordNotFoundException $e) {
            $this->logger->fatal(__METHOD__ . " select user thrown exeption " . $e . " user id=" . $userId . " password=" . $password);
            throw new AuthException('Invalid user ID or password');
        }

        $startTime = ChandraConst::LOGIN_TIMEOUT > 0 ? time() : null;

        // 権限の整形
        $rawPermissions = $row['permissions'] ?? [];

        if (is_array($rawPermissions)) {
            $permissions = $rawPermissions;
        } else {
            // 文字列想定（null対策済み）
            $permissions = explode(',', (string)$rawPermissions);
        }

        // trim + 空要素除去
        $permissions = array_values(array_filter(
            array_map('trim', $permissions),
            static fn($s) => $s !== ''
        ));

        $loginUser = new LoginUser(
            $row['user_id'],
            $row['user_name'],
            $permissions,
            $startTime
        );

        SessionHelper::setUser($loginUser);
        return true;
    }

    /**
     * ログアウトする（セッションからユーザー情報を削除）。
     *
     * @return void
     */
    public function logout(): void
    {
        SessionHelper::delUser();
    }

    /**
     * 現在のログインユーザーを取得する。
     *
     * @return LoginUser|null
     */
    public function getCurrentUser(): ?LoginUser
    {
        $user = SessionHelper::getUser();
        if ($user === null || $user === '') {
            return null;
        }

        $loginUser = @unserialize($user);
        return $loginUser instanceof LoginUser ? $loginUser : null;
    }

    /**
     * ログインユーザーをセッションへ保存する。
     *
     * @param LoginUser $loginUser 保存するユーザー
     * @return void
     */
    public function setCurrentUser($loginUser)
    {
        SessionHelper::setUser($loginUser);
    }
}
