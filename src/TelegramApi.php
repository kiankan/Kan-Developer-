<?php

declare(strict_types=1);

final class TelegramApi
{
    public function __construct(private readonly string $token)
    {
    }

    public function call(string $method, array $params = [], int $curlTimeout = 40): array
    {
        $ch = curl_init("https://api.telegram.org/bot{$this->token}/$method");
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
        return $this->call('getUpdates', [
            'offset' => $offset,
            'timeout' => $timeout,
            'allowed_updates' => json_encode(['message', 'callback_query']),
        ], $timeout + 10);
    }

    public function sendMessage(int $chatId, string $text, ?array $replyMarkup = null): void
    {
        $params = ['chat_id' => $chatId, 'text' => $text];
        if ($replyMarkup !== null) {
            $params['reply_markup'] = json_encode($replyMarkup);
        }
        $this->call('sendMessage', $params);
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

    public function deleteWebhook(): void
    {
        $this->call('deleteWebhook', ['drop_pending_updates' => 'true']);
    }

    public function setWebhook(string $url): void
    {
        $this->call('setWebhook', ['url' => $url]);
    }
}
