<?php

declare(strict_types=1);

/**
 * تبدیل خروجی سرویس به متن فارسی. همه‌جا از HTML تلگرام استفاده می‌کنیم و
 * برای CLI همون متن با toPlain به متن ساده تبدیل می‌شه — یعنی یک منطق نمایش
 * برای هر دو رابط.
 */
final class Render
{
    public static function ping(array $info): string
    {
        $lines = ['✅ <b>اتصال برقرار شد</b>'];
        $lines[] = '🖥 سرور: <code>' . self::e((string) $info['host']) . '</code>';
        $lines[] = '🔑 کاربر: <code>' . self::e((string) $info['user']) . '</code>';
        $lines[] = '🧩 نوع: ' . ($info['type'] === CpanelClient::TYPE_WHM ? 'WHM (سطح سرور)' : 'cPanel (سطح حساب)');
        if (!empty($info['hostname'])) {
            $lines[] = '🏷 هاست‌نیم: <code>' . self::e((string) $info['hostname']) . '</code>';
        }
        if (!empty($info['version'])) {
            $lines[] = '📦 نسخه: <code>' . self::e((string) $info['version']) . '</code>';
        }
        if (!empty($info['home'])) {
            $lines[] = '📂 هوم: <code>' . self::e((string) $info['home']) . '</code>';
        }

        return implode("\n", $lines);
    }

    public static function summary(array $data): string
    {
        $lines = ['📊 <b>خلاصه‌ی حساب</b>'];
        $lines[] = '🖥 ' . self::e((string) $data['server']) . ' — <code>' . self::e((string) $data['user']) . '</code>';

        $domains = $data['domains'];
        if (($domains['main_domain'] ?? '') !== '') {
            $lines[] = '🌐 دامنه‌ی اصلی: <code>' . self::e((string) $domains['main_domain']) . '</code>';
        }

        if ($data['usage'] !== []) {
            $lines[] = '';
            $lines[] = '<b>منابع:</b>';
            foreach ($data['usage'] as $item) {
                $lines[] = self::usageLine($item);
            }
        }

        $counts = $data['counts'];
        $lines[] = '';
        $lines[] = '<b>موجودی حساب:</b>';
        $lines[] = "✉️ ایمیل: {$counts['emails']}   🗄 دیتابیس: {$counts['databases']}   📁 FTP: {$counts['ftp']}";
        $lines[] = "🌐 ساب‌دامنه: {$counts['subdomains']}   ➕ ادان‌دامنه: {$counts['addon']}";
        $lines[] = '';
        $lines[] = '🕐 ' . Format::jalaliNow();

        return implode("\n", $lines);
    }

    private static function usageLine(array $item): string
    {
        $isBytes = str_contains(strtolower($item['unit'] ?? ''), 'byte');
        $usage = $item['usage'] === null
            ? '—'
            : ($isBytes ? Format::bytes($item['usage']) : self::number($item['usage']));
        $maximum = $item['maximum'] === null || $item['maximum'] <= 0
            ? 'نامحدود'
            : ($isBytes ? Format::bytes($item['maximum']) : self::number($item['maximum']));

        $bar = $item['percent'] === null ? '' : ' ' . Format::bar($item['percent']) . ' ' . Format::percent($item['percent']);

        return '• ' . self::e((string) $item['label']) . ": $usage / $maximum$bar";
    }

    public static function emails(array $emails): string
    {
        if ($emails === []) {
            return '✉️ هیچ حساب ایمیلی روی این حساب تعریف نشده.';
        }

        $lines = ['✉️ <b>حساب‌های ایمیل</b> (' . count($emails) . ')', ''];
        foreach ($emails as $email) {
            $used = Format::bytes($email['used']);
            $quota = Format::quota($email['quota'], 'B');
            $lines[] = '• <code>' . self::e($email['email']) . '</code>';
            $lines[] = "   💾 $used از $quota"
                . ($email['percent'] !== null ? ' (' . Format::percent($email['percent']) . ')' : '');
        }

        return implode("\n", $lines);
    }

