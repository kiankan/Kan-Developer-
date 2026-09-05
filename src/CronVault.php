<?php

declare(strict_types=1);

/**
 * انبار کران‌های موقتاً متوقف‌شده.
 *
 * cPanel راهی برای «خاموش کردن» یک کران ندارد؛ یا هست یا نیست. پس برای توقف
 * موقت، خط کران را از سرور برمی‌داریم و همین‌جا نگه می‌داریم تا هر وقت خواستید
 * دقیقاً همان را برگردانیم.
 */
final class CronVault
{
    /**
     * @return string شناسه‌ی کوتاه برای برگرداندن
     */
    public static function put(string $server, string $schedule, string $command): string
    {
        $items = self::all();
        $id = substr(bin2hex(random_bytes(4)), 0, 8);
        $items[] = [
            'id' => $id,
            'server' => $server,
            'schedule' => $schedule,
            'command' => $command,
            'paused_at' => time(),
        ];
        self::save($items);

        return $id;
    }

    /**
     * @return array<int, array{id: string, server: string, schedule: string, command: string, paused_at: int}>
     */
    public static function all(string $server = ''): array
    {
        $path = Paths::data('cron_vault.json');
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return [];
        }

        $items = [];
        foreach ($decoded as $row) {
            if (!is_array($row) || trim((string) ($row['command'] ?? '')) === '') {
                continue;
            }
            $item = [
                'id' => (string) ($row['id'] ?? ''),
                'server' => (string) ($row['server'] ?? ''),
                'schedule' => (string) ($row['schedule'] ?? ''),
                'command' => (string) $row['command'],
                'paused_at' => (int) ($row['paused_at'] ?? 0),
            ];
            if ($server !== '' && $item['server'] !== '' && $item['server'] !== $server) {
                continue;
            }
            $items[] = $item;
        }

        return $items;
    }

    /**
     * @return array{id: string, server: string, schedule: string, command: string, paused_at: int}|null
     */
    public static function take(string $id): ?array
    {
        $found = null;
        $kept = [];
        foreach (self::all() as $item) {
            if ($found === null && $item['id'] === $id) {
                $found = $item;
                continue;
            }
            $kept[] = $item;
        }
        if ($found !== null) {
            self::save($kept);
        }

        return $found;
    }

    public static function clear(): void
    {
        self::save([]);
    }

    /**
     * @param array<int, array<string, mixed>> $items
     */
    private static function save(array $items): void
    {
        $path = Paths::ensure('cron_vault.json');
        file_put_contents($path, json_encode(array_values($items), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        @chmod($path, 0600);
    }
}
