<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Studiogau\Chandra\Auth\LoginUser;

require_once __DIR__ . '/../vendor/autoload.php';

final class LoginUserTest extends TestCase
{
    /**
     * startTime の getter/setter が値を保持することを確認する。
     */
    public function testStartTimeGetterAndSetter(): void
    {
        $user = new LoginUser('2', 'user2', 'Tester', [], null);

        $this->assertNull($user->getStartTime());
        $this->assertSame('2', $user->getId());
        $this->assertSame('user2', $user->getLoginId());

        $user->setStartTime(1700000000);
        $this->assertSame(1700000000, $user->getStartTime());
    }
}
