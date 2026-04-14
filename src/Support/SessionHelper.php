<?php

declare(strict_types=1);

namespace Studiogau\Chandra\Support;

use Studiogau\Chandra\Auth\LoginUser;

/**
 * $_SESSION をラップするヘルパー。
 *
 * - キーは SCRIPT_NAME から取得したプロジェクト名でプレフィックスする。
 * - セッション開始は自動で行わないため、必要に応じて session_start() を呼ぶこと。
 */
final class SessionHelper
{
    public const SESSION_KEY = 'struct';           // 画面ごとのセッション保持
    public const USER_SESSION_KEY = 'user';        // ログインユーザーの保持
    public const MASTER_SESSION_KEY = 'master';    // マスター情報の保持
    public const PREF_SESSION_KEY = 'preferences'; // 設定情報の保持
    public const FLUSH_ERROR = 'flasherror';       // Flushメッセージ（エラー）
    public const FLUSH_SUCCESS = 'flashsuccess';   // Flushメッセージ（成功）

    /**
     * structデータを保存する。
     *
     * @param string $func 画面・機能名
     * @param string $key  キー名
     * @param mixed  $value 値
     * @return void
     */
    public static function setData(string $func, string $key, mixed $value): void
    {
        $structMap = self::getSession(self::SESSION_KEY);

        if ($structMap !== null && array_key_exists($func, $structMap)) {
            $funcMap = $structMap[$func];
            $funcMap[$key] = $value;
            $structMap[$func] = $funcMap;
        } else {
            $funcMap = [$key => $value];
            $structMap = $structMap === null ? [$func => $funcMap] : $structMap + [$func => $funcMap];
        }

        self::setSession(self::SESSION_KEY, $structMap);
    }

    /**
     * structデータを取得する。
     *
     * @param string $func 画面・機能名
     * @param string $key  キー名
     * @return mixed|null
     */
    public static function getData(string $func, string $key): mixed
    {
        $structMap = self::getSession(self::SESSION_KEY);
        if ($structMap !== null && array_key_exists($func, $structMap) && array_key_exists($key, $structMap[$func])) {
            return $structMap[$func][$key];
        }

        return null;
    }

    /**
     * structデータを削除する。
     *
     * @param string $func 画面・機能名
     * @param string|null $key キー名（nullならfunc単位で削除）
     * @return void
     */
    public static function delData(string $func, ?string $key = null): void
    {
        $structMap = self::getSession(self::SESSION_KEY);
        if ($structMap === null) {
            return;
        }

        if ($key === null) {
            unset($structMap[$func]);
        } elseif (array_key_exists($func, $structMap) && array_key_exists($key, $structMap[$func])) {
            unset($structMap[$func][$key]);
        }

        self::setSession(self::SESSION_KEY, $structMap);
    }

    /**
     * 指定したfunc以外のstructデータを全て削除する。
     *
     * @param string $func 残すfuncキー
     * @return void
     */
    public static function clearDataExceptFunc(string $func): void
    {
        $structMap = self::getSession(self::SESSION_KEY);
        $funcMap = null;
        if ($structMap !== null && array_key_exists($func, $structMap)) {
            $funcMap = $structMap[$func];
        }

        $structMap = $funcMap !== null ? [$func => $funcMap] : null;
        self::setSession(self::SESSION_KEY, $structMap);
    }

    /**
     * structデータをすべてクリアする。
     *
     * @return void
     */
    public static function clearData(): void
    {
        self::setSession(self::SESSION_KEY, null);
    }

    /**
     * 権限変更後にセッションIDを再生成する。
     *
     * セッション固定攻撃対策として、主にログイン成功後の呼び出しを想定する。
     *
     * @param bool $deleteOldSession 旧セッションを削除する場合 true
     * @return bool 再生成成功時 true
     */
    public static function regenerateSessionId(bool $deleteOldSession = true): bool
    {
        if (session_status() !== PHP_SESSION_ACTIVE) {
            return false;
        }

        return session_regenerate_id($deleteOldSession);
    }

