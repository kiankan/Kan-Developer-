#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * اجرای هشدارها و گزارش دوره‌ای.
 *
 *   php alerts.php
 *
 * روی نصب سروری با یک تایمر systemd هر ۱۵ دقیقه صدا زده می‌شود و روی هاست
 * اشتراکی می‌شود با یک کران ساعتی اجرایش کرد:
 *
 *   0 * * * * /usr/local/bin/php /home/user/teamkan/alerts.php >/dev/null 2>&1
 *
 * فقط وقتی چیزی برای گفتن باشد پیام می‌دهد.
 */

require_once __DIR__ . '/bootstrap.php';

$admins = Admins::all();
if ($admins === []) {
    exit(0);
}

$server = ServerStore::active();
if ($server === null) {
    exit(0);
}

$api = new TelegramApi(kanpanel_bot_token());

try {
    $client = ServerStore::client();
    if ($client->isWhm() && $client->targetUser() === '') {
        exit(0);
    }

    $messages = Alerts::collect($client);

    if (Alerts::reportDue()) {
        $summary = CpanelService::summary($client);
        $messages[] = "📊 <b>گزارش دوره‌ای وضعیت هاست</b>\n\n" . Render::summary($summary);
        Alerts::markReportSent();
    }

    foreach ($messages as $message) {
        foreach ($admins as $admin) {
            $api->sendHtml($admin, $message);
        }
    }

    if ($messages !== []) {
        AuditLog::record('alerts', (string) (ServerStore::activeId() ?? '-'), 'alerts_sent', ['count' => count($messages)]);
    }
} catch (Throwable $e) {
    error_log('teamkan alerts error: ' . $e->getMessage());
    exit(1);
}
