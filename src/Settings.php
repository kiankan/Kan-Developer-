<?php

declare(strict_types=1);

/**
 * تنظیمات ساده‌ی خود ربات (نه سرورها): سوییچ‌ها، آستانه‌ی هشدارها، زمان آخرین
 * گزارش و مسیر آخرین بکاپ خودکار.
 *
 * کلید/مقدار روی یک فایل JSON — چیزی که با هر آپدیت پروژه پاک نمی‌شود.
 */
final class Settings
{
    public const SAFE_DELETE = 'safe_delete';
    public const ALERT_DISK = 'alert_disk';
    public const ALERT_INODE = 'alert_inode';
    public const ALERT_SSL_DAYS = 'alert_ssl_days';
    public const ALERT_SITE = 'alert_site';
    public const REPORT_EVERY = 'report_every';
    public const LAST_BACKUP = 'last_backup';
    public const WATCH_DOMAINS = 'watch_domains';
    public const BACKUP_EVERY = 'backup_every';
    public const BACKUP_KEEP = 'backup_keep';
    public const LAST_SERVER_IP = 'last_server_ip';
    public const WATCH_FILES = 'watch_files';

    private const DEFAULTS = [
        self::SAFE_DELETE => '1',
        self::ALERT_DISK => '85',
        self::ALERT_INODE => '85',
        self::ALERT_SSL_DAYS => '14',
        self::ALERT_SITE => '1',
        self::REPORT_EVERY => 'weekly',
        self::LAST_BACKUP => '',
        self::WATCH_DOMAINS => '',
        self::BACKUP_EVERY => 'off',
        self::BACKUP_KEEP => '3',
        self::LAST_SERVER_IP => '',
        self::WATCH_FILES => '',
    ];

    public static function get(string $key, string $default = ''): string
    {
        $all = self::load();

        return (string) ($all[$key] ?? self::DEFAULTS[$key] ?? $default);
    }

    public static function int(string $key, int $default = 0): int
    {
        $value = self::get($key);

        return is_numeric($value) ? (int) $value : $default;
    }

    public static function bool(string $key, bool $default = false): bool
    {
        $value = self::get($key, $default ? '1' : '0');

        return $value === '1' || strtolower($value) === 'true';
    }

    public static function set(string $key, string $value): void
    {
        $all = self::load();
        $all[$key] = $value;
        self::save($all);
    }

    public static function toggle(string $key): bool
    {
        $new = !self::bool($key);
        self::set($key, $new ? '1' : '0');

        return $new;
    }

    /**
     * @return array<string, string>
     */
    public static function all(): array
    {
        return self::load() + self::DEFAULTS;
    }

    /**
     * @return array<string, string>
     */
    private static function load(): array
    {
        $path = Paths::data('settings.json');
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? array_map('strval', $decoded) : [];
    }

    /**
     * @param array<string, string> $all
     */
    private static function save(array $all): void
    {
        $path = Paths::ensure('settings.json');
        file_put_contents($path, json_encode($all, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        @chmod($path, 0600);
    }
}
