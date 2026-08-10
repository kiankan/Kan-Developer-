<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

$token = kanchecker_bot_token();
$adminId = kanchecker_admin_id();

$raw = file_get_contents('php://input');
$update = json_decode((string) $raw, true);

http_response_code(200);

if (is_array($update)) {
    $api = new TelegramApi($token);
    try {
        if (isset($update['message'])) {
            UpdateHandler::handle($api, $update['message'], $adminId);
        } elseif (isset($update['callback_query'])) {
            UpdateHandler::handleCallback($api, $update['callback_query'], $adminId);
        }
    } catch (Throwable $e) {
        error_log('kan_checker webhook error: ' . $e->getMessage());
    }
}
