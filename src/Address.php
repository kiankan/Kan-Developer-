<?php

declare(strict_types=1);

/**
 * تجزیه‌ی آدرس سرور.
 *
 * کاربر معمولاً همان چیزی را می‌فرستد که در نوار آدرس مرورگرش می‌بیند —
 * «https://server.example.com:2083/cpanel» یا «server.example.com:2096» یا
 * فقط «1.2.3.4». هر سه باید به یک هاست و (اگر بود) یک پورت تبدیل شوند، چون
 * هر هاست می‌تواند پورت خودش را داشته باشد و همه هم‌زمان کار کنند.
 */
final class Address
{
    /**
     * @return array{host: string, port: int|null}
     */
    public static function parse(string $address): array
    {
        $value = trim($address);
        $value = (string) preg_replace('#^[a-z][a-z0-9+.-]*://#i', '', $value);
        // مسیر، کوئری و هرچه بعد از هاست آمده کنار گذاشته می‌شود.
        $value = (string) preg_replace('~[/?\#].*$~', '', $value);
        $value = trim($value);

        if ($value === '') {
            return ['host' => '', 'port' => null];
        }

        // IPv6 داخل کروشه: [::1]:2083
        if (preg_match('#^\[([0-9a-f:]+)\](?::(\d{1,5}))?$#i', $value, $matches) === 1) {
            return ['host' => '[' . $matches[1] . ']', 'port' => isset($matches[2]) ? (int) $matches[2] : null];
        }

        // IPv6 بدون کروشه و بدون پورت
        if (substr_count($value, ':') > 1) {
            return ['host' => '[' . $value . ']', 'port' => null];
        }

        if (preg_match('#^(.+):(\d{1,5})$#', $value, $matches) === 1) {
            $port = (int) $matches[2];

            return ['host' => $matches[1], 'port' => ($port >= 1 && $port <= 65535) ? $port : null];
        }

        return ['host' => $value, 'port' => null];
    }

    public static function host(string $address): string
    {
        return self::parse($address)['host'];
    }

    public static function port(string $address): ?int
    {
        return self::parse($address)['port'];
    }

    /**
     * پورت نهایی: چیزی که کاربر صریح داده > پورت چسبیده به آدرس > پیش‌فرض نوع سرور.
     */
    public static function resolvePort(string $address, mixed $explicitPort, string $type): int
    {
        $explicit = (int) (is_numeric($explicitPort) ? $explicitPort : 0);
        if ($explicit > 0) {
            return $explicit;
        }

        return self::port($address) ?? self::defaultPort($type);
    }

    public static function defaultPort(string $type): int
    {
        return $type === CpanelClient::TYPE_WHM ? 2087 : 2083;
    }
}
