<?php

declare(strict_types=1);

/**
 * وضعیت گفت‌وگوهای چندمرحله‌ای («اسم دیتابیس رو بفرست» → «حالا رمز رو بفرست»).
 *
 * هر چت وضعیت خودش رو داره و بعد از ۳۰ دقیقه بی‌استفاده منقضی می‌شه، تا یک
 * جریان نیمه‌کاره‌ی فراموش‌شده جلوی دستور بعدی رو نگیره.
 */
final class State
{
    private const TTL = 1800;

    /**
     * @return array{flow: string, step: int, data: array<string, mixed>}|null
     */
    public static function get(int $chatId): ?array
    {
        $all = self::load();
        $entry = $all[(string) $chatId] ?? null;
        if (!is_array($entry)) {
            return null;
        }
        if ((int) ($entry['updated'] ?? 0) + self::TTL < time()) {
            self::clear($chatId);

            return null;
        }

        return [
            'flow' => (string) ($entry['flow'] ?? ''),
            'step' => (int) ($entry['step'] ?? 0),
            'data' => is_array($entry['data'] ?? null) ? $entry['data'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function set(int $chatId, string $flow, int $step, array $data = []): void
    {
        $all = self::load();
        $all[(string) $chatId] = [
            'flow' => $flow,
            'step' => $step,
            'data' => $data,
            'updated' => time(),
        ];
        self::save($all);
    }

    public static function clear(int $chatId): void
    {
        $all = self::load();
        unset($all[(string) $chatId]);
        self::save($all);
    }

    /**
     * @return array<string, mixed>
     */
    private static function load(): array
    {
        $path = self::path();
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);

        return is_array($decoded) ? $decoded : [];
    }

    /**
     * @param array<string, mixed> $all
     */
    private static function save(array $all): void
    {
        $path = self::path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        // جریان‌ها گاهی رمز عبور موقت نگه می‌دارن، پس فایل باید فقط برای خود
        // سرویس خوندنی باشه.
        file_put_contents($path, json_encode($all, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        @chmod($path, 0600);
    }

    private static function path(): string
    {
        return Paths::data('state.json');
    }
}
