<?php

declare(strict_types=1);

/**
 * کارهای واقعی روی یک سرور لینوکسی: خواندن وضعیت، فشار، سرویس‌ها، کاربران و
 * انجام کارهای مدیریتی.
 *
 * هر «صفحه» با یک اتصال SSH گرفته می‌شود، نه چند تا: دستورها با نشانه‌گذار
 * کنار هم فرستاده می‌شوند و خروجی همان‌جا تکه‌تکه و تفسیر می‌شود. اینطوری هم
 * سریع‌تر است و هم روی سرورهای دور کمتر معطلی دارد.
 */
final class SshService
{
    private const MARK = '###TEAMKAN:';

    /** سرویس‌هایی که معمولاً روی یک سرور وب مهم‌اند. */
    public const SERVICES = [
        'nginx', 'apache2', 'httpd', 'mysql', 'mariadb', 'mysqld',
        'php-fpm', 'redis', 'redis-server', 'memcached', 'docker',
        'sshd', 'ssh', 'cron', 'crond', 'postfix', 'exim', 'cpanel',
    ];

    /**
     * وضعیت کامل سرور در یک نگاه.
     *
     * @return array{hostname: string, os: string, kernel: string, uptime: string, load: array<int, float>, cpu: array{cores: int, model: string, percent: ?float}, memory: array{total: float, used: float, percent: ?float}, swap: array{total: float, used: float}, disks: array<int, array{mount: string, size: float, used: float, percent: ?float}>, ips: array<int, string>, processes: int}
     */
    public static function facts(SshClient $client): array
    {
        $out = $client->runOrFail(self::script([
            'host' => 'hostname -f 2>/dev/null || hostname',
            'os' => 'cat /etc/os-release 2>/dev/null | head -12',
            'kernel' => 'uname -r',
            'uptime' => 'cat /proc/uptime',
            'load' => 'cat /proc/loadavg',
            'cores' => 'nproc 2>/dev/null || grep -c ^processor /proc/cpuinfo',
            'cpu' => 'grep -m1 "model name" /proc/cpuinfo | cut -d: -f2',
            'mem' => 'head -6 /proc/meminfo',
            'disk' => 'df -P -k -x tmpfs -x devtmpfs 2>/dev/null || df -P -k',
            'ip' => 'hostname -I 2>/dev/null || ip -4 -o addr show 2>/dev/null | awk \'{print $4}\'',
            'ps' => 'ps -e --no-headers 2>/dev/null | wc -l',
        ]), 60);

        $parts = self::split($out);
        $meminfo = self::keyValues($parts['mem'] ?? '', ':');
        $osRelease = self::keyValues($parts['os'] ?? '', '=');

        $memTotal = (float) ($meminfo['MemTotal'] ?? 0) * 1024;
        $memAvailable = (float) ($meminfo['MemAvailable'] ?? ($meminfo['MemFree'] ?? 0)) * 1024;
        $swapTotal = (float) ($meminfo['SwapTotal'] ?? 0) * 1024;
        $swapFree = (float) ($meminfo['SwapFree'] ?? 0) * 1024;

        $load = self::loadNumbers($parts['load'] ?? '');
        $cores = max(1, (int) trim($parts['cores'] ?? '1'));

        return [
            'hostname' => trim($parts['host'] ?? ''),
            'os' => trim((string) ($osRelease['PRETTY_NAME'] ?? ($osRelease['NAME'] ?? '')), " \"'"),
            'kernel' => trim($parts['kernel'] ?? ''),
            'uptime' => self::uptimeText($parts['uptime'] ?? ''),
            'load' => $load,
            'cpu' => [
                'cores' => $cores,
                'model' => trim($parts['cpu'] ?? ''),
                'percent' => $load === [] ? null : min(100.0, $load[0] / $cores * 100),
            ],
            'memory' => [
                'total' => $memTotal,
                'used' => max(0.0, $memTotal - $memAvailable),
                'percent' => $memTotal > 0 ? ($memTotal - $memAvailable) / $memTotal * 100 : null,
            ],
            'swap' => ['total' => $swapTotal, 'used' => max(0.0, $swapTotal - $swapFree)],
            'disks' => self::disks($parts['disk'] ?? ''),
            'ips' => self::ips($parts['ip'] ?? ''),
            'processes' => (int) trim($parts['ps'] ?? '0'),
        ];
    }

