<?php

declare(strict_types=1);

/**
 * تاریخچه‌ی مصرف منابع.
 *
 * هر روز یک عکس فوری از درصد مصرف نگه می‌داریم تا بشود گفت «دیسک نسبت به
 * دیروز چقدر بالا رفته» — چیزی که سی‌پنل خودش نشان نمی‌دهد.
 */
final class UsageHistory
{
    private const KEEP_DAYS = 60;

    /**
     * @param array<int, array{id: string, label: string, usage: ?float, maximum: ?float, percent: ?float, unit: string}> $usage
     */
    public static function record(array $usage): void
    {
        $today = gmdate('Y-m-d');
        $all = self::load();

        $snapshot = [];
        foreach ($usage as $item) {
            if ($item['percent'] === null) {
                continue;
            }
            $snapshot[$item['id']] = round($item['percent'], 2);
        }
        if ($snapshot === []) {
            return;
        }

        $all[$today] = $snapshot;
        krsort($all);
        $all = array_slice($all, 0, self::KEEP_DAYS, true);

        self::save($all);
    }

    /**
     * مقایسه‌ی امروز با دیروز و هفته‌ی پیش.
     *
     * @return array<int, array{id: string, today: float, yesterday: ?float, week: ?float}>
     */
    public static function trend(): array
    {
        $all = self::load();
        if ($all === []) {
            return [];
        }

        $today = $all[gmdate('Y-m-d')] ?? reset($all);
        $yesterday = $all[gmdate('Y-m-d', strtotime('-1 day'))] ?? null;
        $week = $all[gmdate('Y-m-d', strtotime('-7 days'))] ?? null;

        $out = [];
        foreach ($today as $id => $percent) {
            $out[] = [
                'id' => (string) $id,
                'today' => (float) $percent,
                'yesterday' => isset($yesterday[$id]) ? (float) $yesterday[$id] : null,
                'week' => isset($week[$id]) ? (float) $week[$id] : null,
            ];
        }

        return $out;
    }

    public static function days(): int
    {
        return count(self::load());
    }

    /**
     * @return array<string, array<string, float>>
     */
    private static function load(): array
    {
        $path = Paths::data('usage-history.json');
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, array<string, float>> $all
     */
    private static function save(array $all): void
    {
        $path = Paths::ensure('usage-history.json');
        file_put_contents($path, json_encode($all));
        @chmod($path, 0600);
    }
}
