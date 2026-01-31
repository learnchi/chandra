<?php

namespace Studiogau\Chandra\Auth;

/**
 * ログインユーザー情報を取得するためのインターフェース。
 * 業務側で implements して実装する。
 * 返却値はsrc\Auth\LoginUser.phpを生成できる以下の配列とする必要がある：
 *     $row['user_id']      ログイン時に入力するID
 *     $row['user_name']    ログイン後に表示するユーザー名
 *     $row['permissions']  ログインユーザーがアクセスできる画面名の配列
 */
interface UserRepositoryInterface
{
    /**
     * ユーザーIDとパスワードを元にユーザー情報を取得する。
     *
     * @param string $userId   ユーザーID
     * @param string $password パスワード
     * @return array|null ユーザーデータ（見つからない場合はnull）
     */
    public function findByCredentials(string $userId, string $password): ?array;
}
