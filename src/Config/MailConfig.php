<?php

namespace Studiogau\Chandra\Config;

use RuntimeException;

final class MailConfig
{
    public const CONFIG_SOURCE_ENV = 'CHANDRA_MAIL_SOURCE';

    /** @var array<string, string> */
    public const DEFAULT_ENV_MAP = array(
        'smtp_host' => 'MAIL_SMTP_HOST',
        'smtp_port' => 'MAIL_SMTP_PORT',
        'smtp_auth' => 'MAIL_SMTP_AUTH',
        'smtp_user' => 'MAIL_SMTP_USER',
        'smtp_pass' => 'MAIL_SMTP_PASS',
        'smtp_secure' => 'MAIL_SMTP_SECURE',
        'mail_from' => 'MAIL_FROM',
    );

    private const DEFAULT_SMTP_PORT = 587;
    private const DEFAULT_SMTP_AUTH = true;
    private const DEFAULT_SMTP_SECURE = 'tls';

    private string $smtpHost;
    private int $smtpPort;
    private bool $smtpAuth;
    private string $smtpUser;
    private string $smtpPass;
    private string $smtpSecure;
    private string $mailFrom;

    public function __construct(
        string $smtpHost,
        int $smtpPort,
        bool $smtpAuth,
        string $smtpUser,
        string $smtpPass,
        string $smtpSecure,
        string $mailFrom
    ) {
        $this->smtpHost = $smtpHost;
        $this->smtpPort = $smtpPort;
        $this->smtpAuth = $smtpAuth;
        $this->smtpUser = $smtpUser;
        $this->smtpPass = $smtpPass;
        $this->smtpSecure = $smtpSecure;
        $this->mailFrom = $mailFrom;
    }

    public static function fromIni(string $path): self
    {
        $config = parse_ini_file($path, false, INI_SCANNER_TYPED);
        if ($config === false) {
            throw new RuntimeException('Unable to load mail config file: ' . $path);
        }

        return self::fromArray($config, 'mail config file');
    }

    /**
     * @param array<string, string> $envMap
     */
    public static function fromEnv(array $envMap = array()): self
    {
        $map = array_replace(self::DEFAULT_ENV_MAP, $envMap);

        $config = array();
        foreach ($map as $key => $envName) {
            $value = self::getEnvValue($envName);
            if ($value !== null) {
                $config[$key] = $value;
            }
        }

        return self::fromArray($config, 'environment variables');
    }

    /**
     * @param array<string, mixed> $options
     */
    public static function fromConfiguredSource(string $path, array $options = array()): self
    {
        $switchEnvName = (string) ($options['switch_env_name'] ?? self::CONFIG_SOURCE_ENV);
        $defaultSource = strtolower(trim((string) ($options['default_source'] ?? 'ini')));
        $source = self::getEnvValue($switchEnvName);
        $source = strtolower(trim($source ?? $defaultSource));

        if ($source === '' || $source === 'ini') {
            return self::fromIni($path);
        }

        if ($source === 'env') {
            $envMap = $options['env_map'] ?? array();
            if (!is_array($envMap)) {
                throw new RuntimeException('env_map must be an array.');
            }

            return self::fromEnv($envMap);
        }

        throw new RuntimeException('Unsupported mail config source: ' . $source);
    }

    public function getSmtpHost(): string
    {
        return $this->smtpHost;
    }

    public function getSmtpPort(): int
    {
        return $this->smtpPort;
    }

    public function isSmtpAuth(): bool
    {
        return $this->smtpAuth;
    }

    public function getSmtpUser(): string
    {
        return $this->smtpUser;
    }

    public function getSmtpPass(): string
    {
        return $this->smtpPass;
    }

    public function getSmtpSecure(): string
    {
        return $this->smtpSecure;
    }

    public function getMailFrom(): string
    {
        return $this->mailFrom;
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function fromArray(array $config, string $sourceLabel): self
    {
        $smtpHost = self::requireString($config, 'smtp_host', $sourceLabel);
        $smtpPort = self::normalizePort($config['smtp_port'] ?? self::DEFAULT_SMTP_PORT, $sourceLabel);
        $smtpAuth = self::normalizeBool($config['smtp_auth'] ?? self::DEFAULT_SMTP_AUTH, 'smtp_auth', $sourceLabel);
        $smtpSecure = trim((string) ($config['smtp_secure'] ?? self::DEFAULT_SMTP_SECURE));
        $mailFrom = self::requireString($config, 'mail_from', $sourceLabel);

        $smtpUser = trim((string) ($config['smtp_user'] ?? ''));
        $smtpPass = (string) ($config['smtp_pass'] ?? '');
        if ($smtpAuth) {
            if ($smtpUser === '') {
                throw new RuntimeException('Missing required key in ' . $sourceLabel . ': smtp_user');
            }

            if ($smtpPass === '') {
                throw new RuntimeException('Missing required key in ' . $sourceLabel . ': smtp_pass');
            }
        }

        return new self($smtpHost, $smtpPort, $smtpAuth, $smtpUser, $smtpPass, $smtpSecure, $mailFrom);
    }

    /**
     * @param array<string, mixed> $config
     */
    private static function requireString(array $config, string $key, string $sourceLabel): string
    {
        if (!array_key_exists($key, $config)) {
            throw new RuntimeException('Missing required key in ' . $sourceLabel . ': ' . $key);
        }

        $value = trim((string) $config[$key]);
        if ($value === '') {
            throw new RuntimeException('Empty required key in ' . $sourceLabel . ': ' . $key);
        }

        return $value;
    }

    /**
     * @param mixed $value
     */
    private static function normalizePort($value, string $sourceLabel): int
    {
        if (is_int($value)) {
            return $value;
        }

        $stringValue = trim((string) $value);
        if ($stringValue === '' || !ctype_digit($stringValue)) {
            throw new RuntimeException('Invalid smtp_port in ' . $sourceLabel . '.');
        }

        return (int) $stringValue;
    }

    /**
     * @param mixed $value
     */
    private static function normalizeBool($value, string $key, string $sourceLabel): bool
    {
        if (is_bool($value)) {
            return $value;
        }

        $normalized = strtolower(trim((string) $value));
        if (in_array($normalized, array('1', 'true', 'on', 'yes'), true)) {
            return true;
        }

        if (in_array($normalized, array('0', 'false', 'off', 'no'), true)) {
            return false;
        }

        throw new RuntimeException('Invalid boolean value for ' . $key . ' in ' . $sourceLabel . '.');
    }

    private static function getEnvValue(string $envName): ?string
    {
        if ($envName === '') {
            return null;
        }

        $value = getenv($envName);
        if ($value === false) {
            return null;
        }

        return (string) $value;
    }
}
