<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Studiogau\Chandra\Auth\LoginUser;

require_once __DIR__ . '/../vendor/autoload.php';

final class LoginUserPermissionTest extends TestCase
{
    /**
     * 目的: 権限判定が厳密比較で行われることを確認する。
     * 期待: 文字列が完全一致しない場合は false になる。
     */
    public function testCanUsesStrictComparison(): void
    {
        $user = new LoginUser('u1', 'Tester', ['1', 'read']);

        $this->assertTrue($user->can('1'));
        $this->assertFalse($user->can('01'));
        $this->assertTrue($user->can('read'));
        $this->assertFalse($user->can('write'));
    }
}

