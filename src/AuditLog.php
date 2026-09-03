<?php

declare(strict_types=1);

/**
 * دفترچه‌ی رویدادها: هر عملیات تغییردهنده (ساخت/حذف/تعلیق/...) یک خط JSON
 * می‌شه. برای یک ابزار مدیریتی که می‌تونه دیتابیس حذف کنه، داشتن ردپا از
 * «کی، چه‌وقت، روی کدام سرور، چه کرد» ضروریه.
 */
final class AuditLog
{
    /**
     * @param array<string, mixed> $context
     */
    public static function record(string $actor, string $server, string $action, array $context = [], bool $ok = true): void
    {
        $line = json_encode([
            'time' => gmdate('c'),
            'actor' => $actor,
            'server' => $server,
            'action' => $action,
            'ok' => $ok,
            'context' => self::scrub($context),
        ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);

        $path = self::path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        @file_put_contents($path, $line . "\n", FILE_APPEND);
        @chmod($path, 0600);
    }

    /**
     * @return array<int, string> آخرین خطوط، از جدید به قدیم
     */
    public static function tail(int $lines = 20): array
    {
        $path = self::path();
        if (!is_file($path)) {
            return [];
        }
        $all = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

        return array_reverse(array_slice($all, -$lines));
    }

    /**
     * رمزها هیچ‌وقت لاگ نمی‌شن.
     *
     * @param array<string, mixed> $context
     *
     * @return array<string, mixed>
     */
    private static function scrub(array $context): array
    {
        foreach ($context as $key => $value) {
            if (preg_match('/pass|token|secret|rmz/i', (string) $key)) {
                $context[$key] = '***';
            }
        }

        return $context;
    }

    private static function path(): string
    {
        return (getenv('KAN_DATA_DIR') ?: dirname(__DIR__) . '/data') . '/audit.log';
    }
}