    /**
     * $_SESSION を空にし、セッションCookie の失効を試みる。
     * セッションが開始済みの場合のみ session_destroy() を呼ぶ。
     * このメソッド自体は session_start() を行わない。
     *
     * @return void
     */
    public static function delSessionAll(): void
    {
        $_SESSION = [];
        self::expireSessionCookie();

        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /**
     * 既存セッションを無効化し、必要に応じて新しいセッションを開始する。
     *
     * @param bool $restart true の場合は無効化後に新しいセッションを開始する
     * @return void
     */
    public static function invalidateSession(bool $restart = false): void
    {
        // 現在のリクエストが既存セッションを参照しているかを判定する。
        $cookieName = session_name();
        $hadCookie = isset($_COOKIE[$cookieName]);
        $hasSessionRef =
            session_status() === PHP_SESSION_ACTIVE
            || session_id() !== ''
            || $hadCookie;

        // 既存セッションの破棄対象があり、まだ開始されていなければ開始する。
        if ($hasSessionRef && session_status() !== PHP_SESSION_ACTIVE) {
            @session_start();
        }

        // 同一リクエスト内の利用を止めるため、セッション配列は先に空にする。
        $_SESSION = [];

        // 開始済みセッションがあれば、永続化されたセッション本体も破棄する。
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }

        // Cookie が存在した場合は、destroy の成否に関係なく失効を試みる。
        if ($hadCookie) {
            unset($_COOKIE[$cookieName]);
            self::expireSessionCookie();
        }

        // 再開指定があれば、古い ID を引き継がないようにしてから新規開始する。
        if ($restart && session_status() !== PHP_SESSION_ACTIVE) {
            if (session_id() !== '') {
                session_id('');
            }

            @session_start();
        }
    }

    /**
     * preference情報を保存する。
     *
     * @param string $key キー名
     * @param mixed $value 値
     * @return void
     */
    public static function setPref(string $key, mixed $value): void
    {
        $prefMap = self::getSession(self::PREF_SESSION_KEY);

        if (!empty($prefMap)) {
            $prefMap[$key] = $value;
        } else {
            $prefMap = [$key => $value];
        }

        self::setSession(self::PREF_SESSION_KEY, $prefMap);
    }

    /**
     * preference情報を取得する。
     *
     * @param string $key キー名
     * @return mixed|null
     */
    public static function getPref(string $key): mixed
    {
        $prefMap = self::getSession(self::PREF_SESSION_KEY);
        if (!empty($prefMap) && array_key_exists($key, $prefMap)) {
            return $prefMap[$key];
        }

        return null;
    }

    /**
     * preference情報を削除する。
     *
     * @param string|null $key キー名（nullなら全削除）
     * @return void
     */
    public static function delPref(?string $key = null): void
    {
        $prefMap = self::getSession(self::PREF_SESSION_KEY);
        if (empty($prefMap)) {
            return;
        }

        if ($key === null) {
            $prefMap = null;
        } elseif (array_key_exists($key, $prefMap)) {
            unset($prefMap[$key]);
        }

        self::setSession(self::PREF_SESSION_KEY, $prefMap);
    }

    /**
     * preference情報を空にする。
     *
     * @return void
     */
    public static function clearPref(): void
    {
        self::setSession(self::PREF_SESSION_KEY, null);
    }

    /**
     * マスター情報を保存する。
     *
     * @param mixed $masterMap マスター情報
     * @return void
     */
    public static function setMaster($masterMap): void
    {
        self::setSession(self::MASTER_SESSION_KEY, $masterMap);
    }

    /**
     * マスター情報を取得する。
     *
     * @return mixed
     */
    public static function getMaster()
    {
        return self::getSession(self::MASTER_SESSION_KEY);
    }

    /**
     * マスター情報から指定キーの値を取得する。
     *
     * @param string $key キー名
     * @return mixed|null
     */
    public static function getMasterList($key)
    {
        $value = null;
        $structMap = self::getSession(self::MASTER_SESSION_KEY);
        if (!empty($structMap) && array_key_exists($key, $structMap)) {
            $value = $structMap[$key];
        }

        return $value;
    }

    /**
     * ログインユーザーを保存する。
     *
     * @param LoginUser $user ログインユーザー
     * @return void
     */
    public static function setUser(LoginUser $user): void
    {
        self::setSession(self::USER_SESSION_KEY, serialize($user));
    }

