<?php

declare(strict_types=1);

date_default_timezone_set('UTC');

$root = __DIR__;

require_once $root . '/src/Env.php';
require_once $root . '/src/Format.php';
require_once $root . '/src/FragmentClient.php';
require_once $root . '/src/GiftClient.php';
require_once $root . '/src/TonClient.php';
require_once $root . '/src/WalletClient.php';
require_once $root . '/src/CurrencyClient.php';
require_once $root . '/src/Render.php';
require_once $root . '/src/TelegramApi.php';
require_once $root . '/src/TextStore.php';
require_once $root . '/src/EditState.php';
require_once $root . '/src/Settings.php';
require_once $root . '/src/UpdateHandler.php';

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

function kanchecker_admin_id(): ?int
{
    $raw = getenv('ADMIN_ID');
    if ($raw === false || trim((string) $raw) === '') {
        return null;
    }
    if (!ctype_digit(trim((string) $raw))) {
        fwrite(STDERR, "ADMIN_ID باید یک عدد باشد، مقدار فعلی نامعتبر است: $raw\n");
        exit(1);
    }

    return (int) $raw;
}
