<?php

declare(strict_types=1);

/**
 * میان‌برهای کاربر: دامنه، ایمیل یا پوشه‌ای که زیاد سراغش می‌رود.
 *
 * هر میان‌بر یک نوع دارد تا ربات بداند با زدنش چه کاری کند (بررسی دامنه،
 * تنظیمات صندوق، یا باز کردن پوشه در مرورگر فایل).
 */
final class Favorites
{
    public const TYPE_DOMAIN = 'domain';
    public const TYPE_EMAIL = 'email';
    public const TYPE_PATH = 'path';

    /**
     * @return array<int, array{type: string, value: string}>
     */
    public static function all(): array
    {
        $path = Paths::data('favorites.json');
        if (!is_file($path)) {
            return [];
        }
        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return [];
        }

        $out = [];
        foreach ($decoded as $row) {
            if (!is_array($row) || !isset($row['type'], $row['value'])) {
                continue;
            }
            $out[] = ['type' => (string) $row['type'], 'value' => (string) $row['value']];
        }

        return $out;
    }

    public static function add(string $type, string $value): void
    {
        $value = trim($value);
        if ($value === '') {
            return;
        }

        $all = self::all();
        foreach ($all as $row) {
            if ($row['type'] === $type && $row['value'] === $value) {
                return;
            }
        }
        $all[] = ['type' => $type, 'value' => $value];
        self::save(array_slice($all, -20));
    }

    public static function remove(string $value): void
    {
        self::save(array_values(array_filter(
            self::all(),
            static fn (array $row): bool => $row['value'] !== trim($value),
        )));
    }

    /**
     * نوع میان‌بر را از روی خود مقدار حدس می‌زند.
     */
    public static function guessType(string $value): string
    {
        $value = trim($value);
        if (str_contains($value, '@')) {
            return self::TYPE_EMAIL;
        }
        if (str_starts_with($value, '/')) {
            return self::TYPE_PATH;
        }

        return self::TYPE_DOMAIN;
    }

    /**
     * @param array<int, array{type: string, value: string}> $all
     */
    private static function save(array $all): void
    {
        $path = Paths::ensure('favorites.json');
        file_put_contents($path, json_encode(array_values($all), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        @chmod($path, 0600);
    }
}
