<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Studiogau\Chandra\Support\SessionHelper;

require_once __DIR__ . '/../vendor/autoload.php';

final class SessionHelperExtendedTest extends TestCase
{
    private array $backupSession = [];
    private array $backupServer = [];
    private array $backupCookies = [];

    protected function setUp(): void
    {
        parent::setUp();

        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $this->backupSession = $_SESSION ?? [];
        $this->backupServer = $_SERVER;
        $this->backupCookies = $_COOKIE ?? [];

        $_SESSION = [];
        $_COOKIE = [];
        $_SERVER['SCRIPT_NAME'] = '/chandra/index.php';
    }

    protected function tearDown(): void
    {
        $_SESSION = $this->backupSession;
        $_SERVER = $this->backupServer;
        $_COOKIE = $this->backupCookies;
        parent::tearDown();
    }

    /**
     * 目的: structデータの登録・取得・削除・全削除が一連で動作することを確認する。
     * 期待: getDataが登録値を返し、削除後は null になる。
     */
    public function testStructDataLifecycle(): void
    {
        SessionHelper::setData('func', 'key', 'value');
        $this->assertSame('value', SessionHelper::getData('func', 'key'));

        SessionHelper::delData('func', 'key');
        $this->assertNull(SessionHelper::getData('func', 'key'));

        SessionHelper::setData('func', 'key', 'value');
        SessionHelper::clearData();
        $this->assertNull(SessionHelper::getData('func', 'key'));
    }

    /**
     * 目的: SCRIPT_NAME に基づくプロジェクトプレフィックスが分離されることを確認する。
     * 期待: 別プロジェクト名に切り替えると同じキーの値が取得できない。
     */
    public function testProjectPrefixIsolationBetweenApps(): void
    {
        SessionHelper::setPref('theme', 'dark');
        $this->assertSame('dark', SessionHelper::getPref('theme'));

        $_SERVER['SCRIPT_NAME'] = '/other/index.php';
        $this->assertNull(SessionHelper::getPref('theme'));

        $this->assertArrayHasKey('chandra' . SessionHelper::PREF_SESSION_KEY, $_SESSION);
    }

    /**
     * 目的: masterデータのセット/取得/リスト取得が正しく動作することを確認する。
     * 期待: getMaster と getMasterList が期待した値を返す。
     */
    public function testMasterDataAccessors(): void
    {
        SessionHelper::setMaster(['roles' => ['admin', 'user']]);

        $this->assertSame(['roles' => ['admin', 'user']], SessionHelper::getMaster());
        $this->assertSame(['admin', 'user'], SessionHelper::getMasterList('roles'));
        $this->assertNull(SessionHelper::getMasterList('missing'));
    }

    /**
     * invalidateSession で現在のセッション内容と Cookie 参照が消えることを確認する。
     */
    public function testInvalidateSessionClearsSessionAndCookie(): void
    {
        SessionHelper::setPref('theme', 'dark');
        $_COOKIE[session_name()] = session_id();

        SessionHelper::invalidateSession();

        $this->assertSame([], $_SESSION);
        $this->assertArrayNotHasKey(session_name(), $_COOKIE);
        $this->assertSame(PHP_SESSION_NONE, session_status());
    }

    /**
     * restart=true の場合は、破棄後に新しいセッションが開始されることを確認する。
     */
    public function testInvalidateSessionCanRestartWithFreshSessionId(): void
    {
        $beforeSessionId = session_id();
        SessionHelper::setPref('theme', 'dark');
        $_COOKIE[session_name()] = $beforeSessionId;

        SessionHelper::invalidateSession(true);

        $this->assertSame(PHP_SESSION_ACTIVE, session_status());
        $this->assertSame([], $_SESSION);
        $this->assertArrayNotHasKey(session_name(), $_COOKIE);
        $this->assertNotSame('', session_id());
        $this->assertNotSame($beforeSessionId, session_id());
    }

    /**
     * session_write_close() 済みでも、既存参照があれば invalidateSession が破棄を完了できることを確認する。
     */
    public function testInvalidateSessionHandlesClosedSessionReference(): void
    {
        SessionHelper::setPref('theme', 'dark');
        $_COOKIE[session_name()] = session_id();

        session_write_close();

        $this->assertSame(PHP_SESSION_NONE, session_status());

        SessionHelper::invalidateSession();

        $this->assertSame([], $_SESSION);
        $this->assertArrayNotHasKey(session_name(), $_COOKIE);
        $this->assertSame(PHP_SESSION_NONE, session_status());
    }
}

