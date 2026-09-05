<?php

declare(strict_types=1);

/**
 * اجرای دستور روی یک سرور لینوکسی از راه SSH.
 *
 * برخلاف بقیه‌ی بخش‌ها که با API سی‌پنل کار می‌کنند، اینجا مستقیم به خود سرور
 * وصل می‌شویم — با آی‌پی و رمز (یا کلید). هیچ کتابخانه‌ی PHPی لازم نیست؛ از
 * خود دستور <code>ssh</code> سیستم استفاده می‌شود و برای احراز هویت با رمز،
 * از <code>sshpass</code>.
 *
 * نکته‌های امنیتی که رعایت شده‌اند:
 *   - دستور با proc_open و آرایه‌ی آرگومان اجرا می‌شود، نه رشته‌ی shell؛ پس
 *     آدرس و نام کاربری هیچ‌وقت به shell محلی تفسیر نمی‌شوند.
 *   - رمز از متغیر محیطی SSHPASS می‌رود، نه از خط فرمان؛ وگرنه در ps دیده
 *     می‌شد.
 *   - چیزهایی مثل رمز کاربر که نباید در فهرست پردازش‌های سرور مقصد پیدا شوند،
 *     از ورودی استاندارد فرستاده می‌شوند.
 */
final class SshClient
{
    public const TYPE = 'ssh';

    private const CONNECT_TIMEOUT = 12;

    /**
     * اجراکننده‌ی جایگزین — فقط تست‌ها ست می‌کنند تا بدون سرور واقعی هم بشود
     * همه‌ی مسیرها را اجرا کرد.
     *
     * @var callable|null
     */
    private static $defaultRunner = null;

    /** @var callable|null */
    private $runner;

    /** @param array<string, mixed> $server */
    public function __construct(private array $server, ?callable $runner = null)
    {
        if ($this->host() === '') {
            throw CpanelError::config('آدرس یا آی‌پی سرور خالی است.');
        }
        if ($this->user() === '') {
            throw CpanelError::config('نام کاربری SSH خالی است (معمولاً <code>root</code>).');
        }
        if ($this->password() === '' && $this->keyPath() === '') {
            throw CpanelError::config('برای این سرور نه رمز ثبت شده و نه کلید؛ یکی را تنظیم کنید.');
        }

        $this->runner = $runner;
    }

    public static function setDefaultRunner(?callable $runner): void
    {
        self::$defaultRunner = $runner;
    }

    public function label(): string
    {
        $label = trim((string) ($this->server['label'] ?? ''));

        return $label !== '' ? $label : $this->host();
    }

    public function host(): string
    {
        return Address::host((string) ($this->server['host'] ?? ''));
    }

    public function port(): int
    {
        $port = (int) ($this->server['port'] ?? 0);

        return $port > 0 ? $port : 22;
    }

    public function user(): string
    {
        return trim((string) ($this->server['user'] ?? 'root'));
    }

    private function password(): string
    {
        return (string) ($this->server['password'] ?? '');
    }

    private function keyPath(): string
    {
        return trim((string) ($this->server['key'] ?? ''));
    }

    /**
     * اجرای یک دستور روی سرور.
     *
     * @return array{code: int, out: string, err: string}
     */
    public function run(string $command, int $timeout = 45, string $stdin = ''): array
    {
        $runner = $this->runner ?? self::$defaultRunner;
        if ($runner !== null) {
            $result = $runner($command, $this->server, $stdin);

            return [
                'code' => (int) ($result['code'] ?? 0),
                'out' => (string) ($result['out'] ?? ''),
                'err' => (string) ($result['err'] ?? ''),
            ];
        }

        return $this->execute($command, $timeout, $stdin);
    }

    /**
     * مثل run، ولی اگر دستور شکست بخورد خطا می‌دهد.
     */
    public function runOrFail(string $command, int $timeout = 45, string $stdin = ''): string
    {
        $result = $this->run($command, $timeout, $stdin);
        if ($result['code'] !== 0) {
            $message = trim($result['err']) !== '' ? trim($result['err']) : trim($result['out']);

            throw CpanelError::api(
                'دستور روی سرور با خطا تمام شد (کد ' . $result['code'] . ')'
                . ($message !== '' ? ': ' . mb_substr($message, 0, 300) : '.')
            );
        }

        return $result['out'];
    }

    /**
     * تست اتصال: چه کاربری هستیم و سرور چقدر بالا بوده.
     *
     * @return array{user: string, host: string, uptime: string}
     */
    public function ping(): array
    {
        $out = $this->runOrFail('id -un; hostname; uptime', 20);
        $lines = array_values(array_filter(array_map('trim', explode("\n", $out)), static fn (string $l): bool => $l !== ''));

        return [
            'user' => $lines[0] ?? '',
            'host' => $lines[1] ?? '',
            'uptime' => $lines[2] ?? '',
        ];
    }

