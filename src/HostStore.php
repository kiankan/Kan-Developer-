<?php

declare(strict_types=1);

/**
 * ذخیره‌ی هاست cPanel وصل‌شده برای هر چت تلگرام (یک هاست فعال به‌ازای هر چت).
 * رمز عبور/توکن قبل از ذخیره روی دیسک با Crypto رمزنگاری می‌شود.
 */
final class HostStore
{
    public static function get(int $chatId): ?array
    {
        $all = self::load();
        $entry = $all[(string) $chatId] ?? null;
        if (!is_array($entry)) {
            return null;
        }

        try {
            $secret = Crypto::decrypt((string) $entry['secret_enc']);
        } catch (Throwable) {
            return null;
        }

        return [
            'host' => (string) $entry['host'],
            'port' => (int) $entry['port'],
            'username' => (string) $entry['username'],
            'auth_type' => (string) $entry['auth_type'],
            'secret' => $secret,
        ];
    }

    public static function save(int $chatId, string $host, int $port, string $username, string $authType, string $secret): void
    {
        $all = self::load();
        $all[(string) $chatId] = [
            'host' => $host,
            'port' => $port,
            'username' => $username,
            'auth_type' => $authType,
            'secret_enc' => Crypto::encrypt($secret),
            'connected_at' => gmdate('c'),
        ];
        self::save_($all);
    }

    public static function remove(int $chatId): void
    {
        $all = self::load();
        unset($all[(string) $chatId]);
        self::save_($all);
    }

    public static function has(int $chatId): bool
    {
        $all = self::load();

        return isset($all[(string) $chatId]);
    }

    private static function load(): array
    {
        $path = self::path();
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }

    private static function save_(array $data): void
    {
        $path = self::path();
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        @chmod($path, 0600);
    }

    private static function path(): string
    {
        return dirname(__DIR__) . '/data/hosts.json';
    }
}
