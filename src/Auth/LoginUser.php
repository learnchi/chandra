<?php

declare(strict_types=1);

namespace Studiogau\Chandra\Auth;

/**
 * ログイン済みユーザー情報を保持するDTO。
 */
class LoginUser
{
    private string $id;
    private string $loginId;
    private string $userName;
    private array $permissions;
    private ?int $startTime;

    /**
     * @param string|int $id         アプリ内で一意なユーザーID
     * @param string|int $loginId    ログイン時に入力するID
     * @param string     $userName   ログイン後に表示するユーザー名
     * @param array      $permissions 権限一覧
     * @param int|null   $startTime  セッション開始時刻 UNIXタイム
     */
    public function __construct(
        string|int $id,
        string|int $loginId,
        string $userName,
        array $permissions = [],
        ?int $startTime = null
    ) {
        $this->id = (string) $id;
        $this->loginId = (string) $loginId;
        $this->userName = $userName;
        $this->permissions = $permissions;
        $this->startTime = $startTime;
    }

    /**
     * @return string アプリ内で一意なユーザーID
     */
    public function getId(): string
    {
        return $this->id;
    }

    /**
     * @return string ログイン時に入力するID
     */
    public function getLoginId(): string
    {
        return $this->loginId;
    }

    /**
     * @return string ログイン後に表示するユーザー名
     */
    public function getUserName(): string
    {
        return $this->userName;
    }

    /**
     * 権限を保持しているかを確認する。
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