    /**
     * آیا ابزارهای لازم روی این ماشین هست؟
     *
     * @return array{ok: bool, message: string}
     */
    public static function available(bool $withPassword): array
    {
        if (self::$defaultRunner !== null) {
            return ['ok' => true, 'message' => ''];
        }
        if (self::which('ssh') === '') {
            return ['ok' => false, 'message' =>
                "دستور <code>ssh</code> روی سرور ربات نصب نیست.\n\n"
                . 'نصبش کنید: <code>apt install -y openssh-client</code> (یا <code>yum install -y openssh-clients</code>)'];
        }
        if ($withPassword && self::which('sshpass') === '') {
            return ['ok' => false, 'message' =>
                "برای وصل‌شدن با رمز، ابزار <code>sshpass</code> لازم است و روی سرور ربات نصب نیست.\n\n"
                . "نصبش کنید: <code>apt install -y sshpass</code> (یا <code>yum install -y sshpass</code>)\n\n"
                . '💡 اگر به‌جای رمز از کلید SSH استفاده کنید، به این ابزار نیازی نیست.'];
        }

        return ['ok' => true, 'message' => ''];
    }

    private static function which(string $binary): string
    {
        foreach (explode(':', (string) (getenv('PATH') ?: '/usr/bin:/bin:/usr/local/bin')) as $dir) {
            $path = rtrim($dir, '/') . '/' . $binary;
            if (is_file($path) && is_executable($path)) {
                return $path;
            }
        }

        return '';
    }

    /**
     * @return array{code: int, out: string, err: string}
     */
    private function execute(string $command, int $timeout, string $stdin): array
    {
        $password = $this->password();
        $check = self::available($password !== '');
        if (!$check['ok']) {
            throw CpanelError::config($check['message']);
        }

        $argv = $this->buildArgv($command, $password !== '');
        $env = [
            'PATH' => (string) (getenv('PATH') ?: '/usr/local/sbin:/usr/local/bin:/usr/sbin:/usr/bin:/sbin:/bin'),
            'HOME' => (string) (getenv('HOME') ?: '/root'),
            'LANG' => 'C',
            'LC_ALL' => 'C',
        ];
        if ($password !== '') {
            // sshpass رمز را از همین متغیر می‌خواند، پس در خط فرمان دیده نمی‌شود.
            $env['SSHPASS'] = $password;
        }

        $descriptors = [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']];
        $process = @proc_open($argv, $descriptors, $pipes, null, $env);
        if (!is_resource($process)) {
            throw CpanelError::transport('اجرای ssh ممکن نشد.');
        }

        if ($stdin !== '') {
            fwrite($pipes[0], $stdin);
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        $out = '';
        $err = '';
        $deadline = microtime(true) + max(5, $timeout);
        $timedOut = false;

        while (true) {
            $out .= (string) stream_get_contents($pipes[1]);
            $err .= (string) stream_get_contents($pipes[2]);

            $status = proc_get_status($process);
            if (!$status['running']) {
                break;
            }
            if (microtime(true) > $deadline) {
                $timedOut = true;
                proc_terminate($process, 9);
                break;
            }
            usleep(50_000);
        }

        $out .= (string) stream_get_contents($pipes[1]);
        $err .= (string) stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);

        if ($timedOut) {
            throw CpanelError::transport("دستور بیشتر از {$timeout} ثانیه طول کشید و متوقف شد.");
        }
        if ($code === 255 && trim($out) === '') {
            // ۲۵۵ یعنی خود ssh وصل نشد (نه اینکه دستور خطا داده باشد).
            $reason = trim($err);

            throw new CpanelError(
                'اتصال SSH برقرار نشد' . ($reason !== '' ? ': ' . mb_substr($reason, 0, 200) : '.')
                . "\n\n💡 آی‌پی، پورت، نام کاربری و رمز را بررسی کنید و مطمئن شوید فایروال سرور"
                . ' به آی‌پی همین ماشین اجازه‌ی SSH می‌دهد.',
                CpanelError::KIND_AUTH,
                255,
            );
        }

        return ['code' => $code, 'out' => $out, 'err' => $err];
    }

    /**
     * @return array<int, string>
     */
    private function buildArgv(string $command, bool $withPassword): array
    {
        $options = [
            '-p', (string) $this->port(),
            '-o', 'ConnectTimeout=' . self::CONNECT_TIMEOUT,
            '-o', 'StrictHostKeyChecking=accept-new',
            '-o', 'UserKnownHostsFile=' . self::knownHostsPath(),
            '-o', 'NumberOfPasswordPrompts=1',
            '-o', 'LogLevel=ERROR',
        ];

        if ($withPassword) {
            // با رمز، کلیدها را امتحان نمی‌کنیم تا درخواست رمز واقعاً برسد.
            $options[] = '-o';
            $options[] = 'PubkeyAuthentication=no';
            $options[] = '-o';
            $options[] = 'PreferredAuthentications=password,keyboard-interactive';
        } else {
            // بدون رمز، هیچ‌وقت نباید منتظر ورودی بماند.
            $options[] = '-o';
            $options[] = 'BatchMode=yes';
            if ($this->keyPath() !== '') {
                $options[] = '-i';
                $options[] = $this->keyPath();
            }
        }

        $argv = array_merge(['ssh'], $options, [$this->user() . '@' . $this->host(), $command]);

        return $withPassword ? array_merge(['sshpass', '-e'], $argv) : $argv;
    }

    private static function knownHostsPath(): string
    {
        $path = Paths::data('known_hosts');
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        if (!is_file($path)) {
            @touch($path);
            @chmod($path, 0600);
        }

        return $path;
    }

    /**
     * نقل‌قول امن برای وقتی که یک مقدار باید داخل دستورِ سمت سرور بنشیند.
     */
    public static function quote(string $value): string
    {
        return "'" . str_replace("'", "'\\''", $value) . "'";
    }
}
