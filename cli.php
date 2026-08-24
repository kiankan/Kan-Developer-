#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function usage(): void
{
    fwrite(STDERR, <<<TXT
        استفاده:
          php cli.php webhook:set <url>
          php cli.php webhook:delete
          php cli.php test <host> <port> <username> <password|token> <password|token>

        دستور test بدون نیاز به تلگرام، اتصال مستقیم به یک هاست cPanel رو تست می‌کنه.
        آرگومان آخر نوع احراز هویت رو مشخص می‌کنه: password یا token.

        TXT);
}

$command = $argv[1] ?? null;

switch ($command) {
    case 'webhook:set':
        $url = $argv[2] ?? null;
        if ($url === null) {
            usage();
            exit(1);
        }
        (new TelegramApi(kanchecker_bot_token()))->setWebhook($url);
        echo "وبهوک تنظیم شد: $url\n";
        break;

    case 'webhook:delete':
        (new TelegramApi(kanchecker_bot_token()))->deleteWebhook();
        echo "وبهوک حذف شد.\n";
        break;

    case 'test':
        [$host, $port, $username, $secret, $authType] = [$argv[2] ?? null, $argv[3] ?? null, $argv[4] ?? null, $argv[5] ?? null, $argv[6] ?? 'password'];
        if ($host === null || $port === null || $username === null || $secret === null) {
            usage();
            exit(1);
        }
        $client = new CpanelClient($host, (int) $port, $username, $secret, $authType === 'token' ? 'token' : 'password');
        try {
            $data = $client->uapi('DomainInfo', 'domains_data');
            echo "اتصال موفق بود.\n" . Format::prettyJson($data) . "\n";
        } catch (CpanelError $e) {
            fwrite(STDERR, 'خطا: ' . $e->getMessage() . "\n");
            exit(1);
        }
        break;

    default:
        usage();
        exit(1);
}
