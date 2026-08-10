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
            'allowed_updates' => json_encode(['message']),
        ], $timeout + 10);
    }

    public function sendMessage(int $chatId, string $text): void
    {
        $this->call('sendMessage', ['chat_id' => $chatId, 'text' => $text]);
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
