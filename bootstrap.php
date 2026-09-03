<?php

declare(strict_types=1);

date_default_timezone_set('UTC');

$root = __DIR__;

spl_autoload_register(static function (string $class) use ($root): void {
    $path = $root . '/src/' . $class . '.php';
    if (is_file($path)) {
        require_once $path;
    }
});

require_once $root . '/src/Env.php';
Env::load($root . '/.env');

function kanchecker_bot_token(): string
{
    $token = getenv('TELEGRAM_BOT_TOKEN');
    if ($token === false || trim($token) === '') {
        fwrite(STDERR, "TELEGRAM_BOT_TOKEN تنظیم نشده است (در .env یا متغیر محیطی قرار دهید).\n");
        exit(1);
    }

    return $token;
}

/**
 * آیدی‌های مجاز؛ هم ADMIN_ID تکی (سازگاری با نصب‌های قبلی) و هم ADMIN_IDS
 * چندتایی جدا شده با کاما پشتیبانی می‌شن.
 *
 * @return array<int, int>
 */
function kanchecker_admin_ids(): array
{
    $raw = trim((string) (getenv('ADMIN_IDS') ?: '')) . ',' . trim((string) (getenv('ADMIN_ID') ?: ''));

    $ids = [];
    foreach (explode(',', $raw) as $part) {
        $part = trim($part);
        if ($part === '') {
            continue;
        }
        if (!ctype_digit($part)) {
            fwrite(STDERR, "آیدی ادمین باید عدد باشد، مقدار نامعتبر: $part\n");
            exit(1);
        }
        $ids[] = (int) $part;
    }

    return array_values(array_unique($ids));
}
