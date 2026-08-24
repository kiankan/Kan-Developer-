<?php

declare(strict_types=1);

/**
 * وضعیت گفتگوی چندمرحله‌ای به‌ازای هر چت (مثلاً ویزارد /connect یا /password)،
 * چون این‌ها برخلاف EditState فقط مخصوص ادمین نیستند و هر کاربری ممکنه وسط
 * یکی از این جریان‌ها باشه. هر چت یک فایل جدا روی دیسک داره تا هم در حالت
 * long-polling و هم webhook (که هر درخواست یک پردازش تازه‌ست) کار کنه.
 */
final class ConversationState
{
    /**
     * @return array{step: string, data: array<string, mixed>}|null
     */
    public static function get(int $chatId): ?array
    {
        $path = self::path($chatId);
        if (!is_file($path)) {
            return null;
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded) || !isset($decoded['step'])) {
            return null;
        }

        return [
            'step' => (string) $decoded['step'],
            'data' => is_array($decoded['data'] ?? null) ? $decoded['data'] : [],
        ];
    }

    public static function set(int $chatId, string $step, array $data = []): void
    {
        $path = self::path($chatId);
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, json_encode(['step' => $step, 'data' => $data], JSON_UNESCAPED_UNICODE));
        @chmod($path, 0600);
    }

    public static function clear(int $chatId): void
    {
        @unlink(self::path($chatId));
    }

    private static function path(int $chatId): string
    {
        return dirname(__DIR__) . "/data/state/$chatId.json";
    }
}
