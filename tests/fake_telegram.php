<?php

declare(strict_types=1);

/**
 * سرور ساختگی Bot API برای تست‌های یکپارچه (tests/integration.php).
 * هر فراخوانی را در یک فایل JSON-lines می‌نویسد و پاسخ موفق برمی‌گرداند.
 */

$logPath = getenv('FAKE_TG_LOG') ?: sys_get_temp_dir() . '/fake_telegram.log';
$path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';

// دانلود فایل (همان آدرسی که ربات برای گرفتن فایل کاربر صدا می‌زند)
if (str_contains($path, '/file/bot')) {
    file_put_contents($logPath, json_encode(['method' => 'downloadFile', 'params' => ['path' => $path]]) . "\n", FILE_APPEND);
    header('Content-Type: application/octet-stream');
    echo 'FAKE-ARCHIVE-CONTENT';

    return;
}

$method = basename($path);

parse_str((string) file_get_contents('php://input'), $params);
file_put_contents($logPath, json_encode(['method' => $method, 'params' => $params], JSON_UNESCAPED_UNICODE) . "\n", FILE_APPEND);

header('Content-Type: application/json');
echo json_encode([
    'ok' => true,
    'result' => match ($method) {
        'sendMessage' => ['message_id' => random_int(1000, 9999), 'text' => $params['text'] ?? ''],
        'getUpdates' => [],
        'getFile' => ['file_id' => $params['file_id'] ?? '', 'file_path' => 'documents/uploaded.bin', 'file_size' => 20],
        'editMessageText' => ['message_id' => (int) ($params['message_id'] ?? 0), 'text' => $params['text'] ?? ''],
        default => true,
    },
]);
