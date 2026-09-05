<?php

declare(strict_types=1);

/**
 * هشدارهای خودکار و گزارش دوره‌ای.
 *
 * یک اجرای کوتاه که با کران/تایمر صدا زده می‌شود، وضعیت هاست را می‌سنجد و
 * فقط وقتی چیزی واقعاً مشکل دارد به ادمین‌ها پیام می‌دهد. هر هشدار تا وقتی
 * وضعیتش عوض نشده دوباره فرستاده نمی‌شود (تا ربات هر ربع ساعت تکرار نکند).
 */
final class Alerts
{
    private const REPEAT_AFTER = 21600; // ۶ ساعت

    /**
     * @return array<int, string> پیام‌هایی که باید فرستاده شوند
     */
    public static function collect(CpanelClient $client): array
    {
        $messages = [];
        $state = self::loadState();
        $now = time();

        $usage = CpanelService::resourceUsage($client);
        UsageHistory::record($usage);

        // ---- دیسک و اینود
        foreach ($usage as $item) {
            $percent = $item['percent'];
            if ($percent === null) {
                continue;
            }
            $id = strtolower($item['id']);
            $threshold = null;
            if (str_contains($id, 'disk')) {
                $threshold = Settings::int(Settings::ALERT_DISK, 85);
            } elseif (str_contains($id, 'inode')) {
                $threshold = Settings::int(Settings::ALERT_INODE, 85);
            } elseif (str_contains($id, 'bandwidth') || str_contains($id, 'bw')) {
                $threshold = Settings::int(Settings::ALERT_BANDWIDTH, 85);
            }
            if ($threshold === null || $threshold <= 0) {
                continue;
            }

            $key = 'usage:' . $id;
            if ($percent >= $threshold) {
                if (self::shouldNotify($state, $key, $now)) {
                    $messages[] = "⚠️ <b>{$item['label']}</b> به " . Format::percent($percent)
                        . " رسید (آستانه: {$threshold}٪).\n\n💡 «📂 فایل‌ها → 🧹 پاک‌سازی هوشمند» را بزنید تا ببینید چه چیزی فضا گرفته.";
                }
            } else {
                unset($state[$key]);
            }
        }

        // ---- انقضای گواهی SSL
        $sslDays = Settings::int(Settings::ALERT_SSL_DAYS, 14);
        if ($sslDays > 0) {
            foreach (CpanelService::sslHosts($client) as $host) {
                if (!is_numeric($host['expires'])) {
                    continue;
                }
                $days = (int) floor(((int) $host['expires'] - $now) / 86400);
                $key = 'ssl:' . $host['domain'];
                if ($days <= $sslDays) {
                    if (self::shouldNotify($state, $key, $now)) {
                        $messages[] = $days < 0
                            ? "🔒 گواهی SSL <code>{$host['domain']}</code> منقضی شده است."
                            : "🔒 گواهی SSL <code>{$host['domain']}</code> تا {$days} روز دیگر منقضی می‌شود.\n\n💡 «🌐 دامنه‌ها → 🔒 SSL → درخواست صدور/تمدید خودکار».";
                    }
                } else {
                    unset($state[$key]);
                }
            }
        }

        // ---- بالا بودن سایت
        if (Settings::bool(Settings::ALERT_SITE, true)) {
            $watch = self::watchList($client);

            foreach ($watch as $domain) {
                $result = SiteCheck::run($domain);
                $status = (int) ($result['https']['status'] ?? 0);
                $down = $status === 0 || $status >= 500;
                $key = 'site:' . $domain;

                if ($down) {
                    if (self::shouldNotify($state, $key, $now)) {
                        $messages[] = "🔴 <code>{$domain}</code> بالا نمی‌آید"
                            . ($status > 0 ? " (کد {$status})" : '')
                            . ".\n\n💡 «🌐 دامنه‌ها → 🩺 بررسی سلامت دامنه» جزئیات بیشتری می‌دهد.";
                    }
                } elseif (isset($state[$key])) {
                    unset($state[$key]);
                    $messages[] = "🟢 <code>{$domain}</code> دوباره بالا آمد.";
                }
            }
        }

        // ---- تغییر آی‌پی سرور
        $ip = '';
        foreach (CpanelService::domainsData($client) as $detail) {
            if ($detail['ip'] !== '') {
                $ip = $detail['ip'];
                break;
            }
        }
        if ($ip !== '') {
            $previous = Settings::get(Settings::LAST_SERVER_IP);
            if ($previous !== '' && $previous !== $ip) {
                $messages[] = "🖧 آی‌پی سرور عوض شد: <code>{$previous}</code> → <code>{$ip}</code>\n\n"
                    . '💡 اگر دامنه‌ای بیرون از این هاست DNS دارد، رکورد A آن را به‌روز کنید.';
            }
            Settings::set(Settings::LAST_SERVER_IP, $ip);
        }

        // ---- فایل‌های حساسی که نباید بی‌خبر عوض شوند
        foreach (self::watchedFiles($client) as $message) {
            $messages[] = $message;
        }

        // ---- کارهای زمان‌بندی‌شده‌ای که وقتشان رسیده
        foreach (self::runQueue($client) as $message) {
            $messages[] = $message;
        }

        // ---- بکاپ زمان‌بندی‌شده و نگه‌داشتن چند نسخه
        foreach (self::scheduledBackup($client) as $message) {
            $messages[] = $message;
        }

        self::saveState($state);

        return $messages;
    }

