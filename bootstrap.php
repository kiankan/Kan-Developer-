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
function kanchecker_fatal(string $message): never
{
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $message . "\n");
        exit(1);
    }

    error_log('kan_checker: ' . $message);
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: application/json; charset=utf-8');
    }
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function kanchecker_bot_token(): string
{
    $token = getenv('TELEGRAM_BOT_TOKEN');
    if ($token === false || trim($token) === '') {
        kanchecker_fatal('TELEGRAM_BOT_TOKEN تنظیم نشده است (در .env یا متغیر محیطی قرار دهید).');
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
            kanchecker_fatal("آیدی ادمین باید عدد باشد، مقدار نامعتبر: {$part}");
        }
        $ids[] = (int) $part;
    }

    return array_values(array_unique($ids));
}

/**
 * پردازش یک آپدیت وبهوکی. هم webhook.php (روی هاست اشتراکی) و هم سرویس
 * پورت اختصاصی (serve.php) از همین استفاده می‌کنند.
 */
function kanchecker_handle_webhook(): void
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

    $api = new TelegramApi(kanchecker_bot_token());
    $adminIds = kanchecker_admin_ids();

    try {
        Heartbeat::touch('webhook');
        if (isset($update['message'])) {
            UpdateHandler::handle($api, $update['message'], $adminIds);
        } elseif (isset($update['callback_query'])) {
            UpdateHandler::handleCallback($api, $update['callback_query'], $adminIds);
        }
    } catch (Throwable $e) {
        error_log('kan_checker webhook error: ' . $e->getMessage());
    }
}
