#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$token = kanchecker_bot_token();
$adminId = kanchecker_admin_id();

if ($adminId !== null) {
    fwrite(STDOUT, "دسترسی محدود به ADMIN_ID=$adminId شد.\n");
} else {
    fwrite(STDOUT, "هشدار: ADMIN_ID تنظیم نشده — ربات به پیام هر کاربری در تلگرام پاسخ می‌دهد.\n");
}

$api = new TelegramApi($token);
$api->deleteWebhook();

fwrite(STDOUT, "Bot starting (long polling)...\n");

$offset = 0;
while (true) {
    try {
        $updates = $api->getUpdates($offset, 30);
    } catch (Throwable $e) {
        fwrite(STDERR, 'خطا در دریافت آپدیت‌ها: ' . $e->getMessage() . "\n");
        sleep(5);
        continue;
    }

    foreach ($updates as $update) {
        $offset = $update['update_id'] + 1;
        try {
            if (isset($update['message'])) {
                UpdateHandler::handle($api, $update['message'], $adminId);
            } elseif (isset($update['callback_query'])) {
                UpdateHandler::handleCallback($api, $update['callback_query'], $adminId);
            }
        } catch (Throwable $e) {
            fwrite(STDERR, 'خطا در پردازش آپدیت: ' . $e->getMessage() . "\n");
        }
    }
}
