<?php

declare(strict_types=1);

/**
 * سوییچ‌های ساده‌ی روی‌دیسک (مثل حالت عمومی) که با پنل ادمین عوض می‌شن —
 * جدا از TextStore چون این‌ها مقدار روشن/خاموشن، نه متن قابل‌ویرایش.
 */
final class Settings
{
    public static function isPublic(): bool
    {
        $path = self::path();

        return is_file($path) && trim((string) file_get_contents($path)) === '1';
    }

    public static function setPublic(bool $value): void
    {
        $path = self::path();
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, $value ? '1' : '0');
    }

    private static function path(): string
    {
        return dirname(__DIR__) . '/data/public_mode.txt';
    }
}
