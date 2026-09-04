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
            $domains = CpanelService::domains($client);
            $watch = array_slice(array_values(array_filter(array_merge(
                [$domains['main_domain']],
                $domains['addon_domains'],
            ))), 0, 5);

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

        self::saveState($state);

        return $messages;
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