    public static function databases(array $databases, array $users): string
    {
        $lines = ['🗄 <b>دیتابیس‌های MySQL</b> (' . count($databases) . ')'];
        if ($databases === []) {
            $lines[] = '';
            $lines[] = 'هیچ دیتابیسی ساخته نشده.';
        }
        foreach ($databases as $database) {
            $size = $database['size'] !== null ? ' — ' . Format::bytes($database['size']) : '';
            $lines[] = '';
            $lines[] = '• <code>' . self::e($database['name']) . '</code>' . $size;
            if ($database['users'] !== []) {
                $lines[] = '   👤 ' . self::e(implode('، ', $database['users']));
            }
        }

        $lines[] = '';
        $lines[] = '👥 <b>کاربران دیتابیس</b> (' . count($users) . ')';
        $lines[] = $users === [] ? 'هیچ کاربری ساخته نشده.' : self::e(implode('، ', $users));

        return implode("\n", $lines);
    }

    public static function domains(array $domains): string
    {
        $lines = ['🌐 <b>دامنه‌ها</b>'];
        $lines[] = '';
        $lines[] = '🏠 اصلی: <code>' . self::e($domains['main_domain']) . '</code>';
        $lines[] = '';
        $lines[] = '➕ ادان‌دامنه‌ها (' . count($domains['addon_domains']) . '):';
        $lines[] = $domains['addon_domains'] === [] ? '—' : self::bullets($domains['addon_domains']);
        $lines[] = '';
        $lines[] = '🔗 ساب‌دامنه‌ها (' . count($domains['sub_domains']) . '):';
        $lines[] = $domains['sub_domains'] === [] ? '—' : self::bullets($domains['sub_domains']);
        $lines[] = '';
        $lines[] = '🅿️ پارک‌شده‌ها (' . count($domains['parked_domains']) . '):';
        $lines[] = $domains['parked_domains'] === [] ? '—' : self::bullets($domains['parked_domains']);

        return implode("\n", $lines);
    }

    public static function ftp(array $accounts): string
    {
        if ($accounts === []) {
            return '📁 هیچ حساب FTP‌ای تعریف نشده.';
        }

        $lines = ['📁 <b>حساب‌های FTP</b> (' . count($accounts) . ')', ''];
        foreach ($accounts as $account) {
            $lines[] = '• <code>' . self::e($account['user']) . '</code>';
            if ($account['dir'] !== '') {
                $lines[] = '   📂 <code>' . self::e($account['dir']) . '</code>';
            }
            $lines[] = '   💾 ' . Format::bytes($account['used']) . ' از ' . Format::quota($account['quota'], 'B');
        }

        return implode("\n", $lines);
    }

    public static function cron(array $jobs): string
    {
        if ($jobs === []) {
            return '⏰ هیچ کران‌جابی تعریف نشده.';
        }

        $lines = ['⏰ <b>کران‌جاب‌ها</b> (' . count($jobs) . ')', ''];
        foreach ($jobs as $index => $job) {
            $lines[] = '• <b>#' . ($index + 1) . '</b> <code>' . self::e($job['schedule']) . '</code>';
            $lines[] = '   <code>' . self::e($job['command']) . '</code>';
            if ($job['key'] !== '') {
                $lines[] = '   🔑 <code>' . self::e($job['key']) . '</code>';
            }
        }

        return implode("\n", $lines);
    }

    public static function dns(string $zone, array $zoneData): string
    {
        $records = $zoneData['records'];
        $lines = ['🧭 <b>رکوردهای DNS</b> — <code>' . self::e($zone) . '</code> (' . count($records) . ')'];
        if ($zoneData['serial'] !== null) {
            $lines[] = 'سریال: <code>' . self::e((string) $zoneData['serial']) . '</code>';
        }
        $lines[] = '';
        foreach ($records as $record) {
            $lines[] = sprintf(
                '• [%d] <code>%s</code> %s <b>%s</b> → <code>%s</code>',
                $record['line'],
                self::e($record['name']),
                $record['ttl'] !== null ? (string) $record['ttl'] : '',
                self::e($record['type']),
                self::e(implode(' ', $record['data'])),
            );
        }

        return implode("\n", $lines);
    }

    public static function ssl(array $hosts): string
    {
        if ($hosts === []) {
            return '🔒 هیچ گواهی SSL نصب‌شده‌ای پیدا نشد.';
        }

        $lines = ['🔒 <b>گواهی‌های SSL</b> (' . count($hosts) . ')', ''];
        foreach ($hosts as $host) {
            $lines[] = '• <code>' . self::e($host['domain']) . '</code>';
            $lines[] = '   🏛 صادرکننده: ' . self::e($host['issuer'] !== '' ? $host['issuer'] : '—');
            $lines[] = '   📅 انقضا: ' . self::e(Format::timestampWithRemaining($host['expires']));
        }

        return implode("\n", $lines);
    }

