<?php

declare(strict_types=1);

namespace Studiogau\Chandra\Support;

use Studiogau\Chandra\Auth\LoginUser;
use Studiogau\Chandra\Logging\Logger;

/**
 * $_SESSION をラップするヘルパー。
 *
 * - キーはSCRIPT_NAMEから取得したプロジェクト名でプレフィックスする。
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
     * @param string      $func 画面・機能名
     * @param string|null $key  キー名（nullならfunc単位で削除）
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
     * セッションとCookieを完全に破棄する。
     *
     * @return void
     */
    public static function delSessionAll(): void
    {
        $_SESSION = [];
        if (isset($_COOKIE[session_name()])) {
            setcookie(session_name(), '', time() - 42000, '/');
        }
        if (session_status() === PHP_SESSION_ACTIVE) {
            session_destroy();
        }
    }

    /**
     * preference情報を保存する。
     *
     * @param string $key   キー名
     * @param mixed  $value 値
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
            unset($prefMap);
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
     * ログインユーザー情報を削除する。
     *
     * @return bool 削除結果
     */
    public static function delUser(): bool
    {
        return self::delSession(self::USER_SESSION_KEY);
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
     * @param string $key   セッションキー
     * @param mixed  $value 保存する値
     * @return bool 保存結果
     */
    private static function setSession(string $key, mixed $value): bool
    {
        if ($key === '') {
            return false;
        }

        $pkey = self::projectPrefix($key);
        $_SESSION[$pkey] = $value;
        return isset($_SESSION[$pkey]);
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
