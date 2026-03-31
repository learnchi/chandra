<?php

declare(strict_types=1);

namespace Studiogau\Chandra\Auth;

/**
 * ログイン失敗の分類。
 *
 * noblestock 側 guard 実装では、この値を見て
 * 「認証失敗回数として数えるか」
 * 「単なる内部エラーとして別扱いにするか」
 * を分岐できる。
 */
enum LoginFailureReason: string
{
    /** ID/パスワード不一致など、通常の認証失敗。 */
    case INVALID_CREDENTIALS = 'invalid_credentials';

    /** 認証自体は通ったが、安全なセッション確立に失敗した。 */
    case SESSION_ESTABLISHMENT_FAILED = 'session_establishment_failed';

    /** repository や guard 内部で想定外エラーが起きた。 */
    case INTERNAL_ERROR = 'internal_error';
}