    /**
     * فشار لحظه‌ای: بار سیستم، حافظه و سنگین‌ترین پردازش‌ها.
     *
     * @return array{load: array<int, float>, cores: int, memory: array{total: float, used: float, percent: ?float}, cpu_top: array<int, array{cpu: float, mem: float, pid: int, name: string}>, mem_top: array<int, array{cpu: float, mem: float, pid: int, name: string}>, io: string}
     */
    public static function pressure(SshClient $client): array
    {
        $out = $client->runOrFail(self::script([
            'load' => 'cat /proc/loadavg',
            'cores' => 'nproc 2>/dev/null || grep -c ^processor /proc/cpuinfo',
            'mem' => 'head -3 /proc/meminfo',
            'cpu_top' => 'ps -eo pcpu,pmem,pid,comm --sort=-pcpu 2>/dev/null | head -9',
            'mem_top' => 'ps -eo pcpu,pmem,pid,comm --sort=-pmem 2>/dev/null | head -9',
            'io' => 'cat /proc/pressure/io 2>/dev/null | head -2',
        ]), 45);

        $parts = self::split($out);
        $meminfo = self::keyValues($parts['mem'] ?? '', ':');
        $memTotal = (float) ($meminfo['MemTotal'] ?? 0) * 1024;
        $memAvailable = (float) ($meminfo['MemAvailable'] ?? ($meminfo['MemFree'] ?? 0)) * 1024;

        return [
            'load' => self::loadNumbers($parts['load'] ?? ''),
            'cores' => max(1, (int) trim($parts['cores'] ?? '1')),
            'memory' => [
                'total' => $memTotal,
                'used' => max(0.0, $memTotal - $memAvailable),
                'percent' => $memTotal > 0 ? ($memTotal - $memAvailable) / $memTotal * 100 : null,
            ],
            'cpu_top' => self::processes($parts['cpu_top'] ?? ''),
            'mem_top' => self::processes($parts['mem_top'] ?? ''),
            'io' => trim($parts['io'] ?? ''),
        ];
    }

    /**
     * وضعیت سرویس‌های مهم و سرویس‌های خراب.
     *
     * @return array{known: array<int, array{name: string, state: string}>, failed: array<int, string>, systemd: bool}
     */
    public static function services(SshClient $client): array
    {
        $checks = [];
        foreach (self::SERVICES as $service) {
            $checks[] = 'printf "%s %s\\n" ' . SshClient::quote($service)
                . ' "$(systemctl is-active ' . SshClient::quote($service) . ' 2>/dev/null || echo missing)"';
        }

        $out = $client->runOrFail(self::script([
            'systemd' => 'command -v systemctl >/dev/null 2>&1 && echo yes || echo no',
            'known' => implode('; ', $checks),
            'failed' => 'systemctl list-units --state=failed --no-legend --no-pager 2>/dev/null | head -10',
        ]), 60);

        $parts = self::split($out);
        $known = [];
        foreach (explode("\n", trim($parts['known'] ?? '')) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            [$name, $state] = array_pad(preg_split('/\s+/', $line, 2) ?: [], 2, '');
            $state = trim((string) $state);
            // سرویسی که اصلاً روی سرور نیست را نشان نمی‌دهیم تا فهرست شلوغ نشود.
            if ($state === '' || $state === 'missing') {
                continue;
            }
            $known[] = ['name' => (string) $name, 'state' => $state];
        }

        $failed = [];
        foreach (explode("\n", trim($parts['failed'] ?? '')) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $failed[] = (string) (preg_split('/\s+/', ltrim($line, '● ')) ?: [''])[0];
            }
        }

