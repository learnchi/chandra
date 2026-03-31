<?php

declare(strict_types=1);

namespace Studiogau\Chandra\Auth;

/**
 * 認証に必要な資格情報だけを表す値オブジェクト。
 *
 * LoginAttempt と分離することで、
 * - パスワードは認証処理のみに閉じ込める
 * - IP や user-agent などの周辺情報は guard 側で扱う
 * という責務分離を明確にしている。
 */
final class LoginCredentials
{
    private string $userId;
    private string $password;

    /**
     * @param string $userId   ログインID
     * @param string $password 平文パスワード
     */
    public function __construct(string $userId, string $password)
    {
        $this->userId = $userId;
        $this->password = $password;
    }

    /**
     * guard や repository が参照するログインIDを返す。
     */
    public function getUserId(): string
    {
        return $this->userId;
    }

    /**
     * 認証本体でのみ使用する平文パスワードを返す。
     */
    public function getPassword(): string
    {
        return $this->password;
    }
}