    public static function php(array $vhosts, array $installed): string
    {
        $lines = ['🐘 <b>نسخه‌ی PHP هر دامنه</b> (' . count($vhosts) . ')', ''];
        foreach ($vhosts as $vhost) {
            $lines[] = '• <code>' . self::e($vhost['vhost']) . '</code> → <b>' . self::e($vhost['version']) . '</b>';
        }
        if ($installed !== []) {
            $lines[] = '';
            $lines[] = '📦 نسخه‌های نصب‌شده روی سرور:';
            $lines[] = self::e(implode('، ', $installed));
        }

        return implode("\n", $lines);
    }

    public static function whmAccounts(array $accounts): string
    {
        if ($accounts === []) {
            return '👥 هیچ حسابی پیدا نشد.';
        }

        $lines = ['👥 <b>حساب‌های سرور</b> (' . count($accounts) . ')', ''];
        foreach ($accounts as $account) {
            $state = $account['suspended'] ? '⛔️ معلق' : '✅ فعال';
            $lines[] = '• <code>' . self::e($account['user']) . '</code> — ' . self::e($account['domain']) . " $state";
        }

        return implode("\n", $lines);
    }

    public static function whmAccount(array $account): string
    {
        $lines = ['👤 <b>' . self::e($account['user']) . '</b>'];
        $lines[] = '🌐 دامنه: <code>' . self::e($account['domain']) . '</code>';
        $lines[] = '📧 ایمیل: ' . self::e($account['email'] !== '' ? $account['email'] : '—');
        $lines[] = '📦 پلن: ' . self::e($account['plan'] !== '' ? $account['plan'] : '—');
        $lines[] = '🖧 آی‌پی: <code>' . self::e($account['ip']) . '</code>';
        $lines[] = '💾 دیسک: ' . self::e((string) ($account['disk_used'] ?? '—')) . ' از '
            . self::e((string) ($account['disk_limit'] ?? 'نامحدود'));
        $lines[] = 'وضعیت: ' . ($account['suspended'] ? '⛔️ معلق' : '✅ فعال');

        return implode("\n", $lines);
    }