        return [
            'known' => $known,
            'failed' => array_values(array_filter($failed)),
            'systemd' => trim($parts['systemd'] ?? '') === 'yes',
        ];
    }

    /**
     * پورت‌هایی که سرور روی آن‌ها گوش می‌دهد.
     *
     * @return array<int, array{proto: string, address: string, port: string, process: string}>
     */
    public static function ports(SshClient $client): array
    {
        $out = $client->runOrFail(
            'ss -tulpnH 2>/dev/null || netstat -tulpn 2>/dev/null | tail -n +3',
            45,
        );

        $rows = [];
        foreach (explode("\n", trim($out)) as $line) {
            $line = trim($line);
            if ($line === '') {
                continue;
            }
            $columns = preg_split('/\s+/', $line) ?: [];
            $proto = strtolower((string) ($columns[0] ?? ''));
            if (!str_starts_with($proto, 'tcp') && !str_starts_with($proto, 'udp')) {
                continue;
            }

            $local = '';
            foreach ($columns as $column) {
                if (preg_match('/^(.*):(\d+|\*)$/', $column, $match) === 1 && $match[2] !== '') {
                    $local = $column;
                    break;
                }
            }
            if ($local === '') {
                continue;
            }
            $position = strrpos($local, ':');
            $process = '';
            if (preg_match('/(?:users:\(\("|\/)([a-zA-Z0-9_.-]+)/', $line, $match) === 1) {
                $process = $match[1];
            }

            $rows[] = [
                'proto' => $proto,
                'address' => $position === false ? $local : substr($local, 0, $position),
                'port' => $position === false ? '' : substr($local, $position + 1),
                'process' => $process,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => (int) $a['port'] <=> (int) $b['port']);

        return $rows;
    }

    /**
     * کاربران سیستم، وضعیت قفل رمزشان و کسانی که همین حالا وصل‌اند.
     *
     * @return array{users: array<int, array{name: string, uid: int, home: string, shell: string, locked: ?bool}>, online: array<int, string>, last: array<int, string>}
     */
    public static function users(SshClient $client): array
    {
        $out = $client->runOrFail(self::script([
            'passwd' => 'awk -F: \'$3==0 || $3>=1000 {print $1":"$3":"$6":"$7}\' /etc/passwd | head -40',
            'locked' => 'passwd -Sa 2>/dev/null | awk \'{print $1":"$2}\' | head -60',
            'online' => 'who 2>/dev/null | head -10',
            'last' => 'last -n 6 2>/dev/null | head -6',
        ]), 45);

        $parts = self::split($out);

        $locked = [];
        foreach (explode("\n", trim($parts['locked'] ?? '')) as $line) {
            $bits = explode(':', trim($line));
            if (count($bits) < 2 || $bits[0] === '') {
                continue;
            }
            // L یعنی قفل، P یعنی رمز دارد، NP یعنی بدون رمز.
            $locked[$bits[0]] = $bits[1] === 'L';
        }

        $users = [];
        foreach (explode("\n", trim($parts['passwd'] ?? '')) as $line) {
            $bits = explode(':', trim($line));
            if (count($bits) < 4 || $bits[0] === '') {
                continue;
            }
            $users[] = [
                'name' => $bits[0],
                'uid' => (int) $bits[1],
                'home' => $bits[2],
                'shell' => $bits[3],
                'locked' => $locked[$bits[0]] ?? null,
            ];
        }

        $lines = static function (string $text, int $limit): array {
            $out = [];
            foreach (explode("\n", trim($text)) as $line) {
                $line = trim($line);
                if ($line !== '' && !str_starts_with($line, 'wtmp begins')) {
                    $out[] = $line;
                }
            }

            return array_slice($out, 0, $limit);
        };

        return [
            'users' => $users,
            'online' => $lines($parts['online'] ?? '', 10),
            'last' => $lines($parts['last'] ?? '', 6),
        ];
    }

    /**
     * وضعیت امنیتی سرور: فایروال، ورودهای ناموفق، تنظیم ورود روت و
     * به‌روزرسانی‌های در انتظار.
     *
     * @return array{firewall: string, failed_logins: int, root_login: string, updates: int, updater: string, reboot_required: bool, notes: array<int, string>}
     */
    public static function security(SshClient $client): array
    {
        $out = $client->runOrFail(self::script([
            'firewall' => 'ufw status 2>/dev/null | head -1 || firewall-cmd --state 2>/dev/null || echo unknown',
            'failed' => '( grep -c "Failed password" /var/log/auth.log 2>/dev/null '
                . '|| grep -c "Failed password" /var/log/secure 2>/dev/null '
                . '|| journalctl -u sshd --since "-7 days" 2>/dev/null | grep -c "Failed password" ) | head -1',
            'root' => 'grep -Ei "^ *PermitRootLogin" /etc/ssh/sshd_config 2>/dev/null | tail -1 || echo "PermitRootLogin (پیش‌فرض)"',
            'updates' => 'apt-get -s upgrade 2>/dev/null | grep -c "^Inst" '
                . '|| yum -q check-update 2>/dev/null | grep -c "^[a-zA-Z0-9]" || echo 0',
            'updater' => 'command -v apt-get >/dev/null 2>&1 && echo apt || (command -v dnf >/dev/null 2>&1 && echo dnf || (command -v yum >/dev/null 2>&1 && echo yum || echo unknown))',
            'reboot' => 'test -f /var/run/reboot-required && echo yes || echo no',
            'selinux' => 'getenforce 2>/dev/null || echo -',
        ]), 90);

        $parts = self::split($out);
        $notes = [];
        $rootLogin = trim($parts['root'] ?? '');
        if (stripos($rootLogin, 'yes') !== false) {
            $notes[] = 'ورود مستقیم کاربر root با SSH باز است؛ اگر لازمش ندارید ببندیدش.';
        }
        $firewall = trim($parts['firewall'] ?? '');
        if ($firewall === '' || stripos($firewall, 'inactive') !== false || stripos($firewall, 'not running') !== false) {
            $notes[] = 'فایروال روشن نیست.';
        }
        $selinux = trim($parts['selinux'] ?? '');
        if ($selinux !== '' && $selinux !== '-') {
            $notes[] = 'SELinux: ' . $selinux;
        }

        return [
            'firewall' => $firewall !== '' ? $firewall : 'نامشخص',
            'failed_logins' => (int) trim(explode("\n", trim($parts['failed'] ?? '0'))[0] ?? '0'),
            'root_login' => $rootLogin,
            'updates' => (int) trim(explode("\n", trim($parts['updates'] ?? '0'))[0] ?? '0'),
            'updater' => trim($parts['updater'] ?? 'unknown'),
            'reboot_required' => trim($parts['reboot'] ?? 'no') === 'yes',
            'notes' => $notes,
        ];
    }

    /**
     * بسته‌هایی که به‌روزرسانی دارند.
     *
     * @return array{updater: string, count: int, packages: array<int, string>}
     */
    public static function updates(SshClient $client): array
    {
        $out = $client->runOrFail(self::script([
            'updater' => 'command -v apt-get >/dev/null 2>&1 && echo apt || (command -v dnf >/dev/null 2>&1 && echo dnf || (command -v yum >/dev/null 2>&1 && echo yum || echo unknown))',
            'list' => 'apt-get -s upgrade 2>/dev/null | awk \'/^Inst/ {print $2}\' | head -30 '
                . '|| (yum -q check-update 2>/dev/null | awk \'NF>=3 {print $1}\' | head -30)',
        ]), 120);

        $parts = self::split($out);
        $packages = [];
        foreach (explode("\n", trim($parts['list'] ?? '')) as $line) {
            $line = trim($line);
            if ($line !== '') {
                $packages[] = $line;
            }
        }

        return [
            'updater' => trim($parts['updater'] ?? 'unknown'),
            'count' => count($packages),
            'packages' => $packages,
        ];
    }

    // ------------------------------------------------------------ کارها

    public static function restartService(SshClient $client, string $service): string
    {
        self::assertName($service, 'نام سرویس');

        $client->runOrFail('systemctl restart ' . SshClient::quote($service), 90);

        return trim($client->run('systemctl is-active ' . SshClient::quote($service), 30)['out']);
    }

    /**
     * تغییر رمز یک کاربر سیستم.
     *
     * رمز از ورودی استاندارد می‌رود، پس در فهرست پردازش‌های سرور دیده نمی‌شود.
     */
    public static function changePassword(SshClient $client, string $user, string $password): void
    {
        self::assertName($user, 'نام کاربر');
        if (mb_strlen($password) < 8) {
            throw CpanelError::config('رمز باید حداقل ۸ کاراکتر باشد.');
        }
        if (str_contains($password, "\n") || str_contains($password, ':')) {
            throw CpanelError::config('رمز نباید شامل «:» یا خط جدید باشد.');
        }

        $result = $client->run('chpasswd', 45, $user . ':' . $password . "\n");
        if ($result['code'] !== 0) {
            $message = trim($result['err']) !== '' ? trim($result['err']) : trim($result['out']);

            throw CpanelError::api('تغییر رمز انجام نشد: ' . mb_substr($message, 0, 200));
        }
    }

    public static function lockUser(SshClient $client, string $user, bool $lock): void
    {
        self::assertName($user, 'نام کاربر');
        if ($lock && in_array($user, ['root'], true)) {
            throw CpanelError::config('قفل‌کردن کاربر root از داخل ربات مجاز نیست؛ ممکن است دسترسی خودتان قطع شود.');
        }

        $client->runOrFail(($lock ? 'usermod -L ' : 'usermod -U ') . SshClient::quote($user), 45);
    }

    /**
     * مسدودکردن یک آی‌پی روی فایروال سرور.
     */
    public static function blockIp(SshClient $client, string $ip, bool $block): string
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw CpanelError::config('آی‌پی معتبر نیست.');
        }

        $quoted = SshClient::quote($ip);
        $command = $block
            ? "if command -v ufw >/dev/null 2>&1; then ufw deny from {$quoted} && echo ufw; "
                . "else iptables -I INPUT -s {$quoted} -j DROP && echo iptables; fi"
            : "if command -v ufw >/dev/null 2>&1; then ufw delete deny from {$quoted} && echo ufw; "
                . "else iptables -D INPUT -s {$quoted} -j DROP && echo iptables; fi";

        return trim($client->runOrFail($command, 60));
    }

    /**
     * نصب به‌روزرسانی‌های سیستم (بدون پرسش تعاملی).
     */
    public static function upgrade(SshClient $client): string
    {
        $command = 'if command -v apt-get >/dev/null 2>&1; then '
            . 'DEBIAN_FRONTEND=noninteractive apt-get update -qq && '
            . 'DEBIAN_FRONTEND=noninteractive apt-get -y -qq upgrade 2>&1 | tail -20; '
            . 'elif command -v dnf >/dev/null 2>&1; then dnf -y -q update 2>&1 | tail -20; '
            . 'else yum -y -q update 2>&1 | tail -20; fi';

        return trim($client->runOrFail($command, 900));
    }

    /**
     * ری‌استارت سرور. اتصال همان لحظه قطع می‌شود، پس قطع‌شدن یعنی موفقیت.
     */
    public static function reboot(SshClient $client): void
    {
        try {
            $client->run('(sleep 2; systemctl reboot || reboot) >/dev/null 2>&1 &', 20);
        } catch (CpanelError $e) {
            if ($e->kind !== CpanelError::KIND_TRANSPORT) {
                throw $e;
            }
        }
    }

    /**
     * اجرای یک دستور دلخواه.
     *
     * @return array{code: int, out: string, err: string}
     */
    public static function command(SshClient $client, string $command, int $timeout = 120): array
    {
        $command = trim($command);
        if ($command === '') {
            throw CpanelError::config('دستور خالی است.');
        }
        if (preg_match('#\brm\s+(-[a-zA-Z]*\s+)*(/|/\*)\s*$#', $command) === 1) {
            throw CpanelError::config('این دستور کل سیستم فایل را پاک می‌کند؛ از داخل ربات اجازه‌اش را نمی‌دهم.');
        }

        return $client->run($command, $timeout);
    }

    /**
     * آیا این سرور خطر دارد؟ چند بررسی سریع برای هشدارهای خودکار.
     *
     * @return array<int, string>
     */
    public static function alerts(SshClient $client, int $diskThreshold = 85, float $loadFactor = 2.0): array
    {
        $facts = self::facts($client);
        $messages = [];

        foreach ($facts['disks'] as $disk) {
            if ($disk['percent'] !== null && $disk['percent'] >= $diskThreshold) {
                $messages[] = '💽 پارتیشن <code>' . $disk['mount'] . '</code> به '
                    . round($disk['percent']) . '٪ رسید.';
            }
        }

        $cores = max(1, $facts['cpu']['cores']);
        if (($facts['load'][0] ?? 0.0) > $cores * $loadFactor) {
            $messages[] = '📈 بار سیستم ' . $facts['load'][0] . ' است (' . $cores . ' هسته)؛'
                . ' سرور تحت فشار سنگین است.';
        }

        if (($facts['memory']['percent'] ?? 0) >= 92) {
            $messages[] = '🧠 حافظه‌ی سرور تقریباً پر است ('
                . round((float) $facts['memory']['percent']) . '٪).';
        }

        return $messages;
    }

    // ---------------------------------------------------------- کمکی‌ها

    /**
     * چند دستور را با نشانه‌گذار کنار هم می‌گذارد تا یک اتصال کافی باشد.
     *
     * @param array<string, string> $commands
     */
    private static function script(array $commands): string
    {
        $parts = [];
        foreach ($commands as $name => $command) {
            $parts[] = 'echo "' . self::MARK . $name . '"';
            $parts[] = '{ ' . $command . ' ; } 2>/dev/null';
        }

        return implode('; ', $parts);
    }

    /**
     * @return array<string, string>
     */
    private static function split(string $output): array
    {
        $parts = [];
        $current = null;
        foreach (explode("\n", $output) as $line) {
            if (str_starts_with(trim($line), self::MARK)) {
                $current = trim(substr(trim($line), strlen(self::MARK)));
                $parts[$current] = '';
                continue;
            }
            if ($current !== null) {
                $parts[$current] .= $line . "\n";
            }
        }

        return array_map('trim', $parts);
    }

    /**
     * @return array<string, string>
     */
    private static function keyValues(string $text, string $separator): array
    {
        $out = [];
        foreach (explode("\n", $text) as $line) {
            $position = strpos($line, $separator);
            if ($position === false) {
                continue;
            }
            $key = trim(substr($line, 0, $position));
            $value = trim(substr($line, $position + 1));
            if ($key !== '') {
                $out[$key] = trim(preg_replace('/\s*kB$/', '', $value) ?? $value);
            }
        }

        return $out;
    }

    /**
     * @return array<int, float>
     */
    private static function loadNumbers(string $line): array
    {
        $bits = preg_split('/\s+/', trim($line)) ?: [];
        $out = [];
        foreach (array_slice($bits, 0, 3) as $value) {
            if (is_numeric($value)) {
                $out[] = (float) $value;
            }
        }

        return count($out) === 3 ? $out : [];
    }

    private static function uptimeText(string $raw): string
    {
        $seconds = (int) (float) trim(explode(' ', trim($raw))[0] ?? '0');

        return $seconds > 0 ? Format::humanizeDuration($seconds) : '';
    }

    /**
     * @return array<int, array{mount: string, size: float, used: float, percent: ?float}>
     */
    private static function disks(string $text): array
    {
        $out = [];
        foreach (explode("\n", trim($text)) as $index => $line) {
            $columns = preg_split('/\s+/', trim($line)) ?: [];
            if (count($columns) < 6 || $index === 0) {
                continue;
            }
            $mount = (string) end($columns);
            $percent = (float) rtrim((string) $columns[count($columns) - 2], '%');
            $out[] = [
                'mount' => $mount,
                'size' => (float) $columns[1] * 1024,
                'used' => (float) $columns[2] * 1024,
                'percent' => $percent > 0 ? $percent : null,
            ];
        }

        usort($out, static fn (array $a, array $b): int => ($b['percent'] ?? 0) <=> ($a['percent'] ?? 0));

        return $out;
    }

    /**
     * @return array<int, string>
     */
    private static function ips(string $text): array
    {
        $out = [];
        foreach (preg_split('/\s+/', trim($text)) ?: [] as $value) {
            $value = trim(explode('/', $value)[0]);
            if ($value !== '' && filter_var($value, FILTER_VALIDATE_IP) !== false) {
                $out[] = $value;
            }
        }

        return array_values(array_unique($out));
    }

    /**
     * @return array<int, array{cpu: float, mem: float, pid: int, name: string}>
     */
    private static function processes(string $text): array
    {
        $out = [];
        foreach (explode("\n", trim($text)) as $index => $line) {
            if ($index === 0) {
                continue;
            }
            $columns = preg_split('/\s+/', trim($line)) ?: [];
            if (count($columns) < 4) {
                continue;
            }
            $out[] = [
                'cpu' => (float) $columns[0],
                'mem' => (float) $columns[1],
                'pid' => (int) $columns[2],
                'name' => (string) $columns[3],
            ];
        }

        return $out;
    }

    private static function assertName(string $value, string $label): void
    {
        if (preg_match('/^[a-zA-Z0-9@._-]{1,64}$/', $value) !== 1) {
            throw CpanelError::config($label . ' معتبر نیست (فقط حروف انگلیسی، عدد و - . _ @).');
        }
    }
}