    /**
     * ログインユーザーを取得する。
     *
     * @return mixed
     */
    public static function getUser(): mixed
    {
        return self::getSession(self::USER_SESSION_KEY);
    }

    /**
     * フラッシュエラーメッセージをセットする。
     *
     * @param string $msg メッセージ
     * @return void
     */
    public static function flushError(string $msg): void
    {
        self::setSession(self::FLUSH_ERROR, $msg);
    }

    /**
     * フラッシュエラーメッセージの有無を確認する。
     *
     * @return bool
     */
    public static function hasFlushError(): bool
    {
        return self::getSession(self::FLUSH_ERROR) ? true : false;
    }

    /**
     * フラッシュエラーメッセージを取得し、セッションから削除する。
     *
     * @return string|null
     */
    public static function getFlushError(): ?string
    {
        $msg = self::getSession(self::FLUSH_ERROR) ?? null;
        self::delSession(self::FLUSH_ERROR);

        return $msg;
    }

    /**
     * フラッシュサクセスメッセージをセットする。
     *
     * @param string $msg メッセージ
     * @return void
     */
    public static function flushSuccess(string $msg): void
    {
        self::setSession(self::FLUSH_SUCCESS, $msg);
    }

    /**
     * フラッシュサクセスメッセージの有無を確認する。
     *
     * @return bool
     */
    public static function hasFlushSuccess(): bool
    {
        return self::getSession(self::FLUSH_SUCCESS) ? true : false;
    }

    /**
     * フラッシュサクセスメッセージを取得し、セッションから削除する。
     *
     * @return string|null
     */
    public static function getFlushSuccess(): ?string
    {
        $msg = self::getSession(self::FLUSH_SUCCESS) ?? null;
        self::delSession(self::FLUSH_SUCCESS);

        return $msg;
    }

    /**
     * セッションキーへ値を保存する（プレフィックス付き）。
     *
     * @param string $key セッションキー
     * @param mixed $value 保存する値
     * @return bool 保存結果
     */
    private static function setSession(string $key, mixed $value): bool
    {
        if ($key === '') {
            return false;
        }

        $pkey = self::projectPrefix($key);
        $_SESSION[$pkey] = $value;

        return array_key_exists($pkey, $_SESSION);
    }

    /**
     * セッションキーの値を取得する（プレフィックス付き）。
     *
     * @param string $key セッションキー
     * @return mixed|null
     */
    private static function getSession(string $key): mixed
    {
        if ($key === '') {
            return null;
        }

        $pkey = self::projectPrefix($key);

        return $_SESSION[$pkey] ?? null;
    }

    /**
     * セッションキーを削除する（プレフィックス付き）。
     *
     * @param string $key セッションキー
     * @return bool 削除結果
     */
    private static function delSession(string $key): bool
    {
        if ($key === '') {
            return false;
        }

        $pkey = self::projectPrefix($key);
        unset($_SESSION[$pkey]);

        return !isset($_SESSION[$pkey]);
    }

    /**
     * 現在のセッションCookie属性を維持したまま、Cookieを失効させる。
     *
     * @return void
     */
    private static function expireSessionCookie(): void
    {
        if ((string) ini_get('session.use_cookies') === '0' || headers_sent()) {
            return;
        }

        $params = session_get_cookie_params();
        $options = [
            'expires' => time() - 42000,
            'path' => $params['path'] ?: '/',
            'secure' => (bool) $params['secure'],
            'httponly' => (bool) $params['httponly'],
        ];

        if (!empty($params['domain'])) {
            $options['domain'] = $params['domain'];
        }

        if (!empty($params['samesite'])) {
            $options['samesite'] = $params['samesite'];
        }

        setcookie(session_name(), '', $options);
        unset($_COOKIE[session_name()]);
    }

    /**
     * プロジェクト名をキーへ付与する。
     *
     * @param string $key 元のキー
     * @return string プレフィックス付きキー
     */
    private static function projectPrefix(string $key): string
    {
        $script = $_SERVER['SCRIPT_NAME'] ?? '';
        $dirs = explode('/', $script);
        $project = $dirs[1] ?? '';

        return $project . $key;
    }
}
