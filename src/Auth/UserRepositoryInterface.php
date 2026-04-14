<?php

namespace Studiogau\Chandra\Auth;

/**
 * ログインユーザー情報を取得するためのインターフェース。
 * 各アプリ側で implements して実装する。
 *
 * 返却配列は AuthService が LoginUser に変換して利用する。
 * - $row['id']          アプリ内で一意なユーザーID
 * - $row['login_id']    ログイン時に入力するID
 * - $row['user_name']   ログイン後に表示するユーザー名
 * - $row['permissions'] ログインユーザーがアクセスできる画面名の配列
 */
interface UserRepositoryInterface
{
    /**
     * ユーザーIDとパスワードを元にユーザー情報を取得する。
     *
     * @param string $userId   ログインID
     * @param string $password パスワード
     * @return array|null 見つからない場合は null
     */
    public function findByCredentials(string $userId, string $password): ?array;
}
