<?php

declare(strict_types=1);

/**
 * تنها استثنای لایه‌ی API. `kind` مشخص می‌کنه مشکل از شبکه بوده، از احراز
 * هویت، از خود HTTP یا از پاسخ منطقی خود cPanel — تا لایه‌ی بالاتر بتونه
 * پیام مناسب (و راهنمایی درست) به کاربر بده.
 */
final class CpanelError extends RuntimeException
{
    public const KIND_TRANSPORT = 'transport';
    public const KIND_AUTH = 'auth';
    public const KIND_HTTP = 'http';
    public const KIND_API = 'api';
    public const KIND_CONFIG = 'config';

    public function __construct(
        string $message,
        public readonly string $kind = self::KIND_API,
        public readonly int $status = 0,
        public readonly ?string $rawBody = null,
    ) {
        parent::__construct($message);
    }

    public static function transport(string $message): self
    {
        return new self("خطا در اتصال به سرور: $message", self::KIND_TRANSPORT);
    }

    public static function auth(int $status): self
    {
        return new self(
            "احراز هویت رد شد (HTTP $status) — نام کاربری یا API Token درست نیست، یا توکن دسترسی لازم رو نداره.",
            self::KIND_AUTH,
            $status,
        );
    }

    public static function http(int $status, string $body): self
    {
        return new self(
            "پاسخ غیرمنتظره از سرور (HTTP $status): " . self::snippet($body),
            self::KIND_HTTP,
            $status,
            $body,
        );
    }

    public static function api(string $message, ?string $body = null): self
    {
        return new self($message, self::KIND_API, 0, $body);
    }

    public static function config(string $message): self
    {
        return new self($message, self::KIND_CONFIG);
    }

    private static function snippet(string $body): string
    {
        $clean = trim(preg_replace('/\s+/', ' ', strip_tags($body)) ?? '');

        return $clean === '' ? '(بدنه‌ی خالی)' : mb_substr($clean, 0, 200, 'UTF-8');
    }
}