    /**
     * دامنه‌هایی که باید بالا بودنشان بررسی شود: فهرست دستی کاربر، وگرنه
     * دامنه‌ی اصلی و ادان‌ها.
     *
     * @return array<int, string>
     */
    public static function watchList(CpanelClient $client): array
    {
        $manual = array_values(array_filter(array_map(
            'trim',
            preg_split('/[\s,\n]+/', Settings::get(Settings::WATCH_DOMAINS)) ?: [],
        )));
        if ($manual !== []) {
            return array_slice($manual, 0, 10);
        }

        $domains = CpanelService::domains($client);

        return array_slice(array_values(array_filter(array_merge(
            [$domains['main_domain']],
            $domains['addon_domains'],
        ))), 0, 5);
    }

    /**
     * بکاپ خودکار (روزانه/هفتگی) به‌علاوه‌ی پاک‌سازی نسخه‌های قدیمی.
     *
     * @return array<int, string>
     */
    private static function scheduledBackup(CpanelClient $client): array
    {
        $every = Settings::get(Settings::BACKUP_EVERY, 'off');
        if ($every === 'off') {
            return [];
        }

        $state = self::loadState();
        $last = (int) ($state['backup:last'] ?? 0);
        $interval = $every === 'daily' ? 86400 : 604800;
        if (time() - $last < $interval) {
            return [];
        }

        $messages = [];
        try {
            CpanelService::fullBackup($client);
            $messages[] = '💾 بکاپ خودکار شروع شد؛ فایلش در پوشه‌ی هوم ساخته می‌شود.';

            $keep = Settings::int(Settings::BACKUP_KEEP, 3);
            if ($keep > 0) {
                $pruned = CpanelService::pruneBackups($client, $keep);
                if ($pruned['deleted'] !== []) {
                    $messages[] = '🧹 ' . count($pruned['deleted']) . ' بکاپ قدیمی پاک شد تا فقط '
                        . $keep . ' نسخه‌ی آخر بماند.';
                }
            }
        } catch (Throwable $e) {
            $messages[] = '⚠️ بکاپ خودکار انجام نشد: ' . $e->getMessage();
        }

        $state = self::loadState();
        $state['backup:last'] = time();
        self::saveState($state);

        return $messages;
    }

    /**
     * فایل‌هایی که کاربر زیر نظر گرفته: اگر محتوایشان عوض شود خبر می‌دهیم.
     *
     * تغییر ناگهانی <code>.htaccess</code> یا <code>wp-config.php</code>
     * معمولاً یا کار یک افزونه است یا نشانه‌ی نفوذ؛ هر دو ارزش دانستن دارند.
     *
     * @return array<int, string>
     */
    public static function watchedFiles(CpanelClient $client): array
    {
        $paths = array_values(array_filter(array_map(
            'trim',
            preg_split('/[\s,\n]+/', Settings::get(Settings::WATCH_FILES)) ?: [],
        )));
        if ($paths === []) {
            return [];
        }

        $state = self::loadState();
        $messages = [];

        foreach (array_slice($paths, 0, 10) as $path) {
            $content = self::softRead($client, $path);
            $key = 'file:' . substr(sha1($path), 0, 12);

            if ($content === null) {
                if (($state[$key] ?? 0) !== 0) {
                    $messages[] = "🗑 فایل تحت‌نظر <code>{$path}</code> دیگر خوانده نمی‌شود (پاک یا جابه‌جا شده).";
                    unset($state[$key]);
                }
                continue;
            }

            // فقط یک عدد نگه می‌داریم، نه خود فایل: هم سبک است هم محتوای
            // حساس (رمز دیتابیس) جایی ذخیره نمی‌شود.
            $hash = (int) hexdec(substr(sha1($content), 0, 12));
            $known = (int) ($state[$key] ?? 0);
            if ($known !== 0 && $known !== $hash) {
                $messages[] = "📝 فایل تحت‌نظر <code>{$path}</code> عوض شده است.\n\n"
                    . '💡 اگر کار خودتان نبوده، «📂 فایل‌ها → 🛡 گزارش امنیتی» و «↩️ بازگردانی آخرین بکاپ» را ببینید.';
            }
            $state[$key] = $hash;
        }

        self::saveState($state);

        return $messages;
    }

