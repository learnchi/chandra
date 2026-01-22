<?php

namespace Studiogau\Chandra\Auth;

/**
 * ログインユーザー情報を取得するためのインターフェース。
 * 業務側で implements して実装する。
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
