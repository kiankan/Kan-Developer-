<?php

declare(strict_types=1);

final class Format
{
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

    public static function secondsUntil(DateTimeImmutable $dt): int
    {
        $now = new DateTimeImmutable('now', new DateTimeZone('UTC'));

        return $dt->getTimestamp() - $now->getTimestamp();
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
}
