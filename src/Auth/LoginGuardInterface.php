<?php

declare(strict_types=1);

namespace Studiogau\Chandra\Auth;

/**
 * ログイン試行制御の拡張ポイント。
 *
 * Chandra は「いつ呼ぶか」の契約だけを持ち、
 * 実際の制限内容や保存先はアプリ側で実装する。
 *
 * 想定用途:
 * - IP単位のレート制限
 * - アカウント単位のレート制限
 * - アカウントロック
 * - 監査ログ記録
 */
interface LoginGuardInterface
{
    /**
     * 認証前チェック。
     *
     * 認証処理に進めてよいかを判定する。
     * 進めてはいけない場合は AuthException を投げる。
     *
     * ここで行う想定:
     * - 既にロック済みか
     * - 短時間の試行回数上限を超えていないか
     * - IP が拒否対象になっていないか
     */
    public function assertCanAttempt(LoginAttempt $attempt): void;

    /**
     * 認証成功後フック。
     *
     * セッション確立まで完了した後にだけ呼ばれる。
     * 失敗回数のクリアやロック解除など、
     * 成功時にだけ行いたい後処理を実装する。
     */
    public function recordSuccessfulLogin(LoginAttempt $attempt, LoginUser $user): void;

    /**
     * 認証失敗後フック。
     *
     * 失敗理由は LoginFailure に含まれる。
     * たとえば INVALID_CREDENTIALS のときだけ回数を加算し、
     * INTERNAL_ERROR は監査ログだけに留める、といった実装ができる。
     */
    public function recordFailedLogin(LoginAttempt $attempt, LoginFailure $failure): void;
}
