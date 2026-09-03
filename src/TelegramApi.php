<?php

declare(strict_types=1);

final class TelegramApi
{
    public function __construct(private readonly string $token)
    {
    }

    /**
     * آدرس پایه‌ی API تلگرام. روی بعضی سرورها api.telegram.org مستقیم در
     * دسترس نیست و از یک پروکسی/آینه استفاده می‌شود؛ با TELEGRAM_API_BASE
     * قابل تغییر است (در تست‌ها هم همین‌جا به سرور ساختگی وصل می‌شویم).
     */
    public static function baseUrl(): string
    {
        $base = trim((string) (getenv('TELEGRAM_API_BASE') ?: ''));

        return rtrim($base !== '' ? $base : 'https://api.telegram.org', '/');
    }

    public function call(string $method, array $params = [], int $curlTimeout = 40): mixed
    {
        $ch = curl_init(self::baseUrl() . "/bot{$this->token}/$method");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_TIMEOUT => $curlTimeout,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new RuntimeException("خطا در ارتباط با تلگرام: $error");
        }
        curl_close($ch);

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded) || !($decoded['ok'] ?? false)) {
            $desc = $decoded['description'] ?? 'unknown error';
            throw new RuntimeException("تلگرام خطا داد: $desc");
        }

        return $decoded['result'];
    }

    public function getUpdates(int $offset, int $timeout = 30): array
    {
        $result = $this->call('getUpdates', [
            'offset' => $offset,
            'timeout' => $timeout,
            'allowed_updates' => json_encode(['message', 'callback_query']),
        ], $timeout + 10);

        return is_array($result) ? $result : [];
    }

    public function sendMessage(int $chatId, string $text, ?array $replyMarkup = null, ?string $parseMode = null): void
    {
        $params = ['chat_id' => $chatId, 'text' => $text];
        if ($replyMarkup !== null) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }
        if ($parseMode !== null) {
            $params['parse_mode'] = $parseMode;
        }
        $this->call('sendMessage', $params);
    }

    /**
     * پیام HTML که اگه از سقف ۴۰۹۶ کاراکتری تلگرام بلندتر باشه، خودکار تکه
     * می‌شه؛ کیبورد فقط به آخرین تکه می‌چسبه.
     */
    public function sendHtml(int $chatId, string $html, ?array $replyMarkup = null): void
    {
        $chunks = Render::chunks($html);
        $last = count($chunks) - 1;
        foreach ($chunks as $index => $chunk) {
            $this->sendMessage($chatId, $chunk, $index === $last ? $replyMarkup : null, 'HTML');
        }
    }

    public function answerCallbackQuery(string $callbackQueryId, ?string $text = null): void
    {
        try {
            $params = ['callback_query_id' => $callbackQueryId];
            if ($text !== null) {
                $params['text'] = $text;
            }
            $this->call('answerCallbackQuery', $params);
        } catch (Throwable) {
            // بی‌اهمیت، فقط برای پاک‌شدن حالت لودینگ دکمه است
        }
    }

    public function sendChatAction(int $chatId, string $action = 'typing'): void
    {
        try {
            $this->call('sendChatAction', ['chat_id' => $chatId, 'action' => $action]);
        } catch (Throwable) {
            // فقط برای UX است، نبودش مهم نیست
        }
    }

    /**
     * پیام‌های حاوی رمز عبور بعد از استفاده از چت پاک می‌شن (تلگرام تا ۴۸
     * ساعت اجازه‌ی حذف می‌ده) تا رمز توی تاریخچه‌ی گفت‌وگو نمونه.
     */
    public function deleteMessage(int $chatId, int $messageId): void
    {
        try {
            $this->call('deleteMessage', ['chat_id' => $chatId, 'message_id' => $messageId]);
        } catch (Throwable) {
            // اگه نشد (پیام قدیمی یا دسترسی نبود) مهم نیست.
        }
    }

    public function deleteWebhook(): void
    {
        $this->call('deleteWebhook', ['drop_pending_updates' => 'true']);
    }

    public function setWebhook(string $url, string $secretToken = ''): void
    {
        $params = ['url' => $url, 'allowed_updates' => json_encode(['message', 'callback_query'])];
        if ($secretToken !== '') {
            // تلگرام این مقدار را در هدر X-Telegram-Bot-Api-Secret-Token
            // می‌فرستد تا مطمئن شویم درخواست واقعاً از تلگرام است.
            $params['secret_token'] = $secretToken;
        }
        $this->call('setWebhook', $params);
    }
}