    private static function softRead(CpanelClient $client, string $path): ?string
    {
        try {
            return CpanelService::readFile($client, $path);
        } catch (Throwable $e) {
            return null;
        }
    }

    /**
     * کارهای زمان‌بندی‌شده‌ای که وقتشان رسیده: اجرا می‌شوند، از صف برداشته
     * می‌شوند و نتیجه‌شان به ادمین‌ها گزارش می‌شود.
     *
     * @return array<int, string>
     */
    public static function runQueue(CpanelClient $client): array
    {
        $server = (string) (ServerStore::activeId() ?? '');
        $messages = [];

        foreach (OpQueue::due($server) as $item) {
            try {
                $messages[] = self::runQueued($client, $item);
            } catch (Throwable $e) {
                $messages[] = '⚠️ کار زمان‌بندی‌شده انجام نشد — ' . OpQueue::label($item) . "\n" . $e->getMessage();
            }
            // چه موفق چه ناموفق، کار از صف برداشته می‌شود تا هر ربع ساعت تکرار نشود.
            OpQueue::remove($item['id']);
        }

        return $messages;
    }

    /**
     * @param array{id: string, action: string, params: array<string, string>, run_at: int, server: string, created: int} $item
     */
    private static function runQueued(CpanelClient $client, array $item): string
    {
        $label = OpQueue::label($item);

        switch ($item['action']) {
            case 'backup':
                CpanelService::fullBackup($client);

                return '⏳ کار زمان‌بندی‌شده انجام شد — ' . $label . "\n\nفایل بکاپ در پوشه‌ی هوم ساخته می‌شود.";

            case 'prune_backups':
                $keep = max(1, (int) ($item['params']['keep'] ?? Settings::int(Settings::BACKUP_KEEP, 3)));
                $pruned = CpanelService::pruneBackups($client, $keep);

                return '⏳ کار زمان‌بندی‌شده انجام شد — ' . $label . '؛ ' . count($pruned['deleted'])
                    . ' بکاپ قدیمی پاک شد و ' . $keep . ' نسخه‌ی آخر ماند.';

            case 'maintenance_on':
            case 'maintenance_off':
                $docroot = (string) ($item['params']['docroot'] ?? '');
                if ($docroot === '') {
                    throw CpanelError::config('پوشه‌ی سایت برای این کار ثبت نشده بود.');
                }
                CpanelService::setMaintenance(
                    $client,
                    $docroot,
                    $item['action'] === 'maintenance_on',
                    (string) ($item['params']['message'] ?? ''),
                );

                return '⏳ کار زمان‌بندی‌شده انجام شد — ' . $label;

            case 'report':
                return "📊 <b>گزارش زمان‌بندی‌شده</b>\n\n" . Render::summary(CpanelService::summary($client));
        }

        throw CpanelError::config("کار «{$item['action']}» پشتیبانی نمی‌شود.");
    }

    /**
     * آیا وقت گزارش دوره‌ای رسیده است؟
     */
    public static function reportDue(): bool
    {
        $every = Settings::get(Settings::REPORT_EVERY, 'weekly');
        if ($every === 'off') {
            return false;
        }

        $state = self::loadState();
        $last = (int) ($state['report:last'] ?? 0);
        $interval = $every === 'daily' ? 86400 : 604800;

        return time() - $last >= $interval;
    }

    public static function markReportSent(): void
    {
        $state = self::loadState();
        $state['report:last'] = time();
        self::saveState($state);
    }

    /**
     * @param array<string, int> $state
     */
    private static function shouldNotify(array &$state, string $key, int $now): bool
    {
        $last = (int) ($state[$key] ?? 0);
        if ($now - $last < self::REPEAT_AFTER) {
            return false;
        }
        $state[$key] = $now;

        return true;
    }

    /**
     * @return array<string, int>
     */
    private static function loadState(): array
    {
        $path = Paths::data('alerts.json');
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? array_map('intval', $decoded) : [];
    }

    /**
     * @param array<string, int> $state
     */
    private static function saveState(array $state): void
    {
        $path = Paths::ensure('alerts.json');
        file_put_contents($path, json_encode($state));
        @chmod($path, 0600);
    }
}
