<?php

declare(strict_types=1);

/**
 * ضربان ربات: پروسه‌ی long polling هر دور یک فایل کوچک را به‌روز می‌کند تا
 * سرویس وب (پورت ۲۰۰۰) بتواند بگوید ربات واقعاً زنده است یا فقط سرویسش
 * بالاست.
 */
final class Heartbeat
{
    private const STALE_AFTER = 120;

    public static function touch(string $mode = 'polling'): void
    {
        $path = self::path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        @file_put_contents($path, json_encode([
            'time' => time(),
            'mode' => $mode,
            'pid' => getmypid(),
        ]));
    }

    /**
     * @return array{alive: bool, mode: string, seconds_ago: int|null}
     */
    public static function read(): array
    {
        $path = self::path();
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (!is_array($decoded) || !isset($decoded['time'])) {
            return ['alive' => false, 'mode' => 'unknown', 'seconds_ago' => null];
        }
        $age = time() - (int) $decoded['time'];

        return [
            'alive' => $age <= self::STALE_AFTER,
            'mode' => (string) ($decoded['mode'] ?? 'unknown'),
            'seconds_ago' => $age,
        ];
    }

    private static function path(): string
    {
        return (getenv('KAN_DATA_DIR') ?: dirname(__DIR__) . '/data') . '/heartbeat.json';
    }
}
