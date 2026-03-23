<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Studiogau\Chandra\Support\Utility;

require_once __DIR__ . '/../vendor/autoload.php';

final class UtilityCsrfTest extends TestCase
{
    private array $backupSession = [];
    private array $backupServer = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->backupSession = $_SESSION ?? [];
        $this->backupServer = $_SERVER;

        $_SESSION = [];
        $_SERVER['SCRIPT_NAME'] = '/chandra/index.php';
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->backupSession;
        $_SERVER = $this->backupServer;
        parent::tearDown();
    }

    public function testIssueCsrfTokenStoresHexTokenPerScope(): void
    {
        $token = Utility::issueCsrfToken('form.create');

        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $token);
        $this->assertSame(
            ['form.create' => $token],
            $_SESSION[$this->csrfSessionKey()]
        );
    }

    public function testRenderCsrfHiddenInputOutputsStoredToken(): void
    {
        $html = Utility::renderCsrfHiddenInput('form.edit');

        $this->assertStringContainsString('name="_csrf_scope"', $html);
        $this->assertStringContainsString('value="form.edit"', $html);
        $this->assertMatchesRegularExpression(
            '/name="_csrf_token" value="[0-9a-f]{64}"/',
            $html
        );

        preg_match('/value="([0-9a-f]{64})"/', $html, $matches);

        $this->assertSame($matches[1], $_SESSION[$this->csrfSessionKey()]['form.edit']);
    }

    public function testValidateCsrfTokenSucceedsOnceAndRemovesToken(): void
    {
        $token = Utility::issueCsrfToken('form.delete');

        $this->assertTrue(Utility::validateCsrfToken('form.delete', $token));
        $this->assertSame([], $_SESSION[$this->csrfSessionKey()]);
        $this->assertFalse(Utility::validateCsrfToken('form.delete', $token));
    }

    public function testValidateCsrfTokenFailsForWrongTokenAndConsumesOnlyTargetScope(): void
    {
        $validToken = Utility::issueCsrfToken('form.update');
        $otherToken = Utility::issueCsrfToken('form.search');

        $this->assertFalse(Utility::validateCsrfToken('form.update', str_repeat('a', 64)));
        $this->assertArrayNotHasKey('form.update', $_SESSION[$this->csrfSessionKey()]);
        $this->assertSame($otherToken, $_SESSION[$this->csrfSessionKey()]['form.search']);
        $this->assertTrue(Utility::validateCsrfToken('form.search', $otherToken));

        $this->assertNotSame($validToken, $otherToken);
    }

    public function testValidateCsrfTokenFailsWhenSubmittedTokenIsMissing(): void
    {
        Utility::issueCsrfToken('form.publish');

        $this->assertFalse(Utility::validateCsrfToken('form.publish', null));
        $this->assertSame([], $_SESSION[$this->csrfSessionKey()]);
    }

    public function testIssueCsrfTokenRecoversFromUnexpectedSessionValue(): void
    {
        $_SESSION[$this->csrfSessionKey()] = 'invalid';

        $token = Utility::issueCsrfToken('form.recover');

        $this->assertSame(
            ['form.recover' => $token],
            $_SESSION[$this->csrfSessionKey()]
        );
    }

    private function csrfSessionKey(): string
    {
        $dirs = explode('/', $_SERVER['SCRIPT_NAME'] ?? '');
        $project = $dirs[1] ?? '';

        return $project . 'csrf_tokens';
    }
}
