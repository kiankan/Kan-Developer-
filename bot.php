#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$token = kanpanel_bot_token();

if (Admins::isClaimed()) {
    fwrite(STDOUT, 'دسترسی محدود به ادمین‌های: ' . implode(', ', Admins::all()) . "\n");
} else {
    fwrite(STDOUT, "ربات هنوز تحویل گرفته نشده. کد نصب را در تلگرام برای ربات بفرستید: " . Admins::claimCode() . "\n");
}

$server = ServerStore::active();
if ($server === null) {
    fwrite(STDOUT, "هشدار: هیچ سرور cPanel/WHM تنظیم نشده — از داخل ربات («⚙️ سرورها») یکی اضافه کنید.\n");
} else {
    fwrite(STDOUT, 'سرور فعال: ' . $server['label'] . ' (' . $server['type'] . ")\n");
}

$api = new TelegramApi($token);
$api->deleteWebhook();

fwrite(STDOUT, "Bot starting (long polling)...\n");

$offset = 0;
while (true) {
    // نبض ربات؛ سرویس پورت اختصاصی از روی همین می‌فهمد ربات زنده است.
    Heartbeat::touch('polling');

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
                UpdateHandler::handle($api, $update['message']);
            } elseif (isset($update['callback_query'])) {
                UpdateHandler::handleCallback($api, $update['callback_query']);
            }
        } catch (Throwable $e) {
            fwrite(STDERR, 'خطا در پردازش آپدیت: ' . $e->getMessage() . "\n");
        }
    }
}
