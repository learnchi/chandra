<?php

namespace Studiogau\Chandra\Support;

use Symfony\Component\HttpFoundation\Cookie;
use Symfony\Component\HttpFoundation\Request;

/**
 * Cookie の暗号化/復号と発行・取得を手助けするヘルパー。
 */
final class CookieHelper
{
    private const DEFAULT_SAMESITE = Cookie::SAMESITE_LAX;

    /** 暗号化アルゴリズム */
    private const CRYPT_ALGO = 'AES-128-CBC';
    /** 暗号鍵 */
    private const CRYPT_KEY = 'f1diag8fdajgau7d';
    /** 初期化ベクトル */
    private const CRYPT_IV = 'fvd9uga5h2dskn3s';

    /**
     * 平文を暗号化する。
     *
     * @param string $plain 平文
     * @return string 暗号化文字列
     */
    private static function encrypt(string $plain): string
    {
        return openssl_encrypt($plain, self::CRYPT_ALGO, self::CRYPT_KEY, 0, self::CRYPT_IV);
    }

    /**
     * 暗号化文字列を復号する。
     *
     * @param string|null $cipher 暗号化文字列
     * @return string|null 復号結果（null/空文字はnullを返す）
     */
    private static function decrypt(?string $cipher)
    {
        if ($cipher === null || $cipher === '') {
            return null;
        }
        return openssl_decrypt($cipher, self::CRYPT_ALGO, self::CRYPT_KEY, 0, self::CRYPT_IV);
    }

    /**
     * 暗号化した値をセットした Cookie を生成して返す。
     *
     * @param string   $name       Cookie名
     * @param string   $value      Cookie値（内部で暗号化）
     * @param int|null $ttlSeconds 有効期限（秒）。nullでセッションクッキー
     * @param array    $options    その他オプション
     * @return Cookie
     */
    public static function make(
        string $name,
        string $value,
        ?int $ttlSeconds = null,
        array $options = []
    ): Cookie {
        $expires = $ttlSeconds ? time() + $ttlSeconds : 0;
        $defaults = [
            'expires'  => $expires,
            'path'     => '/',
            'secure'   => isset($_SERVER['HTTPS']),
            'httpOnly' => true,
            'sameSite' => self::DEFAULT_SAMESITE,
        ];
        $opt = array_replace($defaults, $options);

        return Cookie::create(
            $name,
            self::encrypt($value),
            $opt['expires'],
            $opt['path'],
            $opt['domain'] ?? null,
            $opt['secure'],
            $opt['httpOnly'],
            false,
            $opt['sameSite']
        );
    }

    /**
     * Request から Cookie を取得して復号する。
     *
     * @param Request    $request Requestインスタンス
     * @param string     $name    Cookie名
     * @param mixed|null $default 見つからない場合のデフォルト値
     * @return mixed 復号済みの値
     */
    public static function get(Request $request, string $name, $default = null)
    {
        $cipher = $request->cookies->get($name);
        $plain = self::decrypt($cipher);
        return $plain !== null ? $plain : $default;
    }

    /**
     * 即時失効させるCookieを生成する（削除用）。
     *
     * @param string $name Cookie名
     * @param string $path パス
     * @return Cookie
     */
    public static function forget(string $name, string $path = '/'): Cookie
    {
        return Cookie::create($name, '', time() - 3600, $path);
    }
}
