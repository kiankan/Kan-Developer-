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

        $info = is_array($data['info'] ?? null) ? $data['info'] : [];
        $row = static function (string $label, string $value) use (&$lines): void {
            if (trim($value) !== '') {
                $lines[] = $label . ': ' . $value;
            }
        };

        $domains = $data['domains'];
        if (($domains['main_domain'] ?? '') !== '') {
            $lines[] = '🌐 دامنه‌ی اصلی: <code>' . self::e((string) $domains['main_domain']) . '</code>';
        }

        $row('🏷 سرور', '<code>' . self::e((string) ($info['hostname'] ?? ($info['machine'] ?? ''))) . '</code>');
        $row('📦 پلن', self::e((string) ($info['plan'] ?? '')));
        $row('📂 پوشه‌ی هوم', '<code>' . self::e((string) ($info['home'] ?? ($info['homedir'] ?? ''))) . '</code>');
        $row('🕐 آخرین ورود', self::e((string) ($info['lastlogin'] ?? '')));
        $row('🖧 آی‌پی آخرین ورود', '<code>' . self::e((string) ($info['lastlogin_ip'] ?? '')) . '</code>');
        $row('🧩 نسخه‌ی cPanel', self::e((string) ($info['cpanelversion'] ?? '')));

        if (isset($info['suspended'])) {
            $lines[] = 'وضعیت حساب: ' . (((string) $info['suspended']) === '1' ? '⛔️ معلق' : '✅ فعال');
        }

        if ($data['usage'] !== []) {
            $lines[] = '';
            $lines[] = '<b>مصرف منابع:</b>';
            foreach ($data['usage'] as $item) {
                $lines[] = self::usageLine($item);
            }

            $warnings = [];
            foreach ($data['usage'] as $item) {
                if ($item['percent'] !== null && $item['percent'] >= 80) {
                    $warnings[] = $item['label'] . ' (' . Format::percent($item['percent']) . ')';
                }
            }
            if ($warnings !== []) {
                $lines[] = '';
                $lines[] = '⚠️ نزدیک سقف: ' . self::e(implode('، ', $warnings));
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

    /**
     * @param array{enabled: array<int, string>, disabled: array<int, string>} $features
     */
    public static function features(array $features): string
    {
        if ($features['enabled'] === [] && $features['disabled'] === []) {
            return 'ℹ️ این هاست فهرست قابلیت‌های حساب را از طریق API نمی‌دهد.';
        }

        $lines = ['🧩 <b>قابلیت‌های فعال این حساب</b> (' . count($features['enabled']) . ')', ''];
        $lines[] = self::e(implode('، ', $features['enabled']));

        if ($features['disabled'] !== []) {
            $lines[] = '';
            $lines[] = '🚫 <b>غیرفعال</b> (' . count($features['disabled']) . '):';
            $lines[] = self::e(implode('، ', $features['disabled']));
        }

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

    /**
     * @param array<int, array{domain: string, docroot: string, ip: string, type: string}> $details
     */
    public static function domains(array $domains, array $details = []): string
    {
        $lines = ['🌐 <b>دامنه‌ها</b>'];

        if ($details !== []) {
            $lines[] = '';
            foreach ($details as $detail) {
                $lines[] = '• <code>' . self::e($detail['domain']) . '</code>'
                    . ($detail['type'] !== '' ? ' — ' . self::e($detail['type']) : '');
                if ($detail['docroot'] !== '') {
                    $lines[] = '   📂 <code>' . self::e($detail['docroot']) . '</code>';
                }
                if ($detail['ip'] !== '') {
                    $lines[] = '   🖧 <code>' . self::e($detail['ip']) . '</code>';
                }
            }

            return implode("\n", $lines);
        }

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

    /**
     * @param array<string, mixed> $result
     */
    public static function siteCheck(array $result): string
    {
        $lines = ['🩺 <b>بررسی سلامت ' . self::e((string) $result['domain']) . '</b>'];

        $status = static function (array $check, string $label): string {
            if (($check['status'] ?? 0) === 0) {
                return "❌ {$label}: جواب نداد" . (($check['error'] ?? '') !== '' ? ' (' . Format::escapeHtml((string) $check['error']) . ')' : '');
            }
            $icon = ($check['status'] < 400) ? '✅' : '⚠️';

            return "{$icon} {$label}: کد {$check['status']} — {$check['time_ms']} میلی‌ثانیه"
                . (($check['loop'] ?? false) ? ' 🔁 حلقه‌ی ریدایرکت' : '');
        };

        $lines[] = '';
        $lines[] = $status($result['https'], 'HTTPS');
        $lines[] = $status($result['http'], 'HTTP');
        $lines[] = $status($result['www'], 'www');

        $chain = $result['https']['chain'] ?? [];
        if (count($chain) > 1) {
            $lines[] = '';
            $lines[] = '↪️ مسیر ریدایرکت:';
            foreach ($chain as $hop) {
                $lines[] = '   ' . self::e((string) $hop);
            }
        }

        $dns = $result['dns'];
        $lines[] = '';
        $lines[] = '🧭 رکورد A: ' . ($dns['a'] === [] ? '—' : '<code>' . self::e(implode('، ', $dns['a'])) . '</code>');
        if ($dns['matches_server'] !== null) {
            $lines[] = $dns['matches_server']
                ? '   ✅ به همین هاست اشاره می‌کند'
                : '   ⚠️ به این هاست اشاره نمی‌کند';
        }
        if ($dns['ns'] !== []) {
            $lines[] = '🌐 نیم‌سرورها: ' . self::e(implode('، ', $dns['ns']));
        }

        $certificate = $result['certificate'];
        $lines[] = '';
        if ($certificate === null) {
            $lines[] = '🔒 گواهی SSL خوانده نشد.';
        } else {
            $lines[] = '🔒 گواهی از: ' . self::e((string) $certificate['issuer']);
            $lines[] = '   📅 انقضا: ' . self::e(Format::timestampWithRemaining($certificate['valid_to']));
            $lines[] = '   ' . (($certificate['covers_domain'] ?? false) ? '✅ برای همین دامنه صادر شده' : '⚠️ نام دامنه با گواهی نمی‌خواند');
        }

        if ($result['problems'] !== []) {
            $lines[] = '';
            $lines[] = '<b>مشکل‌های پیداشده:</b>';
            foreach ($result['problems'] as $problem) {
                $lines[] = '• ' . self::e((string) $problem);
            }
        } else {
            $lines[] = '';
            $lines[] = '✅ مشکلی پیدا نشد.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, string> $limits
     */
    public static function phpLimits(string $vhost, array $limits): string
    {
        if ($limits === []) {
            return 'ℹ️ محدودیت‌های PHP این دامنه از طریق API در دسترس نیست.';
        }

        $labels = [
            'memory_limit' => '🧠 حافظه (memory_limit)',
            'upload_max_filesize' => '⬆️ حجم آپلود (upload_max_filesize)',
            'post_max_size' => '📮 حجم فرم (post_max_size)',
            'max_execution_time' => '⏱ زمان اجرا (max_execution_time)',
            'max_input_vars' => '🔢 تعداد ورودی (max_input_vars)',
            'max_input_time' => '⏳ زمان دریافت ورودی (max_input_time)',
        ];

        $lines = ['⚙️ <b>محدودیت‌های PHP</b> — <code>' . self::e($vhost) . '</code>', ''];
        foreach ($labels as $key => $label) {
            if (isset($limits[$key])) {
                $lines[] = $label . ': <code>' . self::e($limits[$key]) . '</code>';
            }
        }
        foreach ($limits as $key => $value) {
            if (!isset($labels[$key])) {
                $lines[] = self::e($key) . ': <code>' . self::e($value) . '</code>';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, string> $settings
     */
    public static function emailSettings(string $email, array $settings): string
    {
        if ($settings === []) {
            return 'ℹ️ تنظیمات این صندوق از طریق API برنگشت.';
        }

        $lines = ['📱 <b>تنظیمات ' . self::e($email) . '</b>', ''];
        $wanted = ['imap', 'pop3', 'smtp', 'server', 'port', 'ssl', 'user'];

        foreach ($settings as $key => $value) {
            $lower = strtolower($key);
            foreach ($wanted as $needle) {
                if (str_contains($lower, $needle)) {
                    $lines[] = self::e($key) . ': <code>' . self::e($value) . '</code>';
                    break;
                }
            }
        }

        $lines[] = '';
        $lines[] = '💡 در Outlook یا موبایل، همین مقادیر را با نام کاربری «آدرس کامل ایمیل» و رمز همان صندوق وارد کنید.';

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

    /**
     * @param array<int, array{name: string, path: string, dir: bool, size: ?float, mtime: ?int}> $entries
     */
    public static function files(string $dir, array $entries): string
    {
        $lines = ['📂 <b>' . self::e($dir) . '</b>'];
        if ($entries === []) {
            $lines[] = '';
            $lines[] = 'این پوشه خالی است.';

            return implode("\n", $lines);
        }

        $dirs = count(array_filter($entries, static fn (array $entry): bool => $entry['dir']));
        $lines[] = $dirs . ' پوشه، ' . (count($entries) - $dirs) . ' فایل';

        return implode("\n", $lines);
    }

    /**
     * فهرست فایل با جزئیات (حجم، تاریخ، سطح دسترسی) — برای نماهایی مثل
     * «بزرگ‌ترین فایل‌ها» و «تغییرات اخیر».
     *
     * @param array<int, array{name: string, path: string, dir: bool, size: ?float, mtime: ?int, permissions?: string}> $entries
     */
    public static function fileList(string $title, array $entries): string
    {
        if ($entries === []) {
            return $title . "\n\nچیزی پیدا نشد.";
        }

        $lines = [$title, ''];
        foreach ($entries as $entry) {
            $lines[] = ($entry['dir'] ? '📁 ' : '📄 ') . '<code>' . self::e($entry['name']) . '</code>';
            $meta = [];
            if (($entry['size'] ?? null) !== null) {
                $meta[] = Format::bytes((float) $entry['size']);
            }
            if (($entry['permissions'] ?? '') !== '') {
                $meta[] = 'دسترسی ' . $entry['permissions'];
            }
            if (($entry['mtime'] ?? null) !== null) {
                $meta[] = date('Y-m-d H:i', (int) $entry['mtime']);
            }
            if ($meta !== []) {
                $lines[] = '   ' . self::e(implode(' — ', $meta));
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array{from: string, to: string}> $forwarders
     */
    public static function forwarders(array $forwarders): string
    {
        if ($forwarders === []) {
            return "📮 هیچ فورواردی تعریف نشده.\n\nفوروارد یعنی هر ایمیلی که به یک آدرس می‌رسد، خودکار به آدرس دیگری هم فرستاده شود.";
        }

        $lines = ['📮 <b>فورواردهای ایمیل</b> (' . count($forwarders) . ')', ''];
        foreach ($forwarders as $forwarder) {
            $lines[] = '• <code>' . self::e($forwarder['from']) . '</code> ← ' . self::e($forwarder['to']);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array{name: string, size: ?float, time: ?int}> $backups
     */
    public static function backups(array $backups): string
    {
        if ($backups === []) {
            return '💾 بکاپی روی این حساب پیدا نشد. با «گرفتن بکاپ کامل» یکی بسازید.';
        }

        $lines = ['💾 <b>بکاپ‌های موجود</b> (' . count($backups) . ')', ''];
        foreach ($backups as $backup) {
            $line = '• <code>' . self::e($backup['name']) . '</code>';
            if ($backup['size'] !== null) {
                $line .= ' — ' . Format::bytes($backup['size']);
            }
            $lines[] = $line;
            if ($backup['time'] !== null) {
                $lines[] = '   📅 ' . self::e(Format::timestampWithRemaining($backup['time']));
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array{source: string, destination: string, type: string}> $redirects
     */
    public static function redirects(array $redirects): string
    {
        if ($redirects === []) {
            return "↪️ هیچ ریدایرکتی تعریف نشده.\n\nریدایرکت یعنی هر کسی آدرس اول را باز کرد، خودکار به آدرس دوم برود.";
        }

        $lines = ['↪️ <b>ریدایرکت‌ها</b> (' . count($redirects) . ')', ''];
        foreach ($redirects as $redirect) {
            $type = str_contains(strtolower($redirect['type']), 'perm') ? '301 دائمی' : '302 موقت';
            $lines[] = '• <code>' . self::e($redirect['source']) . '</code> → <code>'
                . self::e($redirect['destination']) . "</code>\n   ({$type})";
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array{email: string, password?: string, ok: bool, error: string}> $results
     */
    public static function bulkResult(string $title, array $results): string
    {
        if ($results === []) {
            return $title . "\n\nچیزی برای انجام‌دادن پیدا نشد.";
        }

        $ok = array_values(array_filter($results, static fn (array $row): bool => $row['ok']));
        $failed = array_values(array_filter($results, static fn (array $row): bool => !$row['ok']));

        $lines = [$title, '', '✅ موفق: ' . count($ok) . '   ❌ ناموفق: ' . count($failed)];

        if ($ok !== []) {
            $lines[] = '';
            foreach ($ok as $row) {
                $line = '• <code>' . self::e($row['email']) . '</code>';
                if (($row['password'] ?? '') !== '') {
                    $line .= "\n   🔐 <code>" . self::e($row['password']) . '</code>';
                }
                $lines[] = $line;
            }
            if (($ok[0]['password'] ?? '') !== '') {
                $lines[] = '';
                $lines[] = '⚠️ رمزها را همین حالا جایی ذخیره کنید؛ بعداً قابل بازیابی نیستند.';
            }
        }

        if ($failed !== []) {
            $lines[] = '';
            $lines[] = '<b>ناموفق‌ها:</b>';
            foreach ($failed as $row) {
                $lines[] = '• <code>' . self::e($row['email']) . '</code> — ' . self::e(mb_substr($row['error'], 0, 120));
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array{path: string, label: string, size: float, files: int}> $candidates
     */
    public static function cleanup(array $candidates, ?float $diskPercent = null): string
    {
        if ($candidates === []) {
            return "🧹 <b>پاک‌سازی هوشمند</b>\n\nجای قابل‌پاک‌سازی پیدا نشد — کش، لاگ یا بکاپ اضافه‌ای روی حساب نیست.";
        }

        $total = 0.0;
        foreach ($candidates as $candidate) {
            $total += $candidate['size'];
        }

        $lines = ['🧹 <b>پاک‌سازی هوشمند</b>', ''];
        if ($diskPercent !== null) {
            $lines[] = '💽 مصرف دیسک: ' . Format::percent($diskPercent);
        }
        $lines[] = 'مجموع فضایی که می‌شود آزاد کرد: <b>' . Format::bytes($total) . '</b>';
        $lines[] = '';

        foreach ($candidates as $index => $candidate) {
            $lines[] = '• ' . self::e($candidate['label']) . ' — ' . Format::bytes($candidate['size'])
                . ' (' . $candidate['files'] . ' مورد)';
            $lines[] = '   <code>' . self::e($candidate['path']) . '</code>';
        }

        $lines[] = '';
        $lines[] = '💡 پاک‌کردن کش و لاگ بی‌خطر است؛ فایل بکاپ را فقط وقتی پاک کنید که نسخه‌ی دیگری دارید.';

        return implode("\n", $lines);
    }

    /**
     * @param array<string, string> $info
     */
    public static function mysqlInfo(array $info, string $prefix): string
    {
        $lines = ['ℹ️ <b>اطلاعات اتصال به دیتابیس</b>', ''];
        $lines[] = '🖧 میزبان: <code>' . self::e($info['host'] !== '' ? $info['host'] : 'localhost') . '</code>';
        $lines[] = '🔌 پورت: <code>' . self::e($info['port']) . '</code>';
        if ($info['version'] !== '') {
            $lines[] = '📦 نسخه: <code>' . self::e($info['version']) . '</code>';
        }
        if ($prefix !== '') {
            $lines[] = '🏷 پیشوند اجباری نام‌ها: <code>' . self::e($prefix) . '</code>';
        }

        $lines[] = '';
        $lines[] = "💡 در فایل تنظیمات سایت (مثل <code>wp-config.php</code>) همین میزبان را بگذارید و نام دیتابیس، نام کاربر و رمزی که خودتان ساخته‌اید.";

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array{email: string, subject: string, from: string}> $responders
     */
    public static function autoResponders(array $responders): string
    {
        if ($responders === []) {
            return "🤖 هیچ پاسخ خودکاری تنظیم نشده.\n\nپاسخ خودکار یعنی هر نامه‌ای که به آن آدرس برسد، یک جواب آماده (مثل «در مرخصی هستم») برایش فرستاده شود.";
        }

        $lines = ['🤖 <b>پاسخ‌های خودکار</b> (' . count($responders) . ')', ''];
        foreach ($responders as $responder) {
            $lines[] = '• <code>' . self::e($responder['email']) . '</code>'
                . ($responder['subject'] !== '' ? ' — ' . self::e($responder['subject']) : '');
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array{user: string, host: string, file: string, pid: string, status: string}> $sessions
     */
    public static function ftpSessions(array $sessions): string
    {
        if ($sessions === []) {
            return '🔌 هیچ نشست FTP فعالی نیست.';
        }

        $lines = ['🔌 <b>نشست‌های FTP باز</b> (' . count($sessions) . ')', ''];
        foreach ($sessions as $session) {
            $lines[] = '• <code>' . self::e($session['user']) . '</code> از ' . self::e($session['host'] ?: '—');
            if ($session['file'] !== '') {
                $lines[] = '   📄 ' . self::e($session['file']);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<string, array<int, string>> $results
     */
    public static function search(string $term, array $results): string
    {
        if ($results === []) {
            return '🔍 برای «' . self::e($term) . "» چیزی پیدا نشد.\n\nربات در ایمیل‌ها، دیتابیس‌ها، دامنه‌ها، حساب‌های FTP و فایل‌های هوم می‌گردد.";
        }

        $lines = ['🔍 <b>نتیجه‌ی جست‌وجوی «' . self::e($term) . '»</b>'];
        foreach ($results as $group => $rows) {
            $lines[] = '';
            $lines[] = '<b>' . self::e((string) $group) . '</b> (' . count($rows) . '):';
            foreach ($rows as $row) {
                $lines[] = '• <code>' . self::e($row) . '</code>';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array{domain: string, bytes: float}> $rows
     */
    public static function bandwidth(array $rows): string
    {
        if ($rows === []) {
            return 'ℹ️ این هاست آمار پهنای‌باند به تفکیک دامنه را از طریق API نمی‌دهد. مصرف کل را در «خلاصه‌ی حساب» ببینید.';
        }

        $lines = ['📶 <b>مصرف پهنای‌باند به تفکیک دامنه</b>', ''];
        foreach ($rows as $row) {
            $lines[] = '• <code>' . self::e($row['domain']) . '</code> — ' . Format::bytes($row['bytes']);
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array{name: string, path: string, size: float, files: int}> $dirs
     */
    public static function directoryUsage(array $dirs): string
    {
        if ($dirs === []) {
            return '📊 پوشه‌ای برای اندازه‌گیری پیدا نشد.';
        }

        $total = 0.0;
        foreach ($dirs as $dir) {
            $total += $dir['size'];
        }

        $lines = ['📊 <b>مصرف پوشه‌های هوم</b>', '', 'مجموع اندازه‌گیری‌شده: ' . Format::bytes($total), ''];
        foreach ($dirs as $dir) {
            $share = $total > 0 ? $dir['size'] / $total * 100 : 0.0;
            $lines[] = '• <code>' . self::e($dir['name']) . '</code> — ' . Format::bytes($dir['size'])
                . ' ' . Format::bar($share, 8);
        }
        $lines[] = '';
        $lines[] = 'ℹ️ اندازه‌ها فقط فایل‌های سطح‌اول هر پوشه‌اند، نه زیرپوشه‌ها.';

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array{id: string, today: float, yesterday: ?float, week: ?float}> $trend
     */
    public static function usageTrend(array $trend, int $days): string
    {
        if ($trend === []) {
            return "📈 هنوز تاریخچه‌ای ثبت نشده.\n\nربات هر روز یک‌بار مصرف را ثبت می‌کند؛ از فردا مقایسه‌ی روزانه را همین‌جا می‌بینید.";
        }

        $lines = ['📈 <b>روند مصرف</b> — ' . $days . ' روز ثبت‌شده', ''];
        foreach ($trend as $row) {
            $line = '• <code>' . self::e($row['id']) . '</code>: ' . Format::percent($row['today']);
            if ($row['yesterday'] !== null) {
                $delta = $row['today'] - $row['yesterday'];
                $icon = $delta > 0.5 ? '🔺' : ($delta < -0.5 ? '🔻' : '▪️');
                $line .= "  {$icon} نسبت به دیروز " . Format::percent(abs($delta));
            }
            if ($row['week'] !== null) {
                $delta = $row['today'] - $row['week'];
                $line .= '  | هفته‌ی پیش: ' . Format::percent($row['week']);
            }
            $lines[] = $line;
        }

        return implode("\n", $lines);
    }

    /**
     * @param array{mx: array<int, string>, spf: string, dmarc: string, dkim: bool, problems: array<int, string>} $records
     */
    public static function mailRecords(string $domain, array $records): string
    {
        $lines = ['📬 <b>رکوردهای ایمیل ' . self::e($domain) . '</b>', ''];

        $lines[] = '📮 MX: ' . ($records['mx'] === [] ? '—' : '<code>' . self::e(implode('، ', $records['mx'])) . '</code>');
        $lines[] = '🛡 SPF: ' . ($records['spf'] === '' ? '—' : '<code>' . self::e($records['spf']) . '</code>');
        $lines[] = '✍️ DKIM: ' . ($records['dkim'] ? '✅ دارد' : '—');
        $lines[] = '📋 DMARC: ' . ($records['dmarc'] === '' ? '—' : '<code>' . self::e($records['dmarc']) . '</code>');

        if ($records['problems'] === []) {
            $lines[] = '';
            $lines[] = '✅ همه‌ی رکوردهای مهم سرجایشان هستند.';

            return implode("\n", $lines);
        }

        $lines[] = '';
        $lines[] = '<b>چه چیزی کم است:</b>';
        foreach ($records['problems'] as $problem) {
            $lines[] = '• ' . $problem;
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array{user: string, domain: string, disk: string, suspended: bool}> $accounts
     */
    public static function whmTopAccounts(array $accounts): string
    {
        if ($accounts === []) {
            return '📊 حسابی پیدا نشد.';
        }

        $lines = ['📊 <b>پرمصرف‌ترین حساب‌های سرور</b>', ''];
        foreach ($accounts as $index => $account) {
            $lines[] = ($index + 1) . '. <code>' . self::e($account['user']) . '</code> — '
                . self::e($account['disk'] !== '' ? $account['disk'] : '—')
                . ($account['suspended'] ? ' ⛔️' : '');
            $lines[] = '   ' . self::e($account['domain']);
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

    /**
     * دفترچه‌ی عملیات به زبان آدمیزاد (به‌جای خط‌های خام JSON).
     *
     * @param array<int, string> $lines خطوط JSON، از جدید به قدیم
     */
    public static function auditLog(array $lines): string
    {
        if ($lines === []) {
            return '🧾 هنوز عملیاتی ثبت نشده.';
        }

        $labels = [
            'email_add' => 'ساخت ایمیل', 'email_del' => 'حذف ایمیل', 'email_passwd' => 'تغییر رمز ایمیل',
            'email_quota' => 'تغییر سهمیه ایمیل', 'email_bulk_add' => 'ساخت گروهی ایمیل',
            'email_bulk_del' => 'حذف گروهی ایمیل', 'fwd_add' => 'افزودن فوروارد', 'fwd_del' => 'حذف فوروارد',
            'db_add' => 'ساخت دیتابیس', 'db_del' => 'حذف دیتابیس', 'dbuser_add' => 'ساخت کاربر دیتابیس',
            'dbuser_del' => 'حذف کاربر دیتابیس', 'db_grant' => 'دادن دسترسی دیتابیس',
            'db_revoke' => 'گرفتن دسترسی دیتابیس', 'db_passwd' => 'تغییر رمز کاربر دیتابیس',
            'db_rename' => 'تغییر نام دیتابیس', 'dbuser_rename' => 'تغییر نام کاربر دیتابیس',
            'db_repair' => 'تعمیر دیتابیس', 'mysql_host_add' => 'افزودن میزبان راه دور',
            'mysql_host_del' => 'حذف میزبان راه دور',
            'sub_add' => 'ساخت ساب‌دامنه', 'sub_del' => 'حذف ساب‌دامنه',
            'redirect_add' => 'افزودن ریدایرکت', 'redirect_del' => 'حذف ریدایرکت',
            'dns_add' => 'افزودن رکورد DNS', 'dns_del' => 'حذف رکورد DNS', 'dns_list' => 'دیدن رکوردهای DNS',
            'file_upload' => 'آپلود فایل', 'file_extract' => 'باز کردن آرشیو', 'file_delete' => 'حذف فایل',
            'file_mkdir' => 'ساخت پوشه', 'file_rename' => 'تغییر نام فایل', 'file_move' => 'جابه‌جایی فایل',
            'file_chmod' => 'تغییر سطح دسترسی', 'file_compress' => 'فشرده‌سازی', 'cleanup_run' => 'پاک‌سازی',
            'ftp_add' => 'ساخت حساب FTP', 'ftp_del' => 'حذف حساب FTP',
            'cron_add' => 'افزودن کران', 'cron_edit' => 'ویرایش کران', 'cron_del' => 'حذف کران',
            'cron_email' => 'تنظیم ایمیل کران', 'php_set' => 'تغییر نسخه PHP', 'php_limits' => 'دیدن محدودیت‌های PHP',
            'backup_run' => 'گرفتن بکاپ', 'autossl' => 'درخواست AutoSSL', 'force_https' => 'اجبار HTTPS',
            'site_check' => 'بررسی سلامت دامنه',
            'srv_add' => 'افزودن سرور', 'srv_use' => 'تعویض سرور', 'srv_del' => 'حذف سرور',
            'admin_add' => 'افزودن ادمین', 'admin_del' => 'حذف ادمین', 'claim_bot' => 'تحویل‌گرفتن ربات',
            'whm_create' => 'ساخت حساب', 'whm_suspend' => 'تعلیق حساب', 'whm_unsuspend' => 'رفع تعلیق',
            'whm_terminate' => 'حذف حساب', 'whm_passwd' => 'تغییر رمز حساب', 'whm_package' => 'تغییر پلن',
            'whm_service' => 'ری‌استارت سرویس', 'whm_login' => 'ساخت لینک ورود',
            'selfhost_cron' => 'نصب کران ربات',
            'maintenance' => 'حالت تعمیر سایت', 'ip_block' => 'مسدودکردن آی‌پی',
            'dir_protect' => 'رمز روی پوشه', 'email_bulk_passwd' => 'تغییر رمز گروهی ایمیل',
            'file_bulk_delete' => 'حذف گروهی فایل', 'file_bulk_move' => 'جابه‌جایی گروهی',
            'file_download' => 'دانلود فایل', 'folder_download' => 'دانلود پوشه',
            'file_edit' => 'ویرایش فایل', 'file_create' => 'ساخت فایل', 'file_find' => 'جست‌وجوی فایل',
            'dns_bulk' => 'رکورد DNS گروهی', 'dns_fix' => 'اصلاح رکورد A',
            'fav_add' => 'افزودن میان‌بر', 'fav_del' => 'حذف میان‌بر',
            'alert_threshold' => 'تغییر آستانه‌ی هشدار', 'alerts_sent' => 'ارسال هشدار',
            'undo_restore' => 'بازگردانی بکاپ', 'report' => 'گزارش وضعیت',
            'search' => 'جست‌وجو', 'mail_purge' => 'پاک‌سازی Trash و Spam',
            'responder_add' => 'تنظیم پاسخ خودکار', 'responder_del' => 'حذف پاسخ خودکار',
            'ftp_passwd' => 'تغییر رمز FTP', 'ftp_quota' => 'تغییر سهمیه FTP', 'ftp_kill' => 'قطع نشست FTP',
            'addon_add' => 'افزودن دامنه', 'addon_del' => 'حذف دامنه', 'park_add' => 'پارک دامنه',
            'email_bulk_add' => 'ساخت گروهی ایمیل', 'email_bulk_del' => 'حذف گروهی ایمیل',
            'email_settings' => 'دیدن تنظیمات ایمیل', 'site_check' => 'بررسی سلامت دامنه',
            'dns_template' => 'اجرای قالب DNS', 'dns_export' => 'خروجی فایل زون',
            'file_transfer' => 'انتقال فایل بین دو هاست', 'srv_compare' => 'مقایسه‌ی دو هاست',
            'php_test' => 'تست زنده‌ی PHP', 'hotlink' => 'جلوگیری از هات‌لینک',
            'schedule_op' => 'زمان‌بندی کار', 'queue_del' => 'حذف کار زمان‌بندی‌شده',
            'mail_records' => 'بررسی رکوردهای ایمیل', 'watch_domains' => 'تنظیم دامنه‌های تحت‌نظر',
            'backup_schedule' => 'تنظیم بکاپ خودکار', 'guest_invite' => 'ساخت دسترسی موقت',
            'whm_bulk' => 'تعلیق/رفع تعلیق گروهی', 'export_csv' => 'خروجی CSV',
            'whm_user' => 'انتخاب کاربر مقصد', 'whm_summary' => 'دیدن جزئیات حساب',
            'site_copy' => 'کپی سایت',
            'filter_add' => 'ساخت فیلتر ایمیل', 'filter_del' => 'حذف فیلتر ایمیل',
            'mail_track' => 'ردیابی ارسال ایمیل', 'db_export' => 'خروجی SQL دیتابیس',
            'file_grep' => 'جست‌وجوی متن در فایل‌ها', 'dir_size' => 'اندازه‌گیری پوشه',
            'file_prune' => 'حذف فایل‌های قدیمی', 'harden' => 'سخت‌سازی سایت',
            'dns_check' => 'مقایسه‌ی DNS با بیرون', 'ssl_install' => 'نصب گواهی دستی',
            'ssl_del' => 'حذف گواهی', 'php_limit_set' => 'تغییر محدودیت PHP',
            'cron_template' => 'ساخت کران آماده', 'backup_download' => 'دانلود بکاپ',
            'sshkey_add' => 'افزودن کلید SSH', 'sshkey_del' => 'حذف کلید SSH',
            'whm_quota' => 'تغییر سهمیه‌ی حساب',
            'filter_toggle' => 'روشن/خاموش کردن فیلتر', 'email_suspend' => 'تعلیق صندوق ایمیل',
            'catchall' => 'صندوق پیش‌فرض دامنه', 'db_setup' => 'راه‌اندازی کامل دیتابیس',
            'error_pages' => 'صفحه‌ی خطای سفارشی', 'park_del' => 'حذف دامنه‌ی پارک‌شده',
            'dns_ttl' => 'تغییر TTL زون', 'ssl_csr' => 'ساخت CSR',
            'trash_restore' => 'بازگردانی از سطل زباله', 'file_from_url' => 'گرفتن فایل از اینترنت',
            'webdisk_add' => 'ساخت حساب وب‌دیسک', 'webdisk_del' => 'حذف حساب وب‌دیسک',
            'cron_pause' => 'توقف موقت کران', 'cron_resume' => 'برگرداندن کران',
            'php_set_all' => 'تغییر نسخه PHP همه‌ی دامنه‌ها', 'backup_del' => 'حذف بکاپ',
            'backup_ftp' => 'بکاپ روی FTP راه دور', 'whm_find' => 'پیدا کردن حساب از روی دامنه',
            'srv_rename' => 'تغییر نام سرور', 'srv_token' => 'تغییر توکن سرور',
            'watch_files' => 'تنظیم فایل‌های تحت‌نظر',
        ];

        $out = ['🧾 <b>آخرین عملیات‌ها</b>', ''];
        foreach ($lines as $line) {
            $entry = json_decode($line, true);
            if (!is_array($entry)) {
                continue;
            }

            $action = (string) ($entry['action'] ?? '');
            $label = $labels[$action] ?? $action;
            $time = substr((string) ($entry['time'] ?? ''), 5, 11);
            $time = str_replace('T', ' ', $time);

            $context = is_array($entry['context'] ?? null) ? $entry['context'] : [];
            $target = '';
            foreach (['email', 'name', 'user', 'domain', 'path', 'host', 'id', 'zone', 'vhost'] as $key) {
                if (isset($context[$key]) && is_scalar($context[$key]) && trim((string) $context[$key]) !== '') {
                    $target = (string) $context[$key];
                    break;
                }
            }

            $out[] = (($entry['ok'] ?? true) ? '✅' : '❌') . " <b>{$label}</b>"
                . ($target !== '' ? ' — <code>' . self::e(mb_substr($target, 0, 40)) . '</code>' : '')
                . "\n   🕐 {$time}";
        }

        return implode("\n", $out);
    }

    /**
     * @param array{roots: array<int, string>, writable: array<int, array{path: string, perm: string, dir: bool}>, exposed: array<int, array{path: string, why: string}>, scanned: int} $report
     */
    public static function security(array $report): string
    {
        $lines = ['🛡 <b>گزارش امنیتی پوشه‌های سایت</b>', ''];
        $lines[] = '📂 پوشه‌های بررسی‌شده: ' . $report['scanned'];
        foreach ($report['roots'] as $root) {
            $lines[] = '   • <code>' . self::e($root) . '</code>';
        }
        $lines[] = '';

        if ($report['exposed'] === [] && $report['writable'] === []) {
            $lines[] = '✅ چیز نگران‌کننده‌ای پیدا نشد؛ نه فایل حساسی داخل پوشه‌ی سایت بود و نه دسترسی بازی.';

            return implode("\n", $lines);
        }

        if ($report['exposed'] !== []) {
            $lines[] = '<b>📥 فایل‌هایی که از روی وب قابل دانلودند:</b>';
            foreach (array_slice($report['exposed'], 0, 15) as $item) {
                $lines[] = '• <code>' . self::e($item['path']) . '</code>';
                $lines[] = '   ↳ ' . self::e($item['why']);
            }
            if (count($report['exposed']) > 15) {
                $lines[] = '… و ' . (count($report['exposed']) - 15) . ' مورد دیگر.';
            }
            $lines[] = '';
        }

        if ($report['writable'] !== []) {
            $lines[] = '<b>🔓 دسترسی «نوشتن برای همه» (۷۷۷ و مانندش):</b>';
            foreach (array_slice($report['writable'], 0, 15) as $item) {
                $lines[] = '• ' . ($item['dir'] ? '📁' : '📄') . ' <code>' . self::e($item['path'])
                    . '</code> — ' . self::e($item['perm']);
            }
            if (count($report['writable']) > 15) {
                $lines[] = '… و ' . (count($report['writable']) - 15) . ' مورد دیگر.';
            }
            $lines[] = '';
        }

        $lines[] = '<b>چه کار کنم؟</b>';
        if ($report['exposed'] !== []) {
            $lines[] = '• فایل‌های حساس را پاک کنید یا از پوشه‌ی سایت بیرون ببرید («📂 فایل‌ها → 🗑 حذف» یا «📦 جابه‌جایی»).';
        }
        if ($report['writable'] !== []) {
            $lines[] = '• دسترسی پوشه‌ها را ۷۵۵ و فایل‌ها را ۶۴۴ بگذارید («📂 فایل‌ها → 🔐 سطح دسترسی»).';
        }
        $lines[] = 'ℹ️ فقط پوشه‌های سایت گشته می‌شوند؛ فایل بیرون از آن‌ها از وب دیده نمی‌شود.';

        return implode("\n", $lines);
    }

    /**
     * @param array{label: string, host: string, user: string, main_domain: string, cpanel: string, php: string, usage: array<int, array{label: string, percent: ?float}>, counts: array<string, int>, error: string} $left
     * @param array{label: string, host: string, user: string, main_domain: string, cpanel: string, php: string, usage: array<int, array{label: string, percent: ?float}>, counts: array<string, int>, error: string} $right
     */
    public static function serverCompare(array $left, array $right): string
    {
        $lines = ['⚖️ <b>مقایسه‌ی دو هاست</b>', ''];

        foreach ([$left, $right] as $index => $side) {
            $lines[] = ($index === 0 ? '🅰️' : '🅱️') . ' <b>' . self::e($side['label']) . '</b>';
            if ($side['error'] !== '') {
                $lines[] = '   ⚠️ ' . self::e($side['error']);
                $lines[] = '';
                continue;
            }

            if ($side['user'] !== '') {
                $lines[] = '   👤 کاربر: <code>' . self::e($side['user']) . '</code>';
            }
            if ($side['main_domain'] !== '') {
                $lines[] = '   🌐 دامنه‌ی اصلی: <code>' . self::e($side['main_domain']) . '</code>';
            }
            foreach ($side['usage'] as $item) {
                $lines[] = '   ' . self::e($item['label']) . ': ' . Format::percent($item['percent'])
                    . ' ' . Format::bar($item['percent'], 8);
            }
            $counts = $side['counts'];
            $lines[] = '   ✉️ ' . ($counts['emails'] ?? 0) . '   🗄 ' . ($counts['databases'] ?? 0)
                . '   📁 ' . ($counts['ftp'] ?? 0) . '   🌐 ' . ($counts['subdomains'] ?? 0)
                . '+' . ($counts['addon'] ?? 0);
            if ($side['php'] !== '') {
                $lines[] = '   🐘 PHP: <code>' . self::e($side['php']) . '</code>';
            }
            if ($side['cpanel'] !== '') {
                $lines[] = '   🧩 cPanel: <code>' . self::e($side['cpanel']) . '</code>';
            }
            $lines[] = '';
        }

        $differences = [];
        if ($left['error'] === '' && $right['error'] === '') {
            if ($left['php'] !== $right['php'] && $left['php'] !== '' && $right['php'] !== '') {
                $differences[] = 'نسخه‌ی PHP یکی نیست: ' . $left['php'] . ' ↔ ' . $right['php'];
            }
            if ($left['cpanel'] !== $right['cpanel'] && $left['cpanel'] !== '' && $right['cpanel'] !== '') {
                $differences[] = 'نسخه‌ی cPanel یکی نیست: ' . $left['cpanel'] . ' ↔ ' . $right['cpanel'];
            }
            foreach (['emails' => 'تعداد ایمیل', 'databases' => 'تعداد دیتابیس', 'ftp' => 'تعداد FTP'] as $key => $title) {
                if (($left['counts'][$key] ?? 0) !== ($right['counts'][$key] ?? 0)) {
                    $differences[] = $title . ': ' . ($left['counts'][$key] ?? 0) . ' ↔ ' . ($right['counts'][$key] ?? 0);
                }
            }
        }

        if ($differences !== []) {
            $lines[] = '<b>تفاوت‌ها:</b>';
            foreach ($differences as $difference) {
                $lines[] = '• ' . self::e($difference);
            }
        } elseif ($left['error'] === '' && $right['error'] === '') {
            $lines[] = '✅ در موارد بالا تفاوتی نداشتند.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param array{rows: array<int, array{email: string, used: float, quota: mixed, percent: ?float}>, total: float, full: array<int, string>} $usage
     */
    public static function mailboxUsage(array $usage): string
    {
        if ($usage['rows'] === []) {
            return '📊 صندوق ایمیلی روی این حساب نیست.';
        }

        $lines = [
            '📊 <b>مصرف صندوق‌های ایمیل</b> (' . count($usage['rows']) . ')',
            '',
            'مجموع: ' . Format::bytes($usage['total']),
            '',
        ];

        foreach (array_slice($usage['rows'], 0, 15) as $row) {
            $line = '• <code>' . self::e($row['email']) . '</code> — ' . Format::bytes($row['used']);
            if ($row['percent'] !== null) {
                $line .= ' ' . Format::bar($row['percent'], 8) . ' ' . Format::percent($row['percent']);
            }
            $lines[] = $line;
        }
        if (count($usage['rows']) > 15) {
            $lines[] = '… و ' . (count($usage['rows']) - 15) . ' صندوق دیگر.';
        }

        if ($usage['full'] !== []) {
            $lines[] = '';
            $lines[] = '⚠️ نزدیک پرشدن (بالای ۹۰٪): ' . self::e(implode('، ', $usage['full']));
            $lines[] = '💡 «🧽 پاک‌سازی Trash و Spam» یا «📦 سهمیه» معمولاً همین‌جا حلش می‌کند.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param array{rows: array<int, array{name: string, size: float, users: int}>, total: float, orphans: array<int, string>} $sizes
     */
    public static function databaseSizes(array $sizes): string
    {
        if ($sizes['rows'] === []) {
            return '📊 دیتابیسی روی این حساب نیست.';
        }

        $lines = [
            '📊 <b>حجم دیتابیس‌ها</b> (' . count($sizes['rows']) . ')',
            '',
            'مجموع: ' . Format::bytes($sizes['total']),
            '',
        ];

        $biggest = $sizes['rows'][0]['size'] ?? 0.0;
        foreach ($sizes['rows'] as $row) {
            $share = $biggest > 0 ? $row['size'] / $biggest * 100 : 0.0;
            $lines[] = '• <code>' . self::e($row['name']) . '</code> — ' . Format::bytes($row['size'])
                . ' ' . Format::bar($share, 8)
                . ($row['users'] === 0 ? '  ⚠️ بدون کاربر' : '');
        }

        if ($sizes['orphans'] !== []) {
            $lines[] = '';
            $lines[] = '⚠️ این دیتابیس‌ها هیچ کاربری ندارند، یعنی هیچ سایتی نمی‌تواند به آن‌ها وصل شود:';
            $lines[] = self::e(implode('، ', $sizes['orphans']));
            $lines[] = '💡 یا با «🔗 وصل‌کردن کاربر به دیتابیس» کاربر بدهید، یا اگر بلااستفاده‌اند حذفشان کنید.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param array{domain: string, url: string, ok: bool, status: int, values: array<string, string>, error: string, cleaned: bool} $result
     */
    public static function phpTest(array $result): string
    {
        $lines = ['🧪 <b>تست زنده‌ی PHP روی ' . self::e($result['domain']) . '</b>', ''];

        if (!$result['ok']) {
            $lines[] = '❌ تست نتیجه نداد.';
            if ($result['error'] !== '') {
                $lines[] = '';
                $lines[] = self::e($result['error']);
            }
            $lines[] = '';
            $lines[] = '💡 اگر دامنه تازه وصل شده یا پشت کلادفلر است، ممکن است از بیرون به خود هاست نرسیده باشیم.';
            $lines[] = $result['cleaned']
                ? 'ℹ️ فایل تست پاک شد.'
                : '⚠️ فایل تست پاک نشد؛ آن را دستی از پوشه‌ی سایت بردارید.';

            return implode("\n", $lines);
        }

        $lines[] = '✅ وب‌سرور فایل PHP را اجرا کرد (کد ' . $result['status'] . ').';
        $lines[] = '';
        foreach ($result['values'] as $key => $value) {
            $lines[] = '• ' . self::e($key) . ': <code>' . self::e($value) . '</code>';
        }
        $lines[] = '';
        $lines[] = 'ℹ️ این‌ها همان چیزی است که سایت واقعاً با آن اجرا می‌شود، نه آنچه در تنظیمات نوشته شده.';
        $lines[] = $result['cleaned']
            ? 'ℹ️ فایل تست پاک شد.'
            : '⚠️ فایل تست پاک نشد؛ آن را دستی از پوشه‌ی سایت بردارید.';

        return implode("\n", $lines);
    }

    /**
     * @param array{added: array<int, string>, skipped: array<int, string>, failed: array<int, string>} $result
     */
    public static function dnsTemplate(string $zone, string $label, array $result): string
    {
        $lines = ['🧩 <b>قالب ' . self::e($label) . '</b> روی <code>' . self::e($zone) . '</code>', ''];

        if ($result['added'] !== []) {
            $lines[] = '✅ اضافه شد:';
            foreach ($result['added'] as $record) {
                $lines[] = '• <code>' . self::e($record) . '</code>';
            }
            $lines[] = '';
        }
        if ($result['skipped'] !== []) {
            $lines[] = '⏭ از قبل بود و دست‌نخورده ماند:';
            foreach ($result['skipped'] as $record) {
                $lines[] = '• <code>' . self::e($record) . '</code>';
            }
            $lines[] = '';
        }
        if ($result['failed'] !== []) {
            $lines[] = '❌ ساخته نشد:';
            foreach ($result['failed'] as $record) {
                $lines[] = '• ' . self::e($record);
            }
            $lines[] = '';
        }

        $lines[] = 'ℹ️ تغییر DNS معمولاً بین چند دقیقه تا چند ساعت طول می‌کشد تا همه‌جا اثر کند.';

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array{id: string, action: string, params: array<string, string>, run_at: int, server: string, created: int}> $items
     */
    public static function queue(array $items): string
    {
        if ($items === []) {
            return "⏳ <b>کارهای زمان‌بندی‌شده</b>\n\nصف خالی است.\n\n"
                . '💡 می‌توانید بکاپ نصفه‌شب، خاموش‌کردن حالت تعمیر بعد از به‌روزرسانی یا گزارش وضعیت را از پیش زمان‌بندی کنید.';
        }

        $lines = ['⏳ <b>کارهای زمان‌بندی‌شده</b> (' . count($items) . ')', ''];
        foreach ($items as $item) {
            $lines[] = '• ' . self::e(OpQueue::label($item));
            $lines[] = '   📅 ' . self::e(Format::timestampWithRemaining($item['run_at']))
                . ($item['server'] !== '' ? ' — <code>' . self::e($item['server']) . '</code>' : '');
        }
        $lines[] = '';
        $lines[] = 'ℹ️ کارها با همان تایمری اجرا می‌شوند که هشدارها را می‌فرستد، پس تا ۱۵ دقیقه اختلاف طبیعی است.';

        return implode("\n", $lines);
    }

    /**
     * @param array{checks: array<int, array{label: string, state: string, detail: string, hint: string}>, score: int} $health
     */
    public static function health(array $health): string
    {
        if ($health['checks'] === []) {
            return '🩺 چیزی برای بررسی پیدا نشد؛ اول یک هاست فعال کنید.';
        }

        $bad = 0;
        $warn = 0;
        foreach ($health['checks'] as $check) {
            if ($check['state'] === 'bad') {
                $bad++;
            } elseif ($check['state'] === 'warn') {
                $warn++;
            }
        }

        $mood = $bad > 0 ? '🔴' : ($warn > 0 ? '🟡' : '🟢');
        $lines = [
            '🩺 <b>چک‌آپ کامل هاست</b>',
            '',
            $mood . ' نمره‌ی سلامت: <b>' . $health['score'] . '</b> از ۱۰۰'
                . ($bad > 0 ? "   ({$bad} مورد جدی)" : ($warn > 0 ? "   ({$warn} مورد قابل بهبود)" : '')),
            '',
        ];

        foreach ($health['checks'] as $check) {
            $icon = match ($check['state']) {
                'ok' => '✅',
                'warn' => '⚠️',
                default => '❌',
            };
            $lines[] = $icon . ' <b>' . self::e($check['label']) . '</b> — ' . self::e($check['detail']);
            if ($check['hint'] !== '') {
                $lines[] = '   💡 ' . self::e($check['hint']);
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array{name: string, enabled: bool, rules: string}> $filters
     */
    public static function emailFilters(array $filters): string
    {
        if ($filters === []) {
            return "🧹 <b>فیلترهای ایمیل</b>\n\nهنوز فیلتری تعریف نشده.\n\n"
                . '💡 فیلتر یعنی «هر نامه‌ای که موضوعش فلان بود، بینداز در پوشه‌ی فلان یا اصلاً نگیرش».';
        }

        $lines = ['🧹 <b>فیلترهای ایمیل</b> (' . count($filters) . ')', ''];
        foreach ($filters as $filter) {
            $lines[] = ($filter['enabled'] ? '✅' : '⏸') . ' <code>' . self::e($filter['name']) . '</code>';
            if ($filter['rules'] !== '') {
                $lines[] = '   ' . self::e(mb_substr($filter['rules'], 0, 120));
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array{time: ?int, sender: string, recipient: string, status: string, message: string}> $rows
     */
    public static function mailDelivery(string $recipient, array $rows): string
    {
        $title = '📨 <b>ردیابی ارسال ایمیل</b>'
            . ($recipient !== '' ? ' — <code>' . self::e($recipient) . '</code>' : '');

        if ($rows === []) {
            return $title . "\n\nنامه‌ای در بازه‌ی اخیر پیدا نشد.\n\n"
                . '💡 اگر مطمئنید نامه فرستاده شده، ممکن است سرور لاگش را نگه نداشته باشد.';
        }

        $lines = [$title, ''];
        foreach ($rows as $row) {
            $icon = match ($row['status']) {
                'تحویل شد' => '✅',
                'در صف' => '⏳',
                default => '❌',
            };
            $lines[] = $icon . ' <code>' . self::e($row['recipient']) . '</code> — ' . self::e($row['status']);
            if ($row['sender'] !== '') {
                $lines[] = '   از: <code>' . self::e($row['sender']) . '</code>';
            }
            if ($row['time'] !== null) {
                $lines[] = '   🕐 ' . self::e(Format::timestampWithRemaining($row['time']));
            }
            if ($row['message'] !== '' && $row['status'] !== 'تحویل شد') {
                $lines[] = '   ↳ ' . self::e(mb_substr($row['message'], 0, 140));
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array{matches: array<int, array{path: string, line: int, text: string}>, scanned: int, skipped: int, complete: bool} $result
     */
    public static function grep(string $needle, array $result): string
    {
        $header = '🔍 <b>جست‌وجوی</b> <code>' . self::e($needle) . '</code>';

        if ($result['matches'] === []) {
            return $header . "\n\nچیزی پیدا نشد. " . $result['scanned'] . ' فایل متنی گشته شد'
                . ($result['skipped'] > 0 ? ' و ' . $result['skipped'] . ' فایل بزرگ یا باینری رد شد' : '') . '.';
        }

        $lines = [$header, '', count($result['matches']) . ' مورد در ' . $result['scanned'] . ' فایل:', ''];
        $lastPath = '';
        foreach ($result['matches'] as $match) {
            if ($match['path'] !== $lastPath) {
                $lines[] = '📄 <code>' . self::e($match['path']) . '</code>';
                $lastPath = $match['path'];
            }
            $lines[] = '   ' . $match['line'] . ': <code>' . self::e($match['text']) . '</code>';
        }

        if (!$result['complete']) {
            $lines[] = '';
            $lines[] = 'ℹ️ به سقف فایل‌های قابل بررسی رسیدیم؛ برای نتیجه‌ی کامل‌تر پوشه‌ی دقیق‌تری بدهید.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param array{path: string, size: float, files: int, dirs: int, complete: bool, biggest: array<int, array{path: string, size: float}>} $info
     */
    public static function directorySize(array $info): string
    {
        $lines = [
            '📏 <b>اندازه‌ی پوشه</b>',
            '<code>' . self::e($info['path']) . '</code>',
            '',
            'مجموع: <b>' . Format::bytes($info['size']) . '</b>',
            $info['files'] . ' فایل در ' . $info['dirs'] . ' پوشه',
        ];

        if ($info['biggest'] !== []) {
            $lines[] = '';
            $lines[] = '<b>سنگین‌ترین پوشه‌ها:</b>';
            foreach ($info['biggest'] as $row) {
                $share = $info['size'] > 0 ? $row['size'] / $info['size'] * 100 : 0.0;
                $lines[] = '• <code>' . self::e($row['path']) . '</code> — ' . Format::bytes($row['size'])
                    . ' ' . Format::bar($share, 8);
            }
        }

        if (!$info['complete']) {
            $lines[] = '';
            $lines[] = 'ℹ️ پوشه‌های خیلی تودرتو تا سقف مشخصی شمرده شدند، پس عدد بالا کمینه‌ی اندازه‌ی واقعی است.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array{path: string, name: string, size: float, mtime: int}> $files
     */
    public static function oldFiles(string $dir, int $days, array $files): string
    {
        if ($files === []) {
            return '🧯 در <code>' . self::e($dir) . '</code> فایلی قدیمی‌تر از ' . $days . ' روز نبود.';
        }

        $total = 0.0;
        foreach ($files as $file) {
            $total += $file['size'];
        }

        $lines = [
            '🧯 <b>فایل‌های قدیمی‌تر از ' . $days . ' روز</b>',
            '<code>' . self::e($dir) . '</code>',
            '',
            count($files) . ' فایل، مجموعاً ' . Format::bytes($total),
            '',
        ];
        foreach (array_slice($files, 0, 12) as $file) {
            $age = (int) floor((time() - $file['mtime']) / 86400);
            $lines[] = '• <code>' . self::e($file['name']) . '</code> — ' . Format::bytes($file['size'])
                . " ({$age} روز)";
        }
        if (count($files) > 12) {
            $lines[] = '… و ' . (count($files) - 12) . ' فایل دیگر.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param array{domain: string, rows: array<int, array{type: string, name: string, zone: array<int, string>, world: array<int, string>, state: string}>, problems: array<int, string>} $result
     */
    public static function dnsCompare(array $result): string
    {
        $lines = ['🌍 <b>DNS از دید دنیای بیرون</b> — <code>' . self::e($result['domain']) . '</code>', ''];

        if ($result['rows'] === []) {
            $lines[] = 'هیچ رکوردی نه روی هاست بود و نه از بیرون دیده شد.';
        }

        foreach ($result['rows'] as $row) {
            $icon = match ($row['state']) {
                'match' => '✅',
                'differs' => '⚠️',
                'missing' => '❌',
                default => 'ℹ️',
            };
            $lines[] = $icon . ' <b>' . self::e($row['type']) . '</b> <code>' . self::e($row['name']) . '</code>';
            $lines[] = '   روی هاست: ' . ($row['zone'] === [] ? '—' : '<code>' . self::e(implode('، ', $row['zone'])) . '</code>');
            $lines[] = '   از بیرون: ' . ($row['world'] === [] ? '—' : '<code>' . self::e(implode('، ', $row['world'])) . '</code>');
        }

        if ($result['problems'] === []) {
            $lines[] = '';
            $lines[] = '✅ چیزی که روی هاست تنظیم شده، همان چیزی است که دنیا می‌بیند.';

            return implode("\n", $lines);
        }

        $lines[] = '';
        $lines[] = '<b>چه چیزی می‌لنگد:</b>';
        foreach ($result['problems'] as $problem) {
            $lines[] = '• ' . self::e($problem);
        }
        $lines[] = '';
        $lines[] = 'ℹ️ تغییر DNS تا چند ساعت طول می‌کشد؛ اگر همین حالا عوضش کردید، کمی بعد دوباره بررسی کنید.';

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array{name: string, type: string, authorized: bool, fingerprint: string}> $keys
     */
    public static function sshKeys(array $keys): string
    {
        if ($keys === []) {
            return "🔑 <b>کلیدهای SSH</b>\n\nکلیدی روی این حساب نیست.\n\n"
                . '💡 با کلید SSH می‌شود بدون رمز به هاست وصل شد و از ابزارهایی مثل rsync و Git استفاده کرد.';
        }

        $lines = ['🔑 <b>کلیدهای SSH</b> (' . count($keys) . ')', ''];
        foreach ($keys as $key) {
            $lines[] = ($key['authorized'] ? '✅' : '⛔️') . ' <code>' . self::e($key['name']) . '</code>'
                . ($key['type'] !== '' ? ' — ' . self::e($key['type']) : '')
                . ($key['authorized'] ? '' : ' (مجاز نشده)');
            if ($key['fingerprint'] !== '') {
                $lines[] = '   <code>' . self::e(mb_substr($key['fingerprint'], 0, 60)) . '</code>';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array{ip: string, used: bool, dedicated: bool}> $ips
     */
    public static function whmIps(array $ips): string
    {
        if ($ips === []) {
            return '🖧 آی‌پی‌ای گزارش نشد.';
        }

        $free = 0;
        $lines = ['🖧 <b>آی‌پی‌های سرور</b> (' . count($ips) . ')', ''];
        foreach ($ips as $ip) {
            if (!$ip['used']) {
                $free++;
            }
            $lines[] = ($ip['used'] ? '🔵' : '⚪️') . ' <code>' . self::e($ip['ip']) . '</code>'
                . ($ip['used'] ? ' — در حال استفاده' : ' — آزاد')
                . ($ip['dedicated'] ? ' (اختصاصی)' : '');
        }
        $lines[] = '';
        $lines[] = $free > 0 ? "{$free} آی‌پی آزاد برای دادن به حساب‌های جدید هست." : 'همه‌ی آی‌پی‌ها در حال استفاده‌اند.';

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array{user: string, reason: string, time: string}> $accounts
     */
    public static function whmSuspended(array $accounts): string
    {
        if ($accounts === []) {
            return '✅ هیچ حساب معلقی روی این سرور نیست.';
        }

        $lines = ['⛔️ <b>حساب‌های معلق</b> (' . count($accounts) . ')', ''];
        foreach ($accounts as $account) {
            $lines[] = '• <code>' . self::e($account['user']) . '</code>';
            if ($account['reason'] !== '') {
                $lines[] = '   دلیل: ' . self::e($account['reason']);
            }
            if ($account['time'] !== '') {
                $lines[] = '   🕐 ' . self::e($account['time']);
            }
        }
        $lines[] = '';
        $lines[] = '💡 با «✅ رفع تعلیق» هرکدام را برمی‌گردانید.';

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array{name: string, path: string, dir: bool, size: ?float, mtime: ?int, permissions: string}> $items
     */
    public static function trash(array $items): string
    {
        if ($items === []) {
            return "🗑 <b>سطل زباله</b>\n\nخالی است.\n\n"
                . 'ℹ️ هرچه از فایل‌منیجر cPanel حذف شود اول اینجا می‌آید و می‌شود برش گرداند.';
        }

        $total = 0.0;
        foreach ($items as $item) {
            $total += (float) ($item['size'] ?? 0);
        }

        $lines = ['🗑 <b>سطل زباله</b> (' . count($items) . ') — ' . Format::bytes($total), ''];
        foreach (array_slice($items, 0, 15) as $item) {
            $line = ($item['dir'] ? '📁' : '📄') . ' <code>' . self::e($item['name']) . '</code>';
            if (($item['size'] ?? null) !== null && !$item['dir']) {
                $line .= ' — ' . Format::bytes($item['size']);
            }
            $lines[] = $line;
        }
        if (count($items) > 15) {
            $lines[] = '… و ' . (count($items) - 15) . ' مورد دیگر.';
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array{login: string, dir: string, private: bool}> $users
     */
    public static function webDisk(array $users): string
    {
        if ($users === []) {
            return "💽 <b>وب‌دیسک</b>\n\nهنوز حسابی ساخته نشده.\n\n"
                . '💡 وب‌دیسک پوشه‌ی هاست را مثل یک درایو در ویندوز/مک بالا می‌آورد؛ فایل را می‌کشید و رها می‌کنید.';
        }

        $lines = ['💽 <b>حساب‌های وب‌دیسک</b> (' . count($users) . ')', ''];
        foreach ($users as $user) {
            $lines[] = '• <code>' . self::e($user['login']) . '</code>'
                . ($user['private'] ? ' 🔒' : '');
            if ($user['dir'] !== '') {
                $lines[] = '   📂 <code>' . self::e($user['dir']) . '</code>';
            }
        }

        return implode("\n", $lines);
    }

    /**
     * @param array<int, array{id: string, server: string, schedule: string, command: string, paused_at: int}> $items
     */
    public static function pausedCrons(array $items): string
    {
        if ($items === []) {
            return '⏸ کران متوقف‌شده‌ای نیست.';
        }

        $lines = ['⏸ <b>کران‌های متوقف‌شده</b> (' . count($items) . ')', ''];
        foreach ($items as $item) {
            $lines[] = '• <code>' . self::e($item['schedule']) . '</code> — <code>'
                . self::e(mb_substr($item['command'], 0, 70)) . '</code>';
            if ($item['paused_at'] > 0) {
                $lines[] = '   ⏱ از ' . self::e(Format::timestampWithRemaining($item['paused_at']));
            }
        }
        $lines[] = '';
        $lines[] = 'ℹ️ تا وقتی برنگردانید، سرور اجرایشان نمی‌کند.';

        return implode("\n", $lines);
    }

    /**
     * @param array{accounts: int, suspended: int, disk: float, plans: array<string, int>, newest: array<int, string>} $overview
     */
    public static function whmOverview(array $overview): string
    {
        $lines = [
            '📈 <b>نمای کلی سرور</b>',
            '',
            '👥 حساب‌ها: <b>' . $overview['accounts'] . '</b>'
                . ($overview['suspended'] > 0 ? '   ⛔️ معلق: ' . $overview['suspended'] : ''),
            '💽 مجموع مصرف دیسک: ' . Format::bytes($overview['disk']),
        ];

        if ($overview['plans'] !== []) {
            $lines[] = '';
            $lines[] = '<b>پلن‌ها:</b>';
            foreach (array_slice($overview['plans'], 0, 8, true) as $plan => $count) {
                $lines[] = '• <code>' . self::e((string) $plan) . '</code> — ' . $count . ' حساب';
            }
        }

        if ($overview['newest'] !== []) {
            $lines[] = '';
            $lines[] = '🆕 تازه‌ترین حساب‌ها: <code>' . self::e(implode('، ', $overview['newest'])) . '</code>';
        }

        return implode("\n", $lines);
    }

    /**
     * @param array{database: string, user: string, password: string, host: string, done: array<int, string>} $result
     */
    public static function databaseSetup(array $result): string
    {
        return implode("\n", [
            '✅ <b>دیتابیس آماده شد</b>',
            '',
            '🗄 نام دیتابیس: <code>' . self::e($result['database']) . '</code>',
            '👤 کاربر: <code>' . self::e($result['user']) . '</code>',
            '🔑 رمز: <code>' . self::e($result['password']) . '</code>',
            '🖥 میزبان: <code>' . self::e($result['host']) . '</code>',
            '',
            'همین چهار مقدار را در فایل تنظیمات سایت بگذارید (مثل <code>wp-config.php</code>).',
            '',
            '⚠️ رمز را همین حالا جای امنی ذخیره کنید؛ دیگر نشانتان نمی‌دهم.',
        ]);
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
