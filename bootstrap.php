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

/**
 * خطای مرگبار، متناسب با محیط اجرا: توی ترمینال روی STDERR، و توی وب یک
 * پاسخ 500 تمیز (چون STDERR در SAPI وب اصلاً وجود ندارد).
 */
function teamkan_fatal(string $message): never
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }

    error_log('teamkan: ' . $message);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function teamkan_bot_token(): string
{
    $token = getenv('TELEGRAM_BOT_TOKEN');
    if ($token === false || trim($token) === '') {
        teamkan_fatal('TELEGRAM_BOT_TOKEN تنظیم نشده است (در .env یا متغیر محیطی قرار دهید).');
    }

    return $token;
}

/**
 * پردازش یک آپدیت وبهوکی. هم webhook.php (روی هاست اشتراکی) و هم سرویس
 * پورت اختصاصی (serve.php) از همین استفاده می‌کنند.
 */
function teamkan_handle_webhook(): void
{
    http_response_code(200);

    // اگر WEBHOOK_SECRET ست شده باشد، تلگرام آن را در هدر می‌فرستد؛ هر
    // درخواستی بدون آن، از تلگرام نیست.
    $secret = trim((string) (getenv('WEBHOOK_SECRET') ?: ''));
    if ($secret !== '') {
        $sent = (string) ($_SERVER['HTTP_X_TELEGRAM_BOT_API_SECRET_TOKEN'] ?? '');
        if (!hash_equals($secret, $sent)) {
            http_response_code(403);
            echo json_encode(['ok' => false, 'error' => 'forbidden']);

            return;
        }
    }

    $update = json_decode((string) file_get_contents('php://input'), true);
    if (!is_array($update)) {
        return;
    }

    $api = new TelegramApi(teamkan_bot_token());

    try {
        Heartbeat::touch('webhook');
        if (isset($update['message'])) {
            UpdateHandler::handle($api, $update['message']);
        } elseif (isset($update['callback_query'])) {
            UpdateHandler::handleCallback($api, $update['callback_query']);
        }
    } catch (Throwable $e) {
        error_log('teamkan webhook error: ' . $e->getMessage());
    }
}
