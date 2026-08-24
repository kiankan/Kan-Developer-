<?php

declare(strict_types=1);

final class Format
{
    public static function jalaliNow(): string
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran'));
        [$jy, $jm, $jd] = self::gregorianToJalali((int) $now->format('Y'), (int) $now->format('n'), (int) $now->format('j'));

        return sprintf('%04d/%02d/%02d | %s', $jy, $jm, $jd, $now->format('H:i:s'));
    }

    public static function megabytes(mixed $value): string
    {
        if (!is_numeric($value)) {
            return 'نامشخص';
        }
        $mb = (float) $value;
        if ($mb <= 0) {
            return '0 مگابایت';
        }
        if ($mb >= 1024) {
            return sprintf('%.2f گیگابایت', $mb / 1024);
        }

        return sprintf('%.1f مگابایت', $mb);
    }

    /**
     * برای مواردی که شکل دقیق پاسخ cPanel رو نمی‌شناسیم/مطمئن نیستیم، خروجی
     * خام JSON رو (کوتاه‌شده تا زیر سقف پیام تلگرام) نمایش می‌دیم تا چیزی
     * گم یا اشتباه‌قالب‌بندی‌شده مخفی نمونه.
     */
    public static function prettyJson(mixed $data, int $maxLength = 3500): string
    {
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
        if ($json === false) {
            return '(قابل نمایش نیست)';
        }
        if (mb_strlen($json) > $maxLength) {
            $json = mb_substr($json, 0, $maxLength) . "\n… (بریده شد)";
        }

        return $json;
    }

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
}
