<?php

declare(strict_types=1);

namespace Studiogau\Chandra\Auth;

use Throwable;

/**
 * 失敗時フックに渡す失敗情報。
 *
 * 失敗理由を enum で固定しつつ、必要なら元例外も参照できるようにしている。
 * これにより guard 実装側は「数える失敗」と「運用エラー」を分離しやすい。
 */
final class LoginFailure
{
    private LoginFailureReason $reason;
    private ?Throwable $cause;

    /**
     * @param LoginFailureReason $reason 失敗種別
     * @param Throwable|null     $cause  元例外。不要なら null
     */
    public function __construct(LoginFailureReason $reason, ?Throwable $cause = null)
    {
        $this->reason = $reason;
        $this->cause = $cause;
    }

    /**
     * guard 側が分岐判断に使う失敗種別を返す。
     */
    public function getReason(): LoginFailureReason
    {
        return $this->reason;
    }

    /**
     * 監査ログやデバッグ用途で元例外を参照したい場合に使う。
     */
    public function getCause(): ?Throwable
    {
        return $this->cause;
    }
}
