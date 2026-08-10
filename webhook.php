<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$token = kanchecker_bot_token();
$adminId = kanchecker_admin_id();

$raw = file_get_contents('php://input');
$update = json_decode((string) $raw, true);

http_response_code(200);

if (is_array($update) && isset($update['message'])) {
    $api = new TelegramApi($token);
    try {
        UpdateHandler::handle($api, $update['message'], $adminId);
    } catch (Throwable $e) {
        error_log('kan_checker webhook error: ' . $e->getMessage());
    }
}
