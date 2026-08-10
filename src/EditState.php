<?php

declare(strict_types=1);

/**
 * وضعیت خیلی سبک برای جریان دومرحله‌ای ویرایش متن از پنل ادمین: «منتظر متن
 * جدید برای کلید X هستیم». چون فقط ادمین ازش استفاده می‌کنه، یک وضعیت سراسری
 * (نه به‌ازای هر چت) کافیه — بار اضافه‌ای هم روی CPU نمی‌ذاره، فقط یه فایل
 * چندبایتی می‌خونه/می‌نویسه.
 */
final class EditState
{
    public static function get(): ?string
    {
        $path = self::path();
        if (!is_file($path)) {
            return null;
        }
        $key = trim((string) file_get_contents($path));

        return $key !== '' ? $key : null;
    }

    public static function set(string $key): void
    {
        $path = self::path();
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, $key);
    }

    public static function clear(): void
    {
        @unlink(self::path());
    }

    private static function path(): string
    {
        return dirname(__DIR__) . '/data/edit_state.txt';
    }
}
