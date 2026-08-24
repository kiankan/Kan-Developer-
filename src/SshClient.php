<?php

declare(strict_types=1);

final class SshError extends RuntimeException
{
}

/**
 * اجرای واقعی دستور شل روی سرور هاست، از طریق SSH (نه از طریق cPanel API —
 * cPanel هیچ endpoint سبکی برای «اجرای یک دستور و گرفتن خروجی» نداره؛
 * ترمینال واقعی‌ش یک اتصال WebSocket تعاملیه که برای این ربات مناسب نیست).
 *
 * فقط با یوزرنیم/رمز کار می‌کنه، نه API Token — چون توکن‌های cPanel برای
 * ورود SSH معتبر نیستن. همچنین به فعال‌بودن SSH روی اون اکانت هاست نیاز داره.
 */
final class SshClient
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $password,
    ) {
    }

    public static function isAvailable(): bool
    {
        return class_exists(\phpseclib3\Net\SSH2::class);
    }

    /**
     * @return array{stdout: string, stderr: string, exit_status: int|null, timed_out: bool}
     */
    public function exec(string $command, int $timeoutSeconds = 20): array
    {
        if (!self::isAvailable()) {
            throw new SshError('کتابخانه‌ی SSH نصب نیست. روی سرور ربات دستور «composer install» را اجرا کنید.');
        }

        $ssh = new \phpseclib3\Net\SSH2($this->host, $this->port);
        $ssh->setTimeout($timeoutSeconds);

        try {
            $loggedIn = $ssh->login($this->username, $this->password);
        } catch (\Throwable $e) {
            throw new SshError('اتصال SSH برقرار نشد: ' . $e->getMessage());
        }
        if (!$loggedIn) {
            throw new SshError('ورود SSH ناموفق بود. یوزرنیم/رمز رو چک کنید، یا شاید SSH برای این اکانت روی هاست فعال نیست.');
        }

        $stdout = (string) $ssh->exec($command);
        $stderr = (string) $ssh->getStdError();
        $timedOut = $ssh->isTimeout();
        $exitStatus = $ssh->getExitStatus();

        return [
            'stdout' => $stdout,
            'stderr' => $stderr,
            'exit_status' => is_int($exitStatus) ? $exitStatus : null,
            'timed_out' => $timedOut,
        ];
    }
}
