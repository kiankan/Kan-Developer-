<?php

declare(strict_types=1);

final class UpdateHandler
{
    public static function handle(TelegramApi $api, array $message, ?int $adminId): void
    {
        $chatId = $message['chat']['id'] ?? null;
        $fromId = $message['from']['id'] ?? null;
        $text = trim($message['text'] ?? '');

        if ($chatId === null || $text === '') {
            return;
        }

        // این دستور برای همه در دسترسه، حتی اگه ADMIN_ID تنظیم شده باشه.
        if (self::isCommand($text, '/status')) {
            $api->sendMessage($chatId, self::buildStatusText(), null, 'HTML');

            return;
        }

        if ($adminId !== null && $fromId !== $adminId) {
            return;
        }

        // اگه ادمین وسط ویرایش یه متنه (از پنل ادمین)، پیام بعدیش رو به‌عنوان
        // مقدار جدید در نظر می‌گیریم، نه یک دستور معمولی.
        if (self::handlePendingEdit($api, $chatId, $text)) {
            return;
        }

        if (self::isCommand($text, '/start') || self::isCommand($text, '/help')) {
            $api->sendMessage($chatId, TextStore::get(TextStore::KEY_WELCOME));

            return;
        }

        if (self::isCommand($text, '/admin')) {
            self::sendAdminPanel($api, $chatId);

            return;
        }

        if (self::isCommand($text, '/auction')) {
            $query = self::commandArg($text);
            if ($query === null) {
                $api->sendMessage($chatId, "لطفاً یوزرنیم، لینک یا اسلاگ گیفت رو هم بفرستید. مثال:\n/auction durov");

                return;
            }
            self::replyAuction($api, $chatId, $query);

            return;
        }

        if (self::isCommand($text, '/tx') || self::isCommand($text, '/hash')) {
            $query = self::commandArg($text);
            if ($query === null) {
                $api->sendMessage($chatId, "لطفاً هش تراکنش رو هم بفرستید. مثال:\n/tx <hash>");

                return;
            }
            self::replyTx($api, $chatId, $query);

            return;
        }

        if (self::isCommand($text, '/wallet')) {
            $query = self::commandArg($text);
            if ($query === null) {
                $api->sendMessage($chatId, "لطفاً آدرس ولت رو هم بفرستید (TON, Bitcoin, Ethereum یا Tron). مثال:\n/wallet <آدرس>");

                return;
            }
            self::replyWallet($api, $chatId, $query);

            return;
        }

        if (self::isCommand($text, '/check')) {
            $query = self::commandArg($text);
            if ($query === null) {
                $api->sendMessage($chatId, "لطفاً یوزرنیم، لینک، هش یا آدرس ولت رو بفرستید. مثال:\n/check durov");

                return;
            }
            self::dispatch($api, $chatId, $query);

            return;
        }

        if (str_starts_with($text, '/')) {
            return;
        }

        // بدون دستور: خودکار تشخیص می‌ده هش تراکنشه، آدرس ولته (هر بلاکچین
        // پشتیبانی‌شده) یا یوزرنیم/لینک/گیفت Fragment.
        self::dispatch($api, $chatId, $text);
    }

    public static function handleCallback(TelegramApi $api, array $callbackQuery, ?int $adminId): void
    {
        $callbackId = $callbackQuery['id'] ?? null;
        $data = $callbackQuery['data'] ?? '';
        $fromId = $callbackQuery['from']['id'] ?? null;
        $chatId = $callbackQuery['message']['chat']['id'] ?? null;

        if ($callbackId !== null) {
            $api->answerCallbackQuery($callbackId);
        }
        if ($chatId === null) {
            return;
        }

        if ($data === 'status') {
            $api->sendMessage($chatId, self::buildStatusText(), null, 'HTML');

            return;
        }

        if ($adminId !== null && $fromId !== $adminId) {
            return;
        }

        if ($data === 'help') {
            $api->sendMessage($chatId, TextStore::get(TextStore::KEY_WELCOME));

            return;
        }

        if (str_starts_with($data, 'edit:')) {
            $key = substr($data, strlen('edit:'));
            if (isset(TextStore::LABELS[$key])) {
                EditState::set($key);
                $label = TextStore::LABELS[$key];
                $api->sendMessage($chatId, "✏️ متن جدید برای «{$label}» رو بفرستید (برای لغو: /cancel).");
            }

            return;
        }

        $hints = [
            'auction' => '🎯 یوزرنیم، لینک fragment.com، لینک والت یا اسلاگ گیفت رو بفرستید (دستور لازم نیست).',
            'tx' => '🔗 هش تراکنش TON رو بفرستید (دستور لازم نیست).',
            'wallet' => '👛 آدرس ولت TON، Bitcoin، Ethereum یا Tron رو بفرستید (دستور لازم نیست).',
        ];

        if (isset($hints[$data])) {
            $api->sendMessage($chatId, $hints[$data]);
        }
    }

