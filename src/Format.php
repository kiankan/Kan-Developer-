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
}
