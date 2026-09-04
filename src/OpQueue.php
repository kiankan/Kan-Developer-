<?php

declare(strict_types=1);

/**
 * صف کارهای زمان‌بندی‌شده.
 *
 * بعضی کارها را نمی‌شود همین حالا انجام داد: بکاپ نصفه‌شب، خاموش‌کردن حالت
 * تعمیر بعد از تمام‌شدن به‌روزرسانی، پاک‌کردن بکاپ‌های قدیمی آخر هفته. اینجا
 * کار با زمان اجرایش ثبت می‌شود و همان تایمری که هشدارها را می‌فرستد، سر وقت
 * اجرایش می‌کند.
 *
 * فایل روی دیسک است، پس ری‌استارت ربات یا سرور صف را از بین نمی‌برد.
 */
final class OpQueue
{
    /** کارهایی که می‌شود زمان‌بندی کرد (فقط کارهای بی‌بازگشت‌نشدنی). */
    public const ACTIONS = [
        'backup' => '💾 گرفتن بکاپ کامل',
        'prune_backups' => '🧹 پاک‌کردن بکاپ‌های قدیمی',
        'maintenance_on' => '🛠 روشن‌کردن حالت تعمیر',
        'maintenance_off' => '✅ خاموش‌کردن حالت تعمیر',
        'report' => '📊 فرستادن گزارش وضعیت',
    ];

    /** بیشتر از این تعداد کار در صف نگه نمی‌داریم. */
    private const MAX_ITEMS = 30;

    /**
     * @param array<string, string> $params
     *
     * @return string شناسه‌ی کار
     */
    public static function add(string $action, int $runAt, array $params = [], string $server = ''): string
    {
        if (!isset(self::ACTIONS[$action])) {
            throw CpanelError::config("کار «{$action}» قابل زمان‌بندی نیست.");
        }

        $items = self::all();
        if (count($items) >= self::MAX_ITEMS) {
            throw CpanelError::config('صف پر است؛ اول چند کار قدیمی را حذف کنید.');
        }

        $id = substr(bin2hex(random_bytes(4)), 0, 8);
        $items[] = [
            'id' => $id,
            'action' => $action,
            'params' => array_map('strval', $params),
            'run_at' => $runAt,
            'server' => $server,
            'created' => time(),
        ];
        self::save($items);

        return $id;
    }

    /**
     * @return array<int, array{id: string, action: string, params: array<string, string>, run_at: int, server: string, created: int}>
     */
    public static function all(): array
    {
        $path = Paths::data('queue.json');
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return [];
        }

        $items = [];
        foreach ($decoded as $row) {
            if (!is_array($row) || !isset(self::ACTIONS[(string) ($row['action'] ?? '')])) {
                continue;
            }
            $items[] = [
                'id' => (string) ($row['id'] ?? ''),
                'action' => (string) $row['action'],
                'params' => is_array($row['params'] ?? null) ? array_map('strval', $row['params']) : [],
                'run_at' => (int) ($row['run_at'] ?? 0),
                'server' => (string) ($row['server'] ?? ''),
                'created' => (int) ($row['created'] ?? 0),
            ];
        }

        usort($items, static fn (array $a, array $b): int => $a['run_at'] <=> $b['run_at']);

        return $items;
    }

    /**
     * کارهایی که وقتشان رسیده و مال همین سرورند.
     *
     * @return array<int, array{id: string, action: string, params: array<string, string>, run_at: int, server: string, created: int}>
     */
    public static function due(string $server = '', ?int $now = null): array
    {
        $now ??= time();
        $due = [];
        foreach (self::all() as $item) {
            if ($item['run_at'] > $now) {
                continue;
            }
            if ($server !== '' && $item['server'] !== '' && $item['server'] !== $server) {
                continue;
            }
            $due[] = $item;
        }

        return $due;
    }

    public static function remove(string $id): bool
    {
        $items = self::all();
        $kept = array_values(array_filter($items, static fn (array $item): bool => $item['id'] !== $id));
        if (count($kept) === count($items)) {
            return false;
        }
        self::save($kept);

        return true;
    }

    public static function clear(): void
    {
        self::save([]);
    }

    /**
     * برچسب خوانا برای نمایش در فهرست و پیام‌ها.
     *
     * @param array{id: string, action: string, params: array<string, string>, run_at: int, server: string, created: int} $item
     */
    public static function label(array $item): string
    {
        $label = self::ACTIONS[$item['action']] ?? $item['action'];
        $docroot = $item['params']['docroot'] ?? '';
        if ($docroot !== '') {
            $label .= ' — ' . $docroot;
        }

        return $label;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private static function save(array $items): void
    {
        $path = Paths::ensure('queue.json');
        file_put_contents($path, json_encode(array_values($items), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        @chmod($path, 0600);
    }
}
