<?php

declare(strict_types=1);

/**
 * مسیر فایل‌های داده. همه‌ی چیزهایی که روی دیسک نگه می‌داریم (سرورها،
 * ادمین‌ها، وضعیت گفت‌وگوها، لاگ عملیات، نبض) زیر همین یک پوشه‌اند تا بکاپ
 * گرفتن و امن‌کردنش یک‌جا باشد.
 *
 * TEAMKAN_DATA_DIR مسیر را عوض می‌کند؛ KAN_DATA_DIR هم برای سازگاری با
 * نصب‌های قبلی هنوز خوانده می‌شود.
 */
final class Paths
{
    public static function dataDir(): string
    {
        $dir = trim((string) (getenv('TEAMKAN_DATA_DIR') ?: getenv('KAN_DATA_DIR') ?: ''));

        return $dir !== '' ? rtrim($dir, '/') : dirname(__DIR__) . '/data';
    }

    public static function data(string $file): string
    {
        return self::dataDir() . '/' . ltrim($file, '/');
    }

    /**
     * پوشه‌ی داده را با دسترسی محدود می‌سازد (اگر نبود) و مسیر فایل را برمی‌گرداند.
     */
    public static function ensure(string $file): string
    {
        $path = self::data($file);
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }

        return $path;
    }
}
