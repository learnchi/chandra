<?php

declare(strict_types=1);

namespace Studiogau\Chandra\Auth;

/**
 * ログインユーザー情報を保持するDTO。
 */
class LoginUser
{
    private string $userId;
    private string $userName;
    private array $permissions;
    private ?int $startTime;

    /**
     * @param string   $userId      ユーザーID
     * @param string   $userName    ユーザー名
     * @param array    $permissions 権限一覧
     * @param int|null $startTime   セッション開始時刻（UNIXタイム）
     */
    public function __construct(string $userId, string $userName, array $permissions = [], ?int $startTime = null)
    {
        $this->userId = $userId;
        $this->userName = $userName;
        $this->permissions = $permissions;
        $this->startTime = $startTime;
    }

    /**
     * @return string ユーザーID
     */
    public function getUserId(): string
    {
        return $this->userId;
    }

    /**
     * @return string ユーザー名
     */
    public function getUserName(): string
    {
        return $this->userName;
    }

    /**
     * 権限を保持しているか判定する。
     *
     * @param string $permission 権限キー
     * @return bool
     */
    public function can(string $permission): bool
    {
        return in_array($permission, $this->permissions, true);
    }

    /**
     * @return int|null セッション開始時刻
     */
    public function getStartTime(): ?int
    {
        return $this->startTime;
    }

    /**
     * セッション開始時刻を設定する。
     *
     * @param int|null $startTime UNIXタイム
     * @return void
     */
    public function setStartTime(?int $startTime): void
    {
        $this->startTime = $startTime;
    }
}