    /**
     * اگه منتظر متن جدید برای یکی از کلیدهای قابل‌ویرایش بودیم، این پیام رو
     * مصرف می‌کنه و true برمی‌گردونه؛ در غیر این صورت false تا پردازش عادی
     * ادامه پیدا کنه.
     */
    private static function handlePendingEdit(TelegramApi $api, int $chatId, string $text): bool
    {
        $awaiting = EditState::get();
        if ($awaiting === null) {
            return false;
        }

        if ($text === '/cancel') {
            EditState::clear();
            $api->sendMessage($chatId, 'ویرایش لغو شد.');

            return true;
        }

        if (str_starts_with($text, '/')) {
            // ادمین یه دستور دیگه زده؛ حالت ویرایش رو بی‌سروصدا کنسل می‌کنیم
            // و می‌ذاریم دستور جدید عادی پردازش بشه.
            EditState::clear();

            return false;
        }

        TextStore::set($awaiting, $text);
        EditState::clear();
        $label = TextStore::LABELS[$awaiting] ?? $awaiting;
        $api->sendMessage($chatId, "✅ «{$label}» ذخیره شد.");

        return true;
    }

    private static function buildStatusText(): string
    {
        $tehran = new DateTimeImmutable('now', new DateTimeZone('Asia/Tehran'));

        return "🟢 <b>ربات فعال است و آماده‌ی پاسخگویی</b>\n"
            . "🕐 ساعت تهران: {$tehran->format('H:i:s')}\n"
            . '🐘 PHP ' . PHP_VERSION;
    }

    private static function sendAdminPanel(TelegramApi $api, int $chatId): void
    {
        $keyboard = [
            'inline_keyboard' => [
                [['text' => '🟢 وضعیت ربات', 'callback_data' => 'status']],
                [
                    ['text' => '🎯 چکر مزایده/گیفت Fragment', 'callback_data' => 'auction'],
                    ['text' => '🔗 چکر هش تراکنش', 'callback_data' => 'tx'],
                ],
                [['text' => '👛 چکر موجودی ولت', 'callback_data' => 'wallet']],
                [['text' => '✏️ ویرایش پیام خوش‌آمد', 'callback_data' => 'edit:' . TextStore::KEY_WELCOME]],
                [['text' => '❓ راهنما', 'callback_data' => 'help']],
            ],
        ];
        $text = "🛠 <b>پنل مدیریت kan_checker</b>\n\n"
            . 'یکی از گزینه‌ها رو بزنید، یا مستقیم یوزرنیم/لینک/هش/آدرس ولت رو بفرستید — خودش تشخیص می‌ده.';
        $api->sendMessage($chatId, $text, $keyboard, 'HTML');
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
        } elseif (WalletClient::looksLikeAddress($query)) {
            self::replyWallet($api, $chatId, $query);
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

    private static function replyWallet(TelegramApi $api, int $chatId, string $address): void
    {
        $api->sendChatAction($chatId, 'typing');
        try {
            $data = WalletClient::fetchBalance($address);
        } catch (WalletError $e) {
            $api->sendMessage($chatId, '⚠️ ' . $e->getMessage());

            return;
        }
        $api->sendMessage($chatId, Render::wallet($data));
    }
}
