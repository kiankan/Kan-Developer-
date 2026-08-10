<?php

declare(strict_types=1);

final class UpdateHandler
{
    private const HELP_TEXT = "سلام! هر وقت خواستید یکی از این دستورها رو بفرستید تا همون لحظه جواب بگیرید:\n\n"
        . "/auction <یوزرنیم یا لینک> — زمان باقی‌مانده و وضعیت مزایده Fragment\n"
        . "/tx <هش تراکنش> — وضعیت یک تراکنش TON\n"
        . "/check <یوزرنیم یا لینک یا هش> — تشخیص خودکار نوع ورودی\n\n"
        . "می‌تونید لینک fragment.com، لینک والت (مثل Tonkeeper) یا فقط یوزرنیم رو بفرستید.";

    public static function handle(TelegramApi $api, array $message, ?int $adminId): void
    {
        $chatId = $message['chat']['id'] ?? null;
        $fromId = $message['from']['id'] ?? null;
        $text = trim($message['text'] ?? '');

        if ($chatId === null || $text === '') {
            return;
        }

        if ($adminId !== null && $fromId !== $adminId) {
            return;
        }

        if (self::isCommand($text, '/start') || self::isCommand($text, '/help')) {
            $api->sendMessage($chatId, self::HELP_TEXT);

            return;
        }

        if (self::isCommand($text, '/auction')) {
            $query = self::commandArg($text);
            if ($query === null) {
                $api->sendMessage($chatId, "لطفاً یوزرنیم یا لینک رو هم بفرستید. مثال:\n/auction esalatjonob");

                return;
            }
            self::replyAuction($api, $chatId, $query);

            return;
        }

        if (self::isCommand($text, '/tx')) {
            $query = self::commandArg($text);
            if ($query === null) {
                $api->sendMessage($chatId, "لطفاً هش تراکنش رو هم بفرستید. مثال:\n/tx <hash>");

                return;
            }
            self::replyTx($api, $chatId, $query);

            return;
        }

        if (self::isCommand($text, '/check')) {
            $query = self::commandArg($text);
            if ($query === null) {
                $api->sendMessage($chatId, "لطفاً یوزرنیم، لینک یا هش تراکنش رو بفرستید. مثال:\n/check esalatjonob");

                return;
            }
            self::dispatch($api, $chatId, $query);

            return;
        }

        if (str_starts_with($text, '/')) {
            return;
        }

        self::dispatch($api, $chatId, $text);
    }

    private static function isCommand(string $text, string $name): bool
    {
        return $text === $name || str_starts_with($text, $name . ' ') || str_starts_with($text, $name . '@');
    }

    private static function commandArg(string $text): ?string
    {
        $parts = preg_split('/\s+/', $text, 2);
        $arg = isset($parts[1]) ? trim($parts[1]) : '';

        return $arg !== '' ? $arg : null;
    }

    private static function dispatch(TelegramApi $api, int $chatId, string $query): void
    {
        if (TonClient::looksLikeTxHash($query)) {
            self::replyTx($api, $chatId, $query);
        } else {
            self::replyAuction($api, $chatId, $query);
        }
    }

    private static function replyAuction(TelegramApi $api, int $chatId, string $query): void
    {
        $api->sendChatAction($chatId, 'typing');
        try {
            $data = FragmentClient::fetchAuction($query);
        } catch (FragmentError $e) {
            $api->sendMessage($chatId, '⚠️ ' . $e->getMessage());

            return;
        }
        $api->sendMessage($chatId, Render::auction($data));
    }

    private static function replyTx(TelegramApi $api, int $chatId, string $txHash): void
    {
        $api->sendChatAction($chatId, 'typing');
        try {
            $data = TonClient::fetchTransaction($txHash);
        } catch (TonError $e) {
            $api->sendMessage($chatId, '⚠️ ' . $e->getMessage());

            return;
        }
        $api->sendMessage($chatId, Render::tx($data));
    }
}
