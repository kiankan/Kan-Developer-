<?php

declare(strict_types=1);

final class Format
{
    /**
     * مگابایت/بایت خام سی‌پنل رو به یک رشته‌ی خوانا تبدیل می‌کنه.
     */
    public static function bytes(?float $bytes): string
    {
        if ($bytes === null) {
            return '—';
        }
        $units = ['B', 'KB', 'MB', 'GB', 'TB', 'PB'];
        $i = 0;
        $value = (float) $bytes;
        while (abs($value) >= 1024 && $i < count($units) - 1) {
            $value /= 1024;
            $i++;
        }

        return self::trimZeros(number_format($value, $i === 0 || abs($value) >= 100 ? 0 : 2, '.', ',')) . ' ' . $units[$i];
    }

    /**
     * صفرهای اضافه‌ی اعشار رو می‌بره، ولی فقط وقتی واقعاً اعشار وجود داره —
     * وگرنه عددی مثل «1,000» به «1,» تبدیل می‌شد.
     */
    public static function trimZeros(string $formatted): string
    {
        return str_contains($formatted, '.') ? rtrim(rtrim($formatted, '0'), '.') : $formatted;
    }

    public static function megabytes(?float $mb): string
    {
        return $mb === null ? '—' : self::bytes($mb * 1024 * 1024);
    }

    /**
     * سهمیه‌های سی‌پنل گاهی عدد، گاهی «unlimited»، «0» یا رشته‌ی خالی برمی‌گردن.
     */
    public static function quota(mixed $value, string $unit = 'MB'): string
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') {
            return 'نامحدود';
        }
        $text = strtolower(trim((string) $value));
        if (in_array($text, ['unlimited', 'unlimited mb', '∞', 'null', 'none'], true)) {
            return 'نامحدود';
        }
        if (is_numeric($text)) {
            return $unit === 'MB' ? self::megabytes((float) $text) : self::bytes((float) $text);
        }

        return (string) $value;
    }

    public static function percent(?float $value): string
    {
        return $value === null ? '—' : self::trimZeros(number_format($value, 1, '.', '')) . '٪';
    }

    /**
     * نوار درصد ساده (بدون تصویر) برای نمایش مصرف منابع در تلگرام.
     */
    public static function bar(?float $percent, int $width = 10): string
    {
        if ($percent === null) {
            return '';
        }
        $percent = max(0.0, min(100.0, $percent));
        $filled = (int) round($percent / 100 * $width);

        return str_repeat('█', $filled) . str_repeat('░', $width - $filled);
    }

    public static function humanizeDuration(int $totalSeconds): string
    {
        $totalSeconds = max(0, $totalSeconds);
        $days = intdiv($totalSeconds, 86400);
        $rem = $totalSeconds % 86400;
        $hours = intdiv($rem, 3600);
        $rem %= 3600;
        $minutes = intdiv($rem, 60);
        $seconds = $rem % 60;

        $parts = [];
        if ($days > 0) {
            $parts[] = "$days روز";
        }
        $parts[] = sprintf('%02d:%02d:%02d', $hours, $minutes, $seconds);

        return implode(' ', $parts);
    }

    public static function formatUtc(DateTimeImmutable $dt): string
    {
        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s \U\T\C');
    }

    /**
     * تاریخ انقضای گواهی SSL و مشابهش به‌صورت timestamp میان؛ این کمکی
     * علاوه بر تاریخ، تعداد روز باقی‌مانده رو هم می‌ده.
     */
    public static function timestampWithRemaining(mixed $timestamp): string
    {
        if (!is_numeric($timestamp)) {
            return '—';
        }
        $dt = (new DateTimeImmutable('@' . (int) $timestamp))->setTimezone(new DateTimeZone('UTC'));
        $days = (int) floor(((int) $timestamp - time()) / 86400);
        $suffix = $days >= 0 ? "$days روز مانده" : abs($days) . ' روز گذشته';

        return $dt->format('Y-m-d') . " ($suffix)";
    }

    public static function jalaliNow(): string
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran'));
        [$jy, $jm, $jd] = self::gregorianToJalali((int) $now->format('Y'), (int) $now->format('n'), (int) $now->format('j'));

        return sprintf('%04d/%02d/%02d | %s', $jy, $jm, $jd, $now->format('H:i:s'));
    }

    /**
     * @return array{0: int, 1: int, 2: int} [year, month, day]
     */
    private static function gregorianToJalali(int $gy, int $gm, int $gd): array
    {
        $gDaysInMonth = [0, 31, 59, 90, 120, 151, 181, 212, 243, 273, 304, 334];
        $gy2 = ($gm > 2) ? ($gy + 1) : $gy;
        $days = 355666 + (365 * $gy) + intdiv($gy2 + 3, 4) - intdiv($gy2 + 99, 100)
            + intdiv($gy2 + 399, 400) + $gd + $gDaysInMonth[$gm - 1];

        $jy = -1595 + (33 * intdiv($days, 12053));
        $days %= 12053;
        $jy += 4 * intdiv($days, 1461);
        $days %= 1461;

        if ($days > 365) {
            $jy += intdiv($days - 1, 365);
            $days = ($days - 1) % 365;
        }

        if ($days < 186) {
            $jm = 1 + intdiv($days, 31);
            $jd = 1 + ($days % 31);
        } else {
            $jm = 7 + intdiv($days - 186, 30);
            $jd = 1 + (($days - 186) % 30);
        }

        return [$jy, $jm, $jd];
    }

    public static function escapeHtml(string $text): string
    {
        return htmlspecialchars($text, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * توکن‌ها هیچ‌وقت کامل نمایش داده نمی‌شن (نه توی تلگرام، نه توی لاگ).
     */
    public static function maskSecret(?string $secret): string
    {
        $secret = (string) $secret;
        $len = strlen($secret);
        if ($len === 0) {
            return '—';
        }
        if ($len <= 8) {
            return str_repeat('•', $len);
        }

        return substr($secret, 0, 4) . str_repeat('•', 6) . substr($secret, -4);
    }
}