    public static function whmStatus(array $status): string
    {
        $lines = ['🖥 <b>وضعیت سرور</b>'];
        if ($status['hostname'] !== '') {
            $lines[] = '🏷 ' . self::e($status['hostname']);
        }
        if ($status['version'] !== '') {
            $lines[] = '📦 WHM ' . self::e($status['version']);
        }
        if ($status['load'] !== []) {
            $load = [];
            foreach (['one', 'five', 'fifteen'] as $key) {
                if (isset($status['load'][$key])) {
                    $load[] = self::e((string) $status['load'][$key]);
                }
            }
            if ($load !== []) {
                $lines[] = '📈 بار سیستم: ' . implode(' | ', $load);
            }
        }
        if ($status['services'] !== []) {
            $lines[] = '';
            $lines[] = '<b>سرویس‌ها:</b>';
            foreach ($status['services'] as $service) {
                $lines[] = ($service['running'] ? '🟢' : '🔴') . ' ' . self::e($service['name']);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, array<string, mixed>> $servers
     */
    public static function servers(array $servers, ?string $activeId): string
    {
        if ($servers === []) {
            return "🖥 هنوز هیچ سروری تعریف نشده.\n\nیا مقادیر <code>CPANEL_*</code> را در فایل <code>.env</code> پر کنید، یا از همین‌جا یکی اضافه کنید.";
        }

        $lines = ['🖥 <b>سرورها</b> (' . count($servers) . ')', ''];
        foreach ($servers as $id => $server) {
            $mark = $id === $activeId ? '✅' : '▫️';
            $type = $server['type'] === CpanelClient::TYPE_WHM ? 'WHM' : 'cPanel';
            $lines[] = "$mark <b>" . self::e((string) $server['label']) . "</b> — $type";
            $lines[] = '   🔗 <code>' . self::e((string) $server['host']) . ':' . (int) $server['port'] . '</code>';
            $lines[] = '   👤 <code>' . self::e((string) $server['user']) . '</code>  🔑 '
                . self::e(Format::maskSecret((string) $server['token']));
            if (($server['as_user'] ?? '') !== '') {
                $lines[] = '   🎯 کاربر مقصد: <code>' . self::e((string) $server['as_user']) . '</code>';
            }
            if (($server['ssl_verify'] ?? true) === false) {
                $lines[] = '   ⚠️ بررسی گواهی SSL خاموش است';
            }
            if (($server['source'] ?? '') === 'env') {
                $lines[] = '   📄 تعریف‌شده در <code>.env</code> (از داخل ربات حذف نمی‌شود)';
            }
            $lines[] = '   🆔 <code>' . self::e((string) $id) . '</code>';
            $lines[] = '';
        }

        return rtrim(implode("\n", $lines));
    }

    public static function error(Throwable $error): string
    {
        $hint = '';

        // بعضی نسخه‌های cPanel ماژول موردنیاز را ندارند؛ پیام خام پرل برای
        // کاربر بی‌معنی است، پس ترجمه‌اش می‌کنیم.
        if (stripos($error->getMessage(), 'Failed to load module') !== false
            || stripos($error->getMessage(), "Can't locate") !== false) {
            return '⚠️ این قابلیت روی نسخه‌ی cPanel این هاست در دسترس نیست (ماژول موردنیازش روی سرور نصب نشده).'
                . "\n\n💡 اگر فکر می‌کنید باید فعال باشد، از پشتیبانی هاست بخواهید ماژول مربوطه را فعال کند.";
        }

        if ($error instanceof CpanelError) {
            $hint = match ($error->kind) {
                CpanelError::KIND_AUTH => "\n\n💡 توکن را در cPanel از مسیر Security → Manage API Tokens (و در WHM از Development → Manage API Tokens) بسازید و مطمئن شوید نام کاربری دقیقاً همان حساب است.",
                CpanelError::KIND_TRANSPORT => "\n\n💡 هاست/پورت را بررسی کنید (cPanel معمولاً 2083 و WHM 2087) و مطمئن شوید فایروال سرور به این ماشین اجازه‌ی اتصال می‌دهد.",
                CpanelError::KIND_CONFIG => '',
                default => '',
            };
        }

        return '⚠️ ' . self::e($error->getMessage()) . $hint;
    }

    /**
     * پیام‌های تلگرام سقف ۴۰۹۶ کاراکتری دارن؛ لیست‌های بلند (مثل ۲۰۰ ایمیل)
     * باید تکه‌تکه فرستاده بشن، آن‌هم روی مرز خط تا تگ‌ها نصف نشن.
     *
     * @return array<int, string>
     */
    public static function chunks(string $text, int $limit = 3500): array
    {
        if (mb_strlen($text, 'UTF-8') <= $limit) {
            return [$text];
        }

        $chunks = [];
        $current = '';
        foreach (explode("\n", $text) as $line) {
            if ($current !== '' && mb_strlen($current, 'UTF-8') + mb_strlen($line, 'UTF-8') + 1 > $limit) {
                $chunks[] = $current;
                $current = '';
            }
            // یک خط تنها هم می‌تونه از سقف بلندتر باشه (مثلاً یک دستور کران خیلی طولانی).
            while (mb_strlen($line, 'UTF-8') > $limit) {
                $chunks[] = mb_substr($line, 0, $limit, 'UTF-8');
                $line = mb_substr($line, $limit, null, 'UTF-8');
            }
            $current = $current === '' ? $line : "$current\n$line";
        }
        if (trim($current) !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    /**
     * همان متن، بدون تگ‌های HTML — برای خروجی ترمینال.
     */
    public static function toPlain(string $html): string
    {
        $text = preg_replace('#<br\s*/?>#i', "\n", $html) ?? $html;
        $text = strip_tags($text);

        return html_entity_decode($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private static function bullets(array $items): string
    {
        return implode("\n", array_map(static fn (string $item): string => '• <code>' . self::e($item) . '</code>', $items));
    }

    private static function number(float $value): string
    {
        return Format::trimZeros(number_format($value, 2, '.', ','));
    }

    private static function e(string $text): string
    {
        return Format::escapeHtml($text);
    }
}
