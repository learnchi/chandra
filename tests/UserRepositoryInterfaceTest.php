<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Studiogau\Chandra\Auth\UserRepositoryInterface;

require_once __DIR__ . '/../vendor/autoload.php';

final class UserRepositoryInterfaceTest extends TestCase
{
    /**
     * findByCredentials が「ユーザーIDとパスワードを引数に取り、?array を返す」シグネチャであることを確認する。
     */
    public function testFindByCredentialsSignature(): void
    {
        $reflection = new ReflectionClass(UserRepositoryInterface::class);
        $method = $reflection->getMethod('findByCredentials');

        $parameters = $method->getParameters();
        $this->assertCount(2, $parameters);
        $this->assertSame('string', (string) $parameters[0]->getType());
        $this->assertSame('string', (string) $parameters[1]->getType());

        $returnType = $method->getReturnType();
        $this->assertNotNull($returnType);
        $this->assertSame('?array', (string) $returnType);
    }
}
