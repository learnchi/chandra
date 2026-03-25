<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use Studiogau\Chandra\Config\MailConfig;

require_once __DIR__ . '/../vendor/autoload.php';

final class MailConfigTest extends TestCase
{
    /**
     * INIファイルに定義したSMTP設定を正しく読み込めることを確認する。
     */
    public function testFromIniLoadsConfiguredValues(): void
    {
        $iniPath = tempnam(sys_get_temp_dir(), 'mail-config-ini');
        file_put_contents(
            $iniPath,
            implode("\n", [
                'smtp_host = smtp.example.com',
                'smtp_port = 2525',
                'smtp_auth = true',
                'smtp_user = sender@example.com',
                'smtp_pass = secret',
                'smtp_secure = ssl',
                'mail_from = no-reply@example.com',
                '',
            ])
        );

        try {
            $config = MailConfig::fromIni($iniPath);
        } finally {
            @unlink($iniPath);
        }

        $this->assertSame('smtp.example.com', $config->getSmtpHost());
        $this->assertSame(2525, $config->getSmtpPort());
        $this->assertTrue($config->isSmtpAuth());
        $this->assertSame('sender@example.com', $config->getSmtpUser());
        $this->assertSame('secret', $config->getSmtpPass());
        $this->assertSame('ssl', $config->getSmtpSecure());
        $this->assertSame('no-reply@example.com', $config->getMailFrom());
    }

    /**
     * 環境変数から設定を読み込む際、未指定項目に既定値が使われることを確認する。
     */
    public function testFromEnvAppliesDefaultsWhenAuthDisabled(): void
    {
        $this->withTemporaryEnv([
            'MAIL_SMTP_HOST' => 'smtp.env.example.com',
            'MAIL_SMTP_PORT' => null,
            'MAIL_SMTP_AUTH' => 'false',
            'MAIL_SMTP_USER' => null,
            'MAIL_SMTP_PASS' => null,
            'MAIL_SMTP_SECURE' => null,
            'MAIL_FROM' => 'system@example.com',
        ], function (): void {
            $config = MailConfig::fromEnv();

            $this->assertSame('smtp.env.example.com', $config->getSmtpHost());
            $this->assertSame(587, $config->getSmtpPort());
            $this->assertFalse($config->isSmtpAuth());
            $this->assertSame('', $config->getSmtpUser());
            $this->assertSame('', $config->getSmtpPass());
            $this->assertSame('tls', $config->getSmtpSecure());
            $this->assertSame('system@example.com', $config->getMailFrom());
        });
    }

    /**
     * SMTP認証が有効な場合、ユーザー名が未設定だと例外になることを確認する。
     */
    public function testFromEnvThrowsWhenAuthCredentialsAreMissing(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Missing required key in environment variables: smtp_user');

        $this->withTemporaryEnv([
            'MAIL_SMTP_HOST' => 'smtp.env.example.com',
            'MAIL_SMTP_PORT' => '587',
            'MAIL_SMTP_AUTH' => 'true',
            'MAIL_SMTP_USER' => null,
            'MAIL_SMTP_PASS' => null,
            'MAIL_SMTP_SECURE' => 'tls',
            'MAIL_FROM' => 'system@example.com',
        ], static function (): void {
            MailConfig::fromEnv();
        });
    }

    /**
     * 設定ソース切替用の環境変数がenvなら、環境変数から設定を取得することを確認する。
     */
    public function testFromConfiguredSourceUsesEnvWhenSwitched(): void
    {
        $this->withTemporaryEnv([
            MailConfig::CONFIG_SOURCE_ENV => 'env',
            'MAIL_SMTP_HOST' => 'smtp.switch.example.com',
            'MAIL_SMTP_PORT' => '465',
            'MAIL_SMTP_AUTH' => 'yes',
            'MAIL_SMTP_USER' => 'switch-user',
            'MAIL_SMTP_PASS' => 'switch-pass',
            'MAIL_SMTP_SECURE' => 'ssl',
            'MAIL_FROM' => 'switch@example.com',
        ], function (): void {
            $config = MailConfig::fromConfiguredSource(__FILE__);

            $this->assertSame('smtp.switch.example.com', $config->getSmtpHost());
            $this->assertSame(465, $config->getSmtpPort());
            $this->assertTrue($config->isSmtpAuth());
            $this->assertSame('switch-user', $config->getSmtpUser());
            $this->assertSame('switch-pass', $config->getSmtpPass());
            $this->assertSame('ssl', $config->getSmtpSecure());
            $this->assertSame('switch@example.com', $config->getMailFrom());
        });
    }

    /**
     * 未対応の設定ソースが指定された場合、例外になることを確認する。
     */
    public function testFromConfiguredSourceThrowsWhenSourceUnsupported(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Unsupported mail config source: invalid');

        $this->withTemporaryEnv([
            MailConfig::CONFIG_SOURCE_ENV => 'invalid',
        ], static function (): void {
            MailConfig::fromConfiguredSource(__FILE__);
        });
    }

    /**
     * env_map に配列以外を渡した場合、例外になることを確認する。
     */
    public function testFromConfiguredSourceThrowsWhenEnvMapIsInvalid(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('env_map must be an array.');

        $this->withTemporaryEnv([
            MailConfig::CONFIG_SOURCE_ENV => 'env',
        ], static function (): void {
            MailConfig::fromConfiguredSource(__FILE__, ['env_map' => 'invalid']);
        });
    }

    /**
     * INIファイルのsmtp_portが数値でない場合、例外になることを確認する。
     */
    public function testFromIniThrowsWhenPortIsInvalid(): void
    {
        $iniPath = tempnam(sys_get_temp_dir(), 'mail-config-invalid-port');
        file_put_contents(
            $iniPath,
            implode("\n", [
                'smtp_host = smtp.example.com',
                'smtp_port = invalid',
                'mail_from = no-reply@example.com',
                '',
            ])
        );

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Invalid smtp_port in mail config file.');

        try {
            MailConfig::fromIni($iniPath);
        } finally {
            @unlink($iniPath);
        }
    }

    /**
     * @param array<string, string|null> $values
     * @param callable                   $callback
     */
    private function withTemporaryEnv(array $values, callable $callback): void
    {
        $previous = [];
        foreach ($values as $name => $value) {
            $current = getenv($name);
            $previous[$name] = ($current === false) ? null : (string) $current;

            if ($value === null) {
                putenv($name);
            } else {
                putenv($name . '=' . $value);
            }
        }

        try {
            $callback();
        } finally {
            foreach ($previous as $name => $value) {
                if ($value === null) {
                    putenv($name);
                } else {
                    putenv($name . '=' . $value);
                }
            }
        }
    }
}
