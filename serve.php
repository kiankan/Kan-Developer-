<?php

declare(strict_types=1);

/**
 * سرویس HTTP روی پورت اختصاصی خود ربات (پیش‌فرض 2000).
 *
 * اجرا:
 *   php -S 0.0.0.0:2000 serve.php
 *   (اسکریپت نصب همین را به‌صورت یک سرویس systemd جدا بالا می‌آورد.)
 *
 * مسیرها:
 *   GET  /            صفحه‌ی وضعیت ساده (بدون هیچ اطلاعات محرمانه)
 *   GET  /health      همان وضعیت به‌صورت JSON، برای مانیتورینگ
 *   POST /webhook     دریافت آپدیت‌های تلگرام (برای حالت وبهوک)
 *
 * ⚠️ تلگرام فقط به پورت‌های 443/80/88/8443 و روی HTTPS وبهوک می‌فرستد، پس
 * پورت 2000 مستقیماً وبهوک نمی‌گیرد؛ اگر وبهوک می‌خواهید، این سرویس را پشت
 * یک ریورس‌پراکسی TLS بگذارید (نمونه‌اش در README هست). حالت پیش‌فرض ربات
 * long polling است که به هیچ پورت ورودی‌ای نیاز ندارد.
 */

require_once __DIR__ . '/bootstrap.php';

$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$method = strtoupper((string) ($_SERVER['REQUEST_METHOD'] ?? 'GET'));

/**
 * @return array<string, mixed>
 */
function kanpanel_status(): array
{
    $heartbeat = Heartbeat::read();
    $servers = [];
    try {
        foreach (ServerStore::all() as $id => $server) {
            $servers[] = [
                'id' => $id,
                'label' => (string) $server['label'],
                'type' => (string) $server['type'],
                'endpoint' => (string) $server['host'] . ':' . (int) $server['port'],
                'active' => $id === ServerStore::activeId(),
            ];
        }
    } catch (Throwable) {
        // فهرست سرورها نباید جلوی پاسخ سلامت را بگیرد.
    }

    return [
        'ok' => true,
        'service' => 'kanpanel',
        'php' => PHP_VERSION,
        'listen_port' => (int) (getenv('LISTEN_PORT') ?: 2000),
        'bot' => [
            'alive' => $heartbeat['alive'],
            'mode' => $heartbeat['mode'],
            'seconds_since_last_update' => $heartbeat['seconds_ago'],
            'claimed' => Admins::isClaimed(),
            'admins' => count(Admins::all()),
        ],
        'servers' => $servers,
        'time' => gmdate('c'),
    ];
}

if ($method === 'POST' && (str_starts_with($path, '/webhook') || $path === '/')) {
    kanpanel_handle_webhook();
    exit;
}

if ($path === '/health' || $path === '/health/') {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(kanpanel_status(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

if ($path === '/' && $method === 'GET') {
    $status = kanpanel_status();
    $bot = $status['bot']['alive'] ? '🟢 در حال اجرا' : '🔴 پاسخ نمی‌دهد';
    $admins = $status['bot']['claimed']
        ? '✅ تحویل گرفته شده (' . (int) $status['bot']['admins'] . ' ادمین)'
        : '⛔️ هنوز تحویل گرفته نشده — کد نصب را در تلگرام برای ربات بفرستید';

    $rows = '';
    foreach ($status['servers'] as $server) {
        $rows .= '<tr><td>' . Format::escapeHtml($server['label']) . '</td><td>'
            . Format::escapeHtml($server['type']) . '</td><td dir="ltr">'
            . Format::escapeHtml($server['endpoint']) . '</td><td>'
            . ($server['active'] ? '✅' : '') . "</td></tr>\n";
    }
    if ($rows === '') {
        $rows = '<tr><td colspan="4">هنوز سروری تعریف نشده</td></tr>';
    }

    header('Content-Type: text/html; charset=utf-8');
    echo <<<HTML
        <!doctype html>
        <html lang="fa" dir="rtl">
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>KanPanel</title>
        <style>
          body{font-family:system-ui,-apple-system,"Segoe UI",Tahoma,sans-serif;max-width:44rem;margin:3rem auto;padding:0 1rem;line-height:1.9;color:#1c1c1e}
          table{border-collapse:collapse;width:100%;margin-top:.5rem}
          th,td{border:1px solid #d8d8dc;padding:.4rem .6rem;text-align:right;font-size:.95rem}
          code{background:#f2f2f7;padding:.1rem .35rem;border-radius:.25rem}
          @media (prefers-color-scheme:dark){body{background:#111;color:#eee}th,td{border-color:#333}code{background:#222}}
        </style>
        <h1>KanPanel</h1>
        <p>مدیریت cPanel/WHM از تلگرام.</p>
        <p>ربات: <b>{$bot}</b><br>ادمین: {$admins}<br>پورت این سرویس: <code>{$status['listen_port']}</code></p>
        <h2>سرورها</h2>
        <table><tr><th>نام</th><th>نوع</th><th>آدرس</th><th>فعال</th></tr>
        {$rows}
        </table>
        <p style="margin-top:2rem;font-size:.85rem;opacity:.7">وضعیت JSON: <code>/health</code></p>
        </html>
        HTML;
    exit;
}

http_response_code(404);
header('Content-Type: application/json; charset=utf-8');
echo json_encode(['ok' => false, 'error' => 'not found'], JSON_UNESCAPED_UNICODE);
