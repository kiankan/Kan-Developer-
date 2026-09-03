#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * حالت «کران» برای هاست‌های اشتراکی.
 *
 * روی هاست اشتراکی معمولاً نه می‌شود یک پروسه‌ی همیشه‌روشن داشت و نه همیشه یک
 * آدرس HTTPS عمومی برای وبهوک هست. این اسکریپت یک دور کوتاه (پیش‌فرض ۵۵ ثانیه)
 * آپدیت‌ها را می‌گیرد و تمام می‌شود، پس می‌شود با یک کران هر دقیقه‌ای صدایش زد:
 *
 *   * * * * * /usr/local/bin/php /home/user/teamkan/poll.php >/dev/null 2>&1
 *
 * قفل فایلی جلوی هم‌پوشانی دو اجرا را می‌گیرد و آخرین offset روی دیسک ذخیره
 * می‌شود تا پیام‌ها دوباره پردازش نشوند.
 */

require_once __DIR__ . '/bootstrap.php';

$dataDir = Paths::dataDir();
if (!is_dir($dataDir)) {
    @mkdir($dataDir, 0700, true);
}

$lockPath = $dataDir . '/poll.lock';
$lock = fopen($lockPath, 'c');
if ($lock === false) {
    fwrite(STDERR, "نمی‌توانم فایل قفل را بسازم: {$lockPath}\n");
    exit(1);
}
if (!flock($lock, LOCK_EX | LOCK_NB)) {
    // اجرای قبلی هنوز تمام نشده؛ این دور را بی‌سروصدا رد می‌کنیم.
    exit(0);
}

$token = teamkan_bot_token();

$offsetPath = $dataDir . '/offset.txt';
$offset = is_file($offsetPath) ? (int) trim((string) file_get_contents($offsetPath)) : 0;

$runSeconds = max(5, min(300, (int) (getenv('POLL_SECONDS') ?: 55)));
$pollTimeout = max(1, min(30, (int) (getenv('POLL_TIMEOUT') ?: 10)));
$deadline = time() + $runSeconds;

$api = new TelegramApi($token);

while (time() < $deadline) {
    Heartbeat::touch('cron');

    try {
        $updates = $api->getUpdates($offset, $pollTimeout);
    } catch (Throwable $e) {
        error_log('teamkan poll error: ' . $e->getMessage());
        break;
    }

    foreach ($updates as $update) {
        $offset = (int) $update['update_id'] + 1;
        file_put_contents($offsetPath, (string) $offset);

        try {
            if (isset($update['message'])) {
                UpdateHandler::handle($api, $update['message']);
            } elseif (isset($update['callback_query'])) {
                UpdateHandler::handleCallback($api, $update['callback_query']);
            }
        } catch (Throwable $e) {
            error_log('teamkan poll update error: ' . $e->getMessage());
        }
    }
}

flock($lock, LOCK_UN);
fclose($lock);
