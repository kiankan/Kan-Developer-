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

    public function sendMessage(int $chatId, string $text, ?array $replyMarkup = null, ?string $parseMode = null): ?int
    {
        $params = ['chat_id' => $chatId, 'text' => $text];
        if ($replyMarkup !== null) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }
        if ($parseMode !== null) {
            $params['parse_mode'] = $parseMode;
        }
        $result = $this->call('sendMessage', $params);

        return is_array($result) && isset($result['message_id']) ? (int) $result['message_id'] : null;
    }

    /**
     * پیام HTML که اگه از سقف ۴۰۹۶ کاراکتری تلگرام بلندتر باشه، خودکار تکه
     * می‌شه؛ کیبورد فقط به آخرین تکه می‌چسبه.
     *
     * @return int|null شناسه‌ی آخرین پیام فرستاده‌شده (برای ویرایش‌های بعدی)
     */
    public function sendHtml(int $chatId, string $html, ?array $replyMarkup = null): ?int
    {
        $chunks = Render::chunks($html);
        $last = count($chunks) - 1;
        $messageId = null;
        foreach ($chunks as $index => $chunk) {
            $messageId = $this->sendMessage($chatId, $chunk, $index === $last ? $replyMarkup : null, 'HTML');
        }

        return $messageId;
    }

    /**
     * ویرایش همان پیام، به‌جای فرستادن پیام جدید — تا با هر دکمه چت پر نشود.
     *
     * @return bool اگر false برگشت یعنی نشد ویرایش کرد و باید پیام تازه فرستاد
     */
    public function editHtml(int $chatId, int $messageId, string $html, ?array $replyMarkup = null): bool
    {
        $params = [
            'chat_id' => $chatId,
            'message_id' => $messageId,
            'text' => $html,
            'parse_mode' => 'HTML',
        ];
        if ($replyMarkup !== null) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }

        try {
            $this->call('editMessageText', $params);

            return true;
        } catch (Throwable $e) {
            // «not modified» یعنی همین حالا هم همان چیزی که می‌خواستیم روی
            // صفحه است؛ خطا نیست.
            return str_contains($e->getMessage(), 'not modified');
        }
    }

    /**
     * اطلاعات یک فایل آپلودشده در تلگرام (برای دانلود و فرستادنش به هاست).
     *
     * @return array{file_path: string, file_size: int}
     */
    public function getFile(string $fileId): array
    {
        $result = $this->call('getFile', ['file_id' => $fileId]);
        $path = is_array($result) ? (string) ($result['file_path'] ?? '') : '';
        if ($path === '') {
            throw new RuntimeException('تلگرام مسیر فایل را برنگرداند.');
        }

        return ['file_path' => $path, 'file_size' => (int) ($result['file_size'] ?? 0)];
    }

    /**
     * دانلود محتوای فایل از تلگرام.
     */
    public function downloadFile(string $filePath): string
    {
        $url = self::baseUrl() . '/file/bot' . $this->token . '/' . ltrim($filePath, '/');

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 120,
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        if ($body === false) {
            throw new RuntimeException("دانلود فایل از تلگرام نشد: $error");
        }
        if ($status !== 200) {
            throw new RuntimeException("دانلود فایل از تلگرام نشد (HTTP $status).");
        }

        return (string) $body;
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
