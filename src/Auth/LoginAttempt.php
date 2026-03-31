<?php

declare(strict_types=1);

namespace Studiogau\Chandra\Auth;

/**
 * 1回のログイン試行に付随するコンテキスト情報を表す。
 *
 * ここには「誰として試そうとしているか」「どこから来たか」
 * 「画面や経路などの追加文脈」を載せる。
 * 実際の資格情報は LoginCredentials に分離し、guard が
 * パスワード文字列を触らなくても済むようにしている。
 */
final class LoginAttempt
{
    private string $userId;
    private ?string $clientIp;
    private ?string $userAgent;

    /** @var array<string, mixed> */
    private array $attributes;

    /**
     * @param string              $userId     試行対象のログインID
     * @param string|null         $clientIp   クライアントIP
     * @param string|null         $userAgent  User-Agent
     * @param array<string, mixed> $attributes
     *                                         画面名やルート名など、
     *                                         アプリ側で自由に拡張したい文脈
     */
    public function __construct(
        string $userId,
        ?string $clientIp = null,
        ?string $userAgent = null,
        array $attributes = []
    ) {
        $this->userId = $userId;
        $this->clientIp = $clientIp;
        $this->userAgent = $userAgent;
        $this->attributes = $attributes;
    }

    /**
     * 典型的な Web アプリ向けの生成ヘルパ。
     *
     * `$_SERVER` 相当の配列から IP / user-agent を抜き出して
     * LoginAttempt を組み立てる。
     *
     * @param string               $userId     試行対象のログインID
     * @param array<string, mixed> $server
     *                                         ふつうは `$_SERVER`
     * @param array<string, mixed> $attributes
     *                                         画面名などの追加情報
     */
    public static function fromServer(string $userId, array $server, array $attributes = []): self
    {
        $clientIp = isset($server['REMOTE_ADDR']) ? (string) $server['REMOTE_ADDR'] : null;
        $userAgent = isset($server['HTTP_USER_AGENT']) ? (string) $server['HTTP_USER_AGENT'] : null;

        return new self($userId, $clientIp, $userAgent, $attributes);
    }

    /**
     * アカウント単位制限のキーとして使うログインIDを返す。
     */
    public function getUserId(): string
    {
        return $this->userId;
    }

    /**
     * IP単位のレート制限などに使うクライアントIPを返す。
     */
    public function getClientIp(): ?string
    {
        return $this->clientIp;
    }

    /**
     * 必要であれば user-agent も制限判定や監査ログに使える。
     */
    public function getUserAgent(): ?string
    {
        return $this->userAgent;
    }

    /**
     * @return array<string, mixed>
     *                              guard 実装側がまとめて参照したい追加情報
     */
    public function getAttributes(): array
    {
        return $this->attributes;
    }

    /**
     * 追加属性を 1 件だけ安全に取り出すヘルパ。
     */
    public function getAttribute(string $key, mixed $default = null): mixed
    {
        return $this->attributes[$key] ?? $default;
    }
}
