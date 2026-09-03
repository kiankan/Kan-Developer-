<?php

declare(strict_types=1);

/**
 * رابط تلگرامی مدیریت cPanel/WHM.
 *
 * دو نوع تعامل داریم:
 *   - دکمه‌ها (callback_data) برای منوها و اجرای کارهای بدون ورودی
 *   - «جریان‌ها» (FLOWS) برای کارهایی که ورودی می‌خوان؛ هر جریان چند مرحله
 *     پرسش متنی داره و در انتها یک متد run… رو صدا می‌زنه.
 *
 * چون این ربات می‌تونه ایمیل و دیتابیس حذف کنه یا حساب رو معلق کنه، فقط به
 * آیدی‌های داخل ADMIN_ID/ADMIN_IDS پاسخ می‌ده و هر عملیات مخرب یک مرحله‌ی
 * تأیید صریح داره.
 */
final class UpdateHandler
{
    private static string $actor = 'unknown';

    /**
     * تعریف جریان‌های چندمرحله‌ای.
     *
     * scope: account = نیازمند حساب cPanel (روی WHM باید کاربر مقصد ست باشد)،
     *        whm = فقط روی سرور WHM، local = بدون تماس با سرور.
     */
    private const FLOWS = [
        'email_add' => [
            'title' => '✉️ افزودن حساب ایمیل',
            'scope' => 'account',
            'steps' => [
                ['key' => 'email', 'prompt' => 'آدرس کامل ایمیل جدید را بفرستید (مثل <code>info@example.com</code>):'],
                ['key' => 'password', 'prompt' => 'رمز عبور را بفرستید (یا <code>/gen</code> برای ساخت رمز قوی):', 'secret' => true],
                ['key' => 'quota', 'prompt' => 'سهمیه به مگابایت (<code>0</code> = نامحدود):', 'optional' => true, 'default' => '0'],
            ],
        ],
        'email_passwd' => [
            'title' => '🔑 تغییر رمز ایمیل',
            'scope' => 'account',
            'steps' => [
                ['key' => 'email', 'prompt' => 'آدرس کامل ایمیل را بفرستید:'],
                ['key' => 'password', 'prompt' => 'رمز جدید را بفرستید (یا <code>/gen</code>):', 'secret' => true],
            ],
        ],
        'email_quota' => [
            'title' => '📦 تغییر سهمیه‌ی ایمیل',
            'scope' => 'account',
            'steps' => [
                ['key' => 'email', 'prompt' => 'آدرس کامل ایمیل را بفرستید:'],
                ['key' => 'quota', 'prompt' => 'سهمیه‌ی جدید به مگابایت (<code>0</code> = نامحدود):'],
            ],
        ],
        'email_del' => [
            'title' => '🗑 حذف حساب ایمیل',
            'scope' => 'account',
            'confirm' => '⚠️ حساب <code>{email}</code> و همه‌ی پیام‌هایش برای همیشه حذف می‌شود.',
            'steps' => [
                ['key' => 'email', 'prompt' => 'آدرس کامل ایمیلی که باید حذف شود را بفرستید:'],
            ],
        ],
        'db_add' => [
            'title' => '🗄 ساخت دیتابیس',
            'scope' => 'account',
            'steps' => [
                ['key' => 'name', 'prompt' => "نام دیتابیس را بفرستید.\n\n💡 در cPanel نام باید با پیشوند حساب باشد، مثل <code>user_shop</code>."],
            ],
        ],
        'db_del' => [
            'title' => '🗑 حذف دیتابیس',
            'scope' => 'account',
            'confirm' => '⚠️ دیتابیس <code>{name}</code> و تمام داده‌هایش برای همیشه حذف می‌شود.',
            'steps' => [
                ['key' => 'name', 'prompt' => 'نام کامل دیتابیس را بفرستید:'],
            ],
        ],
        'dbuser_add' => [
            'title' => '👤 ساخت کاربر دیتابیس',
            'scope' => 'account',
            'steps' => [
                ['key' => 'name', 'prompt' => 'نام کاربر را بفرستید (با پیشوند حساب، مثل <code>user_shop</code>):'],
                ['key' => 'password', 'prompt' => 'رمز عبور را بفرستید (یا <code>/gen</code>):', 'secret' => true],
            ],
        ],
        'dbuser_del' => [
            'title' => '🗑 حذف کاربر دیتابیس',
            'scope' => 'account',
            'confirm' => '⚠️ کاربر <code>{name}</code> حذف می‌شود و دسترسی‌هایش از بین می‌رود.',
            'steps' => [
                ['key' => 'name', 'prompt' => 'نام کامل کاربر دیتابیس را بفرستید:'],
            ],
        ],
        'db_grant' => [
            'title' => '🔗 دادن دسترسی کاربر به دیتابیس',
            'scope' => 'account',
            'steps' => [
                ['key' => 'user', 'prompt' => 'نام کاربر دیتابیس را بفرستید:'],
                ['key' => 'database', 'prompt' => 'نام دیتابیس را بفرستید:'],
                ['key' => 'privileges', 'prompt' => 'سطح دسترسی (پیش‌فرض <code>ALL PRIVILEGES</code>):', 'optional' => true, 'default' => 'ALL PRIVILEGES'],
            ],
        ],
        'sub_add' => [
            'title' => '➕ افزودن ساب‌دامنه',
            'scope' => 'account',
            'steps' => [
                ['key' => 'subdomain', 'prompt' => 'فقط بخش ساب‌دامنه را بفرستید (مثل <code>shop</code>):'],
                ['key' => 'rootdomain', 'prompt' => 'دامنه‌ی اصلی را بفرستید (مثل <code>example.com</code>):'],
                ['key' => 'dir', 'prompt' => 'مسیر پوشه (اختیاری، خالی = پیش‌فرض cPanel):', 'optional' => true, 'default' => ''],
            ],
        ],
        'sub_del' => [
            'title' => '🗑 حذف ساب‌دامنه',
            'scope' => 'account',
            'confirm' => '⚠️ ساب‌دامنه‌ی <code>{domain}</code> حذف می‌شود (فایل‌های پوشه‌اش باقی می‌مانند).',
            'steps' => [
                ['key' => 'domain', 'prompt' => 'ساب‌دامنه‌ی کامل را بفرستید (مثل <code>shop.example.com</code>):'],
            ],
        ],
        'ftp_add' => [
            'title' => '📁 افزودن حساب FTP',
            'scope' => 'account',
            'steps' => [
                ['key' => 'user', 'prompt' => 'نام کاربری FTP را بفرستید (مثل <code>deploy@example.com</code> یا فقط <code>deploy</code>):'],
                ['key' => 'password', 'prompt' => 'رمز عبور را بفرستید (یا <code>/gen</code>):', 'secret' => true],
                ['key' => 'dir', 'prompt' => 'مسیر پوشه (اختیاری):', 'optional' => true, 'default' => ''],
                ['key' => 'quota', 'prompt' => 'سهمیه به مگابایت (<code>0</code> = نامحدود):', 'optional' => true, 'default' => '0'],
            ],
        ],
        'ftp_del' => [
            'title' => '🗑 حذف حساب FTP',
            'scope' => 'account',
            'confirm' => '⚠️ حساب FTP <code>{user}</code> حذف می‌شود (فایل‌ها پاک نمی‌شوند).',
            'steps' => [
                ['key' => 'user', 'prompt' => 'نام کاربری FTP را بفرستید:'],
            ],
        ],
        'cron_add' => [
            'title' => '⏰ افزودن کران‌جاب',
            'scope' => 'account',
            'steps' => [
                ['key' => 'schedule', 'prompt' => "زمان‌بندی را با ۵ فیلد استاندارد کران بفرستید.\n\nمثال هر ساعت: <code>0 * * * *</code>\nمثال هر ۵ دقیقه: <code>*/5 * * * *</code>"],
                ['key' => 'command', 'prompt' => 'دستوری که باید اجرا شود را بفرستید:'],
            ],
        ],
        'cron_del' => [
            'title' => '🗑 حذف کران‌جاب',
            'scope' => 'account',
            'confirm' => '⚠️ کران‌جاب با کلید <code>{key}</code> حذف می‌شود.',
            'steps' => [
                ['key' => 'key', 'prompt' => "کلید (🔑) کران‌جاب را از خروجی «لیست کران‌جاب‌ها» بفرستید:"],
            ],
        ],
        'dns_list' => [
            'title' => '🧭 نمایش رکوردهای DNS',
            'scope' => 'account',
            'steps' => [
                ['key' => 'zone', 'prompt' => 'نام زون (دامنه) را بفرستید، مثل <code>example.com</code>:'],
            ],
        ],
        'dns_add' => [
            'title' => '➕ افزودن رکورد DNS',
            'scope' => 'account',
            'steps' => [
                ['key' => 'zone', 'prompt' => 'نام زون (دامنه) را بفرستید:'],
                ['key' => 'name', 'prompt' => "نام رکورد را بفرستید (مثل <code>www</code> یا <code>@</code> برای خود دامنه):"],
                ['key' => 'type', 'prompt' => 'نوع رکورد: <code>A</code>، <code>AAAA</code>، <code>CNAME</code>، <code>TXT</code>، <code>MX</code>، <code>NS</code>'],
                ['key' => 'value', 'prompt' => "مقدار رکورد را بفرستید.\n\nبرای MX اول اولویت و بعد میزبان، مثل: <code>10 mail.example.com.</code>"],
                ['key' => 'ttl', 'prompt' => 'TTL (اختیاری، پیش‌فرض <code>14400</code>):', 'optional' => true, 'default' => '14400'],
            ],
        ],
        'dns_del' => [
            'title' => '🗑 حذف رکورد DNS',
            'scope' => 'account',
            'confirm' => '⚠️ رکورد شماره‌ی <code>{line}</code> از زون <code>{zone}</code> حذف می‌شود.',
            'steps' => [
                ['key' => 'zone', 'prompt' => 'نام زون (دامنه) را بفرستید:'],
                ['key' => 'line', 'prompt' => 'شماره‌ی خط رکورد را بفرستید (همان عدد داخل [] در لیست رکوردها):'],
            ],
        ],
        'php_set' => [
            'title' => '🐘 تغییر نسخه‌ی PHP دامنه',
            'scope' => 'account',
            'steps' => [
                ['key' => 'vhost', 'prompt' => 'دامنه (vhost) را بفرستید:'],
                ['key' => 'version', 'prompt' => 'نسخه را بفرستید، مثل <code>ea-php82</code> یا <code>inherit</code>:'],
            ],
        ],
        'backup_run' => [
            'title' => '💾 گرفتن بکاپ کامل',
            'scope' => 'account',
            'confirm' => '⚠️ ساخت بکاپ کامل شروع می‌شود؛ بسته به حجم حساب، فضای هوم و مقداری CPU مصرف می‌کند.',
            'steps' => [
                ['key' => 'email', 'prompt' => 'ایمیل برای اطلاع‌رسانی پایان بکاپ (اختیاری):', 'optional' => true, 'default' => ''],
            ],
        ],
        'whm_user' => [
            'title' => '🎯 انتخاب کاربر مقصد',
            'scope' => 'local',
            'steps' => [
                ['key' => 'user', 'prompt' => 'نام کاربری حسابی که می‌خواهید مدیریت کنید را بفرستید:'],
            ],
        ],
        'whm_summary' => [
            'title' => '🔍 جزئیات حساب',
            'scope' => 'whm',
            'steps' => [
                ['key' => 'user', 'prompt' => 'نام کاربری حساب را بفرستید:'],
            ],
        ],
        'whm_suspend' => [
            'title' => '⛔️ تعلیق حساب',
            'scope' => 'whm',
            'confirm' => '⚠️ حساب <code>{user}</code> معلق می‌شود؛ سایت‌ها و ایمیل‌هایش از کار می‌افتند.',
            'steps' => [
                ['key' => 'user', 'prompt' => 'نام کاربری حساب را بفرستید:'],
                ['key' => 'reason', 'prompt' => 'دلیل تعلیق (اختیاری):', 'optional' => true, 'default' => ''],
            ],
        ],
        'whm_unsuspend' => [
            'title' => '✅ رفع تعلیق حساب',
            'scope' => 'whm',
            'steps' => [
                ['key' => 'user', 'prompt' => 'نام کاربری حساب را بفرستید:'],
            ],
        ],
        'whm_passwd' => [
            'title' => '🔑 تغییر رمز حساب',
            'scope' => 'whm',
            'confirm' => '⚠️ رمز حساب <code>{user}</code> عوض می‌شود (رمز دیتابیس‌ها و FTP همان حساب هم می‌تواند تغییر کند).',
            'steps' => [
                ['key' => 'user', 'prompt' => 'نام کاربری حساب را بفرستید:'],
                ['key' => 'password', 'prompt' => 'رمز جدید را بفرستید (یا <code>/gen</code>):', 'secret' => true],
            ],
        ],
        'whm_login' => [
            'title' => '🔐 لینک ورود یک‌بارمصرف',
            'scope' => 'whm',
            'steps' => [
                ['key' => 'user', 'prompt' => 'نام کاربری حساب را بفرستید:'],
            ],
        ],
        'whm_create' => [
            'title' => '🆕 ساخت حساب جدید',
            'scope' => 'whm',
            'confirm' => '⚠️ حساب <code>{username}</code> با دامنه‌ی <code>{domain}</code> ساخته می‌شود.',
            'steps' => [
                ['key' => 'username', 'prompt' => 'نام کاربری حساب جدید:'],
                ['key' => 'domain', 'prompt' => 'دامنه‌ی اصلی حساب:'],
                ['key' => 'password', 'prompt' => 'رمز عبور (یا <code>/gen</code>):', 'secret' => true],
                ['key' => 'plan', 'prompt' => 'نام پلن (اختیاری، خالی = پیش‌فرض):', 'optional' => true, 'default' => ''],
            ],
        ],
        'whm_terminate' => [
            'title' => '💀 حذف کامل حساب',
            'scope' => 'whm',
            'confirm' => '⚠️ حساب <code>{user}</code> با تمام سایت‌ها، ایمیل‌ها و دیتابیس‌هایش برای همیشه حذف می‌شود. این کار برگشت‌پذیر نیست.',
            'steps' => [
                ['key' => 'user', 'prompt' => 'نام کاربری حسابی که باید حذف شود:'],
                ['key' => 'confirm_user', 'prompt' => 'برای اطمینان، دقیقاً همان نام کاربری را دوباره بفرستید:'],
            ],
        ],
        'srv_add' => [
            'title' => '➕ افزودن سرور',
            'scope' => 'local',
            'steps' => [
                ['key' => 'label', 'prompt' => 'یک اسم دلخواه برای این سرور بفرستید (مثل <code>هاست اصلی</code>):'],
                ['key' => 'type', 'prompt' => "نوع اتصال را بفرستید:\n<code>cpanel</code> = توکن یک حساب cPanel\n<code>whm</code> = توکن root/ریسلر روی WHM"],
                ['key' => 'host', 'prompt' => 'هاست یا آی‌پی سرور را بفرستید (مثل <code>server.example.com</code>):'],
                ['key' => 'port', 'prompt' => 'پورت (اختیاری، پیش‌فرض 2083 برای cPanel و 2087 برای WHM):', 'optional' => true, 'default' => ''],
                ['key' => 'user', 'prompt' => 'نام کاربری (برای WHM معمولاً <code>root</code>):'],
                ['key' => 'token', 'prompt' => "API Token را بفرستید.\n\ncPanel: Security → Manage API Tokens\nWHM: Development → Manage API Tokens", 'secret' => true],
            ],
        ],
        'srv_use' => [
            'title' => '🔀 انتخاب سرور فعال',
            'scope' => 'local',
            'steps' => [
                ['key' => 'id', 'prompt' => 'شناسه‌ی (🆔) سرور موردنظر را از لیست سرورها بفرستید:'],
            ],
        ],
        'srv_del' => [
            'title' => '🗑 حذف سرور',
            'scope' => 'local',
            'confirm' => '⚠️ سرور <code>{id}</code> از فهرست حذف می‌شود (روی خود سرور چیزی تغییر نمی‌کند).',
            'steps' => [
                ['key' => 'id', 'prompt' => 'شناسه‌ی (🆔) سروری که باید حذف شود را بفرستید:'],
            ],
        ],
    ];

    /**
     * @param array<int, int> $adminIds
     */
    public static function handle(TelegramApi $api, array $message, array $adminIds): void
    {
        $chatId = $message['chat']['id'] ?? null;
        $fromId = $message['from']['id'] ?? null;
        $messageId = $message['message_id'] ?? null;
        $text = trim((string) ($message['text'] ?? ''));

        if ($chatId === null || $text === '') {
            return;
        }
        if (!self::authorize($api, (int) $chatId, $fromId, $adminIds)) {
            return;
        }
        self::$actor = 'tg:' . $fromId;

        if ($text === '/cancel') {
            State::clear((int) $chatId);
            $api->sendHtml((int) $chatId, '❌ عملیات لغو شد.', self::mainKeyboard());

            return;
        }

        // اگر وسط یک جریان چندمرحله‌ای هستیم، این پیام جواب همان مرحله است.
        $state = State::get((int) $chatId);
        if ($state !== null && self::isFlowInput($text)) {
            self::continueFlow($api, (int) $chatId, $state, $text, is_int($messageId) ? $messageId : null);

            return;
        }
        if ($state !== null) {
            // دستور جدید یعنی جریان قبلی رها شده.
            State::clear((int) $chatId);
        }

        self::handleCommand($api, (int) $chatId, $text);
    }

    /**
     * @param array<int, int> $adminIds
     */
    public static function handleCallback(TelegramApi $api, array $callbackQuery, array $adminIds): void
    {
        $callbackId = $callbackQuery['id'] ?? null;
        $data = (string) ($callbackQuery['data'] ?? '');
        $fromId = $callbackQuery['from']['id'] ?? null;
        $chatId = $callbackQuery['message']['chat']['id'] ?? null;

        if ($callbackId !== null) {
            $api->answerCallbackQuery((string) $callbackId);
        }
        if ($chatId === null || !self::authorize($api, (int) $chatId, $fromId, $adminIds)) {
            return;
        }
        self::$actor = 'tg:' . $fromId;
        $chatId = (int) $chatId;

        if ($data === 'no') {
            State::clear($chatId);
            $api->sendHtml($chatId, '❌ عملیات لغو شد.', self::mainKeyboard());

            return;
        }

        if ($data === 'ok') {
            $state = State::get($chatId);
            if ($state === null || $state['step'] !== -1) {
                $api->sendHtml($chatId, 'چیزی برای تأیید در انتظار نیست.', self::mainKeyboard());

                return;
            }
            State::clear($chatId);
            self::execute($api, $chatId, $state['flow'], $state['data']);

            return;
        }

        if (str_starts_with($data, 'f:')) {
            self::startFlow($api, $chatId, substr($data, 2));

            return;
        }

        if (str_starts_with($data, 'use:')) {
            self::execute($api, $chatId, 'srv_use', ['id' => substr($data, 4)]);

            return;
        }

        if (str_starts_with($data, 'm:') || $data === 'menu') {
            self::sendMenu($api, $chatId, $data === 'menu' ? 'main' : substr($data, 2));

            return;
        }

        if (str_starts_with($data, 'a:')) {
            self::runAction($api, $chatId, substr($data, 2));

            return;
        }
    }

    // ------------------------------------------------------------- دستورها

    /**
     * وقتی وسط یک جریان هستیم، پیام معمولی جواب همان مرحله است و پیام دستوری
     * یعنی کاربر جریان را رها کرده — به‌جز /skip و /gen که خودشان بخشی از
     * جریان‌اند و نباید به‌عنوان دستور جدید تفسیر شوند.
     */
    public static function isFlowInput(string $text): bool
    {
        return !str_starts_with($text, '/') || in_array($text, ['/skip', '/gen'], true);
    }

    private static function handleCommand(TelegramApi $api, int $chatId, string $text): void
    {
        $parts = preg_split('/\s+/', $text, 2) ?: [$text];
        $command = strtolower((string) preg_replace('/@.*$/', '', $parts[0]));
        $arg = trim($parts[1] ?? '');

        switch ($command) {
            case '/start':
            case '/help':
                $api->sendHtml($chatId, self::welcome(), self::mainKeyboard());

                return;
            case '/panel':
            case '/menu':
                self::sendMenu($api, $chatId, 'main');

                return;
            case '/servers':
                self::sendMenu($api, $chatId, 'srv');

                return;
            case '/whm':
                self::sendMenu($api, $chatId, 'whm');

                return;
            case '/use':
                if ($arg === '') {
                    self::runAction($api, $chatId, 'servers');

                    return;
                }
                self::execute($api, $chatId, 'srv_use', ['id' => $arg]);

                return;
            case '/dns':
                if ($arg === '') {
                    self::startFlow($api, $chatId, 'dns_list');

                    return;
                }
                self::execute($api, $chatId, 'dns_list', ['zone' => $arg]);

                return;
            case '/ping':
                self::runAction($api, $chatId, 'ping');

                return;
            case '/summary':
            case '/status':
                self::runAction($api, $chatId, 'summary');

                return;
            case '/emails':
                self::runAction($api, $chatId, 'emails');

                return;
            case '/databases':
            case '/db':
                self::runAction($api, $chatId, 'dbs');

                return;
            case '/domains':
                self::runAction($api, $chatId, 'domains');

                return;
            case '/ftp':
                self::runAction($api, $chatId, 'ftps');

                return;
            case '/cron':
                self::runAction($api, $chatId, 'crons');

                return;
            case '/ssl':
                self::runAction($api, $chatId, 'ssl');

                return;
            case '/php':
                self::runAction($api, $chatId, 'php');

                return;
            case '/log':
                self::runAction($api, $chatId, 'log');

                return;
        }

        if (str_starts_with($text, '/')) {
            $api->sendHtml($chatId, 'این دستور را نمی‌شناسم. از منوی زیر استفاده کنید:', self::mainKeyboard());

            return;
        }

        $api->sendHtml(
            $chatId,
            'برای مدیریت، یکی از دکمه‌های زیر را بزنید (یا <code>/panel</code> را بفرستید):',
            self::mainKeyboard(),
        );
    }

    // --------------------------------------------------------------- منوها

    private static function sendMenu(TelegramApi $api, int $chatId, string $menu): void
    {
        $server = ServerStore::active();
        $header = self::serverHeader($server);

        [$text, $keyboard] = match ($menu) {
            'email' => ['✉️ <b>مدیریت ایمیل</b>', [
                [self::btn('📋 لیست حساب‌ها', 'a:emails')],
                [self::btn('➕ افزودن', 'f:email_add'), self::btn('🗑 حذف', 'f:email_del')],
                [self::btn('🔑 تغییر رمز', 'f:email_passwd'), self::btn('📦 سهمیه', 'f:email_quota')],
                [self::btn('⬅️ بازگشت', 'menu')],
            ]],
            'db' => ['🗄 <b>مدیریت دیتابیس</b>', [
                [self::btn('📋 لیست دیتابیس‌ها و کاربران', 'a:dbs')],
                [self::btn('➕ دیتابیس جدید', 'f:db_add'), self::btn('🗑 حذف دیتابیس', 'f:db_del')],
                [self::btn('👤 کاربر جدید', 'f:dbuser_add'), self::btn('🗑 حذف کاربر', 'f:dbuser_del')],
                [self::btn('🔗 دادن دسترسی به کاربر', 'f:db_grant')],
                [self::btn('⬅️ بازگشت', 'menu')],
            ]],
            'dom' => ['🌐 <b>مدیریت دامنه‌ها</b>', [
                [self::btn('📋 لیست دامنه‌ها', 'a:domains')],
                [self::btn('➕ ساب‌دامنه', 'f:sub_add'), self::btn('🗑 حذف ساب‌دامنه', 'f:sub_del')],
                [self::btn('🧭 DNS', 'm:dns'), self::btn('🔒 SSL', 'a:ssl')],
                [self::btn('⬅️ بازگشت', 'menu')],
            ]],
            'ftp' => ['📁 <b>مدیریت FTP</b>', [
                [self::btn('📋 لیست حساب‌ها', 'a:ftps')],
                [self::btn('➕ افزودن', 'f:ftp_add'), self::btn('🗑 حذف', 'f:ftp_del')],
                [self::btn('⬅️ بازگشت', 'menu')],
            ]],
            'cron' => ['⏰ <b>مدیریت کران‌جاب</b>', [
                [self::btn('📋 لیست کران‌جاب‌ها', 'a:crons')],
                [self::btn('➕ افزودن', 'f:cron_add'), self::btn('🗑 حذف', 'f:cron_del')],
                [self::btn('⬅️ بازگشت', 'menu')],
            ]],
            'dns' => ['🧭 <b>مدیریت DNS</b>', [
                [self::btn('📄 نمایش رکوردها', 'f:dns_list')],
                [self::btn('➕ رکورد جدید', 'f:dns_add'), self::btn('🗑 حذف رکورد', 'f:dns_del')],
                [self::btn('⬅️ بازگشت', 'menu')],
            ]],
            'php' => ['🐘 <b>نسخه‌ی PHP</b>', [
                [self::btn('📋 نسخه‌ی هر دامنه', 'a:php')],
                [self::btn('🔧 تغییر نسخه', 'f:php_set')],
                [self::btn('⬅️ بازگشت', 'menu')],
            ]],
            'whm' => ['🖥 <b>مدیریت سرور (WHM)</b>', [
                [self::btn('👥 لیست حساب‌ها', 'a:whmaccts'), self::btn('🔍 جزئیات حساب', 'f:whm_summary')],
                [self::btn('⛔️ تعلیق', 'f:whm_suspend'), self::btn('✅ رفع تعلیق', 'f:whm_unsuspend')],
                [self::btn('🆕 ساخت حساب', 'f:whm_create'), self::btn('🔑 تغییر رمز', 'f:whm_passwd')],
                [self::btn('🔐 لینک ورود', 'f:whm_login'), self::btn('💀 حذف حساب', 'f:whm_terminate')],
                [self::btn('🖥 وضعیت سرور', 'a:whmstatus'), self::btn('📦 پلن‌ها', 'a:whmpkgs')],
                [self::btn('🎯 انتخاب کاربر مقصد', 'f:whm_user')],
                [self::btn('⬅️ بازگشت', 'menu')],
            ]],
            'srv' => ['🖥 <b>سرورها</b>', array_merge(
                [[self::btn('📋 لیست سرورها', 'a:servers'), self::btn('🔌 تست اتصال', 'a:ping')]],
                self::serverPickerRows(),
                [
                    [self::btn('➕ افزودن سرور', 'f:srv_add'), self::btn('🗑 حذف سرور', 'f:srv_del')],
                    [self::btn('⬅️ بازگشت', 'menu')],
                ],
            )],
            default => ['🛠 <b>پنل مدیریت cPanel</b>', self::mainKeyboard()['inline_keyboard']],
        };

        $api->sendHtml($chatId, $header . "\n\n" . $text, ['inline_keyboard' => $keyboard]);
    }

    /**
     * @return array{inline_keyboard: array<int, array<int, array<string, string>>>}
     */
    private static function mainKeyboard(): array
    {
        return ['inline_keyboard' => [
            [self::btn('📊 خلاصه‌ی حساب', 'a:summary'), self::btn('🌐 دامنه‌ها', 'm:dom')],
            [self::btn('✉️ ایمیل‌ها', 'm:email'), self::btn('🗄 دیتابیس‌ها', 'm:db')],
            [self::btn('📁 FTP', 'm:ftp'), self::btn('⏰ کران‌جاب', 'm:cron')],
            [self::btn('🧭 DNS', 'm:dns'), self::btn('🐘 نسخه PHP', 'm:php')],
            [self::btn('🔒 SSL', 'a:ssl'), self::btn('💾 بکاپ کامل', 'f:backup_run')],
            [self::btn('🖥 WHM', 'm:whm'), self::btn('⚙️ سرورها', 'm:srv')],
            [self::btn('🧾 لاگ عملیات', 'a:log'), self::btn('❓ راهنما', 'a:help')],
        ]];
    }

    /**
     * @return array<int, array<int, array<string, string>>>
     */
    private static function serverPickerRows(): array
    {
        $rows = [];
        $activeId = ServerStore::activeId();
        foreach (ServerStore::all() as $id => $server) {
            $mark = $id === $activeId ? '✅ ' : '▫️ ';
            $rows[] = [self::btn($mark . (string) $server['label'], 'use:' . $id)];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed>|null $server
     */
    private static function serverHeader(?array $server): string
    {
        if ($server === null) {
            return '⚠️ هنوز هیچ سروری تنظیم نشده — از «⚙️ سرورها → ➕ افزودن سرور» شروع کنید.';
        }

        $type = $server['type'] === CpanelClient::TYPE_WHM ? 'WHM' : 'cPanel';
        $line = '🖥 سرور فعال: <b>' . Format::escapeHtml((string) $server['label']) . "</b> ($type)";
        if ($server['type'] === CpanelClient::TYPE_WHM) {
            $line .= ($server['as_user'] ?? '') !== ''
                ? "\n🎯 کاربر مقصد: <code>" . Format::escapeHtml((string) $server['as_user']) . '</code>'
                : "\n🎯 کاربر مقصد انتخاب نشده (برای کارهای سطح حساب لازم است).";
        }

        return $line;
    }

    /**
     * @return array<string, string>
     */
    private static function btn(string $text, string $data): array
    {
        return ['text' => $text, 'callback_data' => $data];
    }

    // ------------------------------------------------------------ کارها

    private static function runAction(TelegramApi $api, int $chatId, string $action): void
    {
        if ($action === 'help') {
            $api->sendHtml($chatId, self::welcome(), self::mainKeyboard());

            return;
        }

        if ($action === 'servers') {
            $api->sendHtml($chatId, Render::servers(ServerStore::all(), ServerStore::activeId()), self::mainKeyboard());

            return;
        }

        if ($action === 'log') {
            $lines = AuditLog::tail(15);
            $text = $lines === []
                ? '🧾 هنوز عملیاتی ثبت نشده.'
                : "🧾 <b>آخرین عملیات‌ها</b>\n\n<code>" . Format::escapeHtml(implode("\n", $lines)) . '</code>';
            $api->sendHtml($chatId, $text, self::mainKeyboard());

            return;
        }

        $api->sendChatAction($chatId);
        try {
            $text = match ($action) {
                'ping' => Render::ping(ServerStore::client()->ping()),
                'summary' => Render::summary(CpanelService::summary(self::accountClient())),
                'emails' => Render::emails(CpanelService::emails(self::accountClient())),
                'dbs' => Render::databases(
                    CpanelService::databases(self::accountClient()),
                    CpanelService::databaseUsers(self::accountClient()),
                ),
                'domains' => Render::domains(CpanelService::domains(self::accountClient())),
                'ftps' => Render::ftp(CpanelService::ftpAccounts(self::accountClient())),
                'crons' => Render::cron(CpanelService::cronJobs(self::accountClient())),
                'ssl' => Render::ssl(CpanelService::sslHosts(self::accountClient())),
                'php' => Render::php(
                    CpanelService::phpVersions(self::accountClient()),
                    CpanelService::installedPhpVersions(self::accountClient()),
                ),
                'whmaccts' => Render::whmAccounts(CpanelService::whmAccounts(self::whmClient())),
                'whmstatus' => Render::whmStatus(CpanelService::whmServerStatus(self::whmClient())),
                'whmpkgs' => '📦 <b>پلن‌ها</b>' . "\n\n" . implode("\n", array_map(
                    static fn (string $plan): string => '• <code>' . Format::escapeHtml($plan) . '</code>',
                    CpanelService::whmPackages(self::whmClient()),
                )),
                default => 'این گزینه را نمی‌شناسم.',
            };
        } catch (Throwable $e) {
            $text = Render::error($e);
        }

        $api->sendHtml($chatId, $text, self::mainKeyboard());
    }

    // -------------------------------------------------------------- جریان‌ها

    private static function startFlow(TelegramApi $api, int $chatId, string $flow): void
    {
        if (!isset(self::FLOWS[$flow])) {
            $api->sendHtml($chatId, 'این عملیات را نمی‌شناسم.', self::mainKeyboard());

            return;
        }

        // خطاهای پیکربندی (سرور نداریم، کاربر مقصد ست نشده و ...) رو همین اول
        // می‌گیریم تا کاربر بعد از پر کردن ۴ مرحله به دیوار نخوره.
        try {
            self::clientFor($flow);
        } catch (Throwable $e) {
            $api->sendHtml($chatId, Render::error($e), self::mainKeyboard());

            return;
        }

        State::set($chatId, $flow, 0, []);
        self::askStep($api, $chatId, $flow, 0);
    }

    private static function askStep(TelegramApi $api, int $chatId, string $flow, int $step): void
    {
        $definition = self::FLOWS[$flow];
        $stepDefinition = $definition['steps'][$step];
        $optional = ($stepDefinition['optional'] ?? false) === true;

        $text = '<b>' . $definition['title'] . '</b>  (مرحله ' . ($step + 1) . ' از ' . count($definition['steps']) . ")\n\n"
            . $stepDefinition['prompt'];
        $text .= $optional ? "\n\nبرای رد شدن <code>/skip</code> و برای لغو <code>/cancel</code>." : "\n\nبرای لغو <code>/cancel</code>.";

        $api->sendHtml($chatId, $text);
    }

    /**
     * @param array{flow: string, step: int, data: array<string, mixed>} $state
     */
    private static function continueFlow(TelegramApi $api, int $chatId, array $state, string $text, ?int $messageId): void
    {
        $flow = $state['flow'];
        if (!isset(self::FLOWS[$flow])) {
            State::clear($chatId);

            return;
        }
        if ($state['step'] === -1) {
            $api->sendHtml($chatId, 'لطفاً با دکمه‌ی «تأیید» یا «انصراف» جواب بدهید.', self::confirmKeyboard());

            return;
        }

        $definition = self::FLOWS[$flow];
        $stepDefinition = $definition['steps'][$state['step']];
        $data = $state['data'];
        $value = trim($text);

        if ($value === '/skip') {
            if (($stepDefinition['optional'] ?? false) !== true) {
                $api->sendHtml($chatId, '⚠️ این مرحله اجباری است.');

                return;
            }
            $value = (string) ($stepDefinition['default'] ?? '');
        }

        if ($value === '/gen' && ($stepDefinition['secret'] ?? false) !== true) {
            $api->sendHtml($chatId, '⚠️ <code>/gen</code> فقط برای مرحله‌های رمز عبور است؛ مقدار این مرحله را بفرستید.');

            return;
        }

        if (($stepDefinition['secret'] ?? false) === true) {
            if ($value === '/gen') {
                $value = self::generatePassword();
                $api->sendHtml($chatId, "🔐 رمز ساخته‌شده (همین حالا جایی ذخیره‌اش کنید):\n<code>" . Format::escapeHtml($value) . '</code>');
            } elseif ($messageId !== null) {
                // رمزی که کاربر تایپ کرده از تاریخچه‌ی چت پاک می‌شه.
                $api->deleteMessage($chatId, $messageId);
            }
        }

        if ($value === '' && ($stepDefinition['optional'] ?? false) !== true) {
            $api->sendHtml($chatId, '⚠️ مقدار خالی است، دوباره بفرستید.');

            return;
        }

        $data[$stepDefinition['key']] = $value;
        $nextStep = $state['step'] + 1;

        if ($nextStep < count($definition['steps'])) {
            State::set($chatId, $flow, $nextStep, $data);
            self::askStep($api, $chatId, $flow, $nextStep);

            return;
        }

        if (isset($definition['confirm'])) {
            State::set($chatId, $flow, -1, $data);
            $api->sendHtml($chatId, self::fillTemplate((string) $definition['confirm'], $data) . "\n\nادامه می‌دهید؟", self::confirmKeyboard());

            return;
        }

        State::clear($chatId);
        self::execute($api, $chatId, $flow, $data);
    }

    /**
     * @return array{inline_keyboard: array<int, array<int, array<string, string>>>}
     */
    private static function confirmKeyboard(): array
    {
        return ['inline_keyboard' => [[self::btn('✅ تأیید', 'ok'), self::btn('❌ انصراف', 'no')]]];
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function execute(TelegramApi $api, int $chatId, string $flow, array $data): void
    {
        $api->sendChatAction($chatId);
        $method = 'run' . str_replace(' ', '', ucwords(str_replace('_', ' ', $flow)));

        try {
            if (!method_exists(self::class, $method)) {
                throw CpanelError::config("عملیات «{$flow}» پیاده‌سازی نشده است.");
            }
            $text = self::$method($data);
            AuditLog::record(self::$actor, (string) (ServerStore::activeId() ?? '-'), $flow, $data);
        } catch (Throwable $e) {
            AuditLog::record(self::$actor, (string) (ServerStore::activeId() ?? '-'), $flow, $data + ['error' => $e->getMessage()], false);
            $text = Render::error($e);
        }

        $api->sendHtml($chatId, $text, self::mainKeyboard());
    }

    // ------------------------------------------------------- اجرای عملیات‌ها

    /** @param array<string, mixed> $data */
    private static function runEmailAdd(array $data): string
    {
        CpanelService::addEmail(self::accountClient(), (string) $data['email'], (string) $data['password'], $data['quota'] ?? 0);

        return '✅ حساب ایمیل <code>' . Format::escapeHtml((string) $data['email']) . '</code> ساخته شد.';
    }

    /** @param array<string, mixed> $data */
    private static function runEmailPasswd(array $data): string
    {
        CpanelService::setEmailPassword(self::accountClient(), (string) $data['email'], (string) $data['password']);

        return '✅ رمز <code>' . Format::escapeHtml((string) $data['email']) . '</code> عوض شد.';
    }

    /** @param array<string, mixed> $data */
    private static function runEmailQuota(array $data): string
    {
        CpanelService::setEmailQuota(self::accountClient(), (string) $data['email'], $data['quota']);

        return '✅ سهمیه‌ی <code>' . Format::escapeHtml((string) $data['email']) . '</code> به‌روزرسانی شد.';
    }

    /** @param array<string, mixed> $data */
    private static function runEmailDel(array $data): string
    {
        CpanelService::deleteEmail(self::accountClient(), (string) $data['email']);

        return '🗑 حساب <code>' . Format::escapeHtml((string) $data['email']) . '</code> حذف شد.';
    }

    /** @param array<string, mixed> $data */
    private static function runDbAdd(array $data): string
    {
        CpanelService::createDatabase(self::accountClient(), (string) $data['name']);

        return '✅ دیتابیس <code>' . Format::escapeHtml((string) $data['name']) . '</code> ساخته شد.';
    }

    /** @param array<string, mixed> $data */
    private static function runDbDel(array $data): string
    {
        CpanelService::deleteDatabase(self::accountClient(), (string) $data['name']);

        return '🗑 دیتابیس <code>' . Format::escapeHtml((string) $data['name']) . '</code> حذف شد.';
    }

    /** @param array<string, mixed> $data */
    private static function runDbuserAdd(array $data): string
    {
        CpanelService::createDatabaseUser(self::accountClient(), (string) $data['name'], (string) $data['password']);

        return '✅ کاربر دیتابیس <code>' . Format::escapeHtml((string) $data['name']) . '</code> ساخته شد.';
    }

    /** @param array<string, mixed> $data */
    private static function runDbuserDel(array $data): string
    {
        CpanelService::deleteDatabaseUser(self::accountClient(), (string) $data['name']);

        return '🗑 کاربر دیتابیس <code>' . Format::escapeHtml((string) $data['name']) . '</code> حذف شد.';
    }

    /** @param array<string, mixed> $data */
    private static function runDbGrant(array $data): string
    {
        CpanelService::grantDatabaseUser(
            self::accountClient(),
            (string) $data['user'],
            (string) $data['database'],
            (string) ($data['privileges'] ?: 'ALL PRIVILEGES'),
        );

        return '✅ دسترسی <code>' . Format::escapeHtml((string) $data['user']) . '</code> روی <code>'
            . Format::escapeHtml((string) $data['database']) . '</code> تنظیم شد.';
    }

    /** @param array<string, mixed> $data */
    private static function runSubAdd(array $data): string
    {
        CpanelService::addSubdomain(
            self::accountClient(),
            (string) $data['subdomain'],
            (string) $data['rootdomain'],
            (string) ($data['dir'] ?? ''),
        );

        return '✅ ساب‌دامنه‌ی <code>' . Format::escapeHtml($data['subdomain'] . '.' . $data['rootdomain']) . '</code> ساخته شد.';
    }

    /** @param array<string, mixed> $data */
    private static function runSubDel(array $data): string
    {
        CpanelService::deleteSubdomain(self::accountClient(), (string) $data['domain']);

        return '🗑 ساب‌دامنه‌ی <code>' . Format::escapeHtml((string) $data['domain']) . '</code> حذف شد.';
    }

    /** @param array<string, mixed> $data */
    private static function runFtpAdd(array $data): string
    {
        CpanelService::addFtp(
            self::accountClient(),
            (string) $data['user'],
            (string) $data['password'],
            (string) ($data['dir'] ?? ''),
            $data['quota'] ?? 0,
        );

        return '✅ حساب FTP <code>' . Format::escapeHtml((string) $data['user']) . '</code> ساخته شد.';
    }

    /** @param array<string, mixed> $data */
    private static function runFtpDel(array $data): string
    {
        CpanelService::deleteFtp(self::accountClient(), (string) $data['user']);

        return '🗑 حساب FTP <code>' . Format::escapeHtml((string) $data['user']) . '</code> حذف شد.';
    }

    /** @param array<string, mixed> $data */
    private static function runCronAdd(array $data): string
    {
        $schedule = CpanelService::parseSchedule((string) $data['schedule']);
        if ($schedule === null) {
            throw CpanelError::config('زمان‌بندی باید دقیقاً ۵ فیلد کران باشد، مثل <code>*/5 * * * *</code>.');
        }
        CpanelService::addCron(self::accountClient(), $schedule, (string) $data['command']);

        return '✅ کران‌جاب اضافه شد: <code>' . Format::escapeHtml((string) $data['schedule']) . '</code>';
    }

    /** @param array<string, mixed> $data */
    private static function runCronDel(array $data): string
    {
        CpanelService::deleteCron(self::accountClient(), (string) $data['key']);

        return '🗑 کران‌جاب حذف شد.';
    }

    /** @param array<string, mixed> $data */
    private static function runDnsList(array $data): string
    {
        $zone = (string) $data['zone'];

        return Render::dns($zone, CpanelService::dnsZone(self::accountClient(), $zone));
    }

    /** @param array<string, mixed> $data */
    private static function runDnsAdd(array $data): string
    {
        $values = preg_split('/\s+/', trim((string) $data['value'])) ?: [];
        CpanelService::addDnsRecord(
            self::accountClient(),
            (string) $data['zone'],
            (string) $data['name'],
            (string) $data['type'],
            $values,
            (int) ($data['ttl'] ?: 14400),
        );

        return '✅ رکورد <b>' . Format::escapeHtml(strtoupper((string) $data['type'])) . '</b> برای <code>'
            . Format::escapeHtml((string) $data['name']) . '</code> اضافه شد.';
    }

    /** @param array<string, mixed> $data */
    private static function runDnsDel(array $data): string
    {
        CpanelService::deleteDnsRecord(self::accountClient(), (string) $data['zone'], (int) $data['line']);

        return '🗑 رکورد شماره‌ی ' . (int) $data['line'] . ' حذف شد.';
    }

    /** @param array<string, mixed> $data */
    private static function runPhpSet(array $data): string
    {
        CpanelService::setPhpVersion(self::accountClient(), (string) $data['vhost'], (string) $data['version']);

        return '✅ نسخه‌ی PHP دامنه‌ی <code>' . Format::escapeHtml((string) $data['vhost']) . '</code> شد <b>'
            . Format::escapeHtml((string) $data['version']) . '</b>.';
    }

    /** @param array<string, mixed> $data */
    private static function runBackupRun(array $data): string
    {
        CpanelService::fullBackup(self::accountClient(), (string) ($data['email'] ?? ''));

        return '✅ ساخت بکاپ کامل شروع شد؛ فایل در پوشه‌ی هوم حساب ساخته می‌شود.';
    }

    /** @param array<string, mixed> $data */
    private static function runWhmUser(array $data): string
    {
        $id = ServerStore::activeId();
        if ($id === null) {
            throw CpanelError::config('اول یک سرور اضافه کنید.');
        }
        ServerStore::setAsUser($id, (string) $data['user']);

        return '🎯 از این به بعد کارهای سطح حساب روی <code>' . Format::escapeHtml((string) $data['user']) . '</code> انجام می‌شود.';
    }

    /** @param array<string, mixed> $data */
    private static function runWhmSummary(array $data): string
    {
        return Render::whmAccount(CpanelService::whmAccountSummary(self::whmClient(), (string) $data['user']));
    }

    /** @param array<string, mixed> $data */
    private static function runWhmSuspend(array $data): string
    {
        CpanelService::whmSuspend(self::whmClient(), (string) $data['user'], (string) ($data['reason'] ?? ''));

        return '⛔️ حساب <code>' . Format::escapeHtml((string) $data['user']) . '</code> معلق شد.';
    }

    /** @param array<string, mixed> $data */
    private static function runWhmUnsuspend(array $data): string
    {
        CpanelService::whmUnsuspend(self::whmClient(), (string) $data['user']);

        return '✅ تعلیق حساب <code>' . Format::escapeHtml((string) $data['user']) . '</code> برداشته شد.';
    }

    /** @param array<string, mixed> $data */
    private static function runWhmPasswd(array $data): string
    {
        CpanelService::whmChangePassword(self::whmClient(), (string) $data['user'], (string) $data['password']);

        return '✅ رمز حساب <code>' . Format::escapeHtml((string) $data['user']) . '</code> عوض شد.';
    }

    /** @param array<string, mixed> $data */
    private static function runWhmLogin(array $data): string
    {
        $url = CpanelService::whmLoginUrl(self::whmClient(), (string) $data['user']);

        return "🔐 لینک ورود یک‌بارمصرف (چند دقیقه اعتبار دارد، با کسی به اشتراک نگذارید):\n" . Format::escapeHtml($url);
    }

    /** @param array<string, mixed> $data */
    private static function runWhmCreate(array $data): string
    {
        CpanelService::whmCreateAccount(
            self::whmClient(),
            (string) $data['username'],
            (string) $data['domain'],
            (string) $data['password'],
            (string) ($data['plan'] ?? ''),
        );

        return '✅ حساب <code>' . Format::escapeHtml((string) $data['username']) . '</code> ساخته شد.';
    }

    /** @param array<string, mixed> $data */
    private static function runWhmTerminate(array $data): string
    {
        if (trim((string) $data['user']) !== trim((string) ($data['confirm_user'] ?? ''))) {
            throw CpanelError::config('نام کاربری تأیید با نام کاربری اصلی یکی نبود؛ حساب حذف نشد.');
        }
        CpanelService::whmTerminateAccount(self::whmClient(), (string) $data['user']);

        return '💀 حساب <code>' . Format::escapeHtml((string) $data['user']) . '</code> کاملاً حذف شد.';
    }

    /** @param array<string, mixed> $data */
    private static function runSrvAdd(array $data): string
    {
        $type = strtolower(trim((string) $data['type'])) === CpanelClient::TYPE_WHM
            ? CpanelClient::TYPE_WHM
            : CpanelClient::TYPE_CPANEL;
        $server = [
            'label' => (string) $data['label'],
            'type' => $type,
            'host' => ServerStore::normalizeHost((string) $data['host']),
            'port' => (int) ($data['port'] ?: ($type === CpanelClient::TYPE_WHM ? 2087 : 2083)),
            'user' => (string) $data['user'],
            'token' => (string) $data['token'],
            'ssl_verify' => true,
        ];

        // قبل از ذخیره تست می‌کنیم تا توکن غلط بی‌سروصدا ذخیره نشه.
        $ping = ServerStore::client(null, $server)->ping();
        $id = ServerStore::add($server);
        ServerStore::setActive($id);

        return Render::ping($ping) . "\n\n✅ سرور ذخیره و فعال شد. 🆔 <code>" . Format::escapeHtml($id) . '</code>';
    }

    /** @param array<string, mixed> $data */
    private static function runSrvUse(array $data): string
    {
        ServerStore::setActive((string) $data['id']);
        $server = ServerStore::active();

        return '✅ سرور فعال شد: <b>' . Format::escapeHtml((string) ($server['label'] ?? $data['id'])) . '</b>';
    }

    /** @param array<string, mixed> $data */
    private static function runSrvDel(array $data): string
    {
        $id = (string) $data['id'];
        $server = ServerStore::get($id);
        if ($server === null) {
            throw CpanelError::config("سروری با شناسه‌ی «{$id}» پیدا نشد.");
        }
        if (($server['source'] ?? '') === 'env') {
            throw CpanelError::config('این سرور در فایل <code>.env</code> تعریف شده؛ برای حذفش مقادیر مربوطه را از .env بردارید.');
        }
        ServerStore::delete($id);

        return '🗑 سرور <code>' . Format::escapeHtml($id) . '</code> حذف شد.';
    }

    // ------------------------------------------------------------- کمکی‌ها

    private static function clientFor(string $flow): ?CpanelClient
    {
        return match (self::FLOWS[$flow]['scope'] ?? 'account') {
            'whm' => self::whmClient(),
            'local' => null,
            default => self::accountClient(),
        };
    }

    /**
     * کلاینتی که می‌تونه دستورهای سطح حساب (UAPI) بزنه.
     */
    private static function accountClient(): CpanelClient
    {
        $client = ServerStore::client();
        if ($client->isWhm() && $client->targetUser() === '') {
            throw CpanelError::config(
                'سرور فعال از نوع WHM است؛ اول از منوی WHM گزینه‌ی «🎯 انتخاب کاربر مقصد» را بزنید تا مشخص شود کارها روی کدام حساب انجام شود.'
            );
        }

        return $client;
    }

    private static function whmClient(): CpanelClient
    {
        $client = ServerStore::client();
        if (!$client->isWhm()) {
            throw CpanelError::config(
                'سرور فعال از نوع cPanel است و به WHM دسترسی ندارد؛ برای کارهای سطح سرور یک سرور از نوع <code>whm</code> اضافه کنید.'
            );
        }

        return $client;
    }

    /**
     * @param array<int, int> $adminIds
     */
    private static function authorize(TelegramApi $api, int $chatId, mixed $fromId, array $adminIds): bool
    {
        if ($adminIds === []) {
            // بدون ADMIN_ID این ربات به هرکسی اجازه‌ی مدیریت سرور می‌داد؛ پس
            // به‌جای پاسخ دادن، هشدار می‌ده.
            $api->sendMessage(
                $chatId,
                "⛔️ ربات هنوز قفل نشده: مقدار ADMIN_ID در فایل .env تنظیم نشده است.\n"
                . 'تا وقتی آیدی عددی ادمین را تنظیم نکنید، هیچ دستوری اجرا نمی‌شود.',
            );

            return false;
        }

        return is_numeric($fromId) && in_array((int) $fromId, $adminIds, true);
    }

    /**
     * @param array<string, mixed> $data
     */
    private static function fillTemplate(string $template, array $data): string
    {
        $replacements = [];
        foreach ($data as $key => $value) {
            $replacements['{' . $key . '}'] = Format::escapeHtml(is_scalar($value) ? (string) $value : '');
        }

        return strtr($template, $replacements);
    }

    private static function generatePassword(int $length = 20): string
    {
        // بدون کاراکترهای شبیه‌به‌هم (0/O، 1/l) و بدون نویسه‌هایی که توی
        // خط فرمان دردسر می‌سازن.
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#%^*_+-=';
        $max = strlen($alphabet) - 1;
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, $max)];
        }

        return $password;
    }

    private static function welcome(): string
    {
        return "🛠 <b>مدیریت cPanel از تلگرام</b>\n\n"
            . "این ربات از طریق <b>API رسمی cPanel/WHM</b> (با API Token، بدون ذخیره‌ی رمز حساب) به سرور وصل می‌شود و کارهای روزمره‌ی مدیریت هاست را انجام می‌دهد:\n\n"
            . "📊 خلاصه‌ی مصرف دیسک/پهنای‌باند/اینود\n"
            . "✉️ ساخت، حذف، تغییر رمز و سهمیه‌ی ایمیل\n"
            . "🗄 ساخت/حذف دیتابیس و کاربر MySQL و دادن دسترسی\n"
            . "🌐 دامنه‌ها و ساب‌دامنه‌ها، 🧭 رکوردهای DNS، 🔒 گواهی‌های SSL\n"
            . "📁 حساب‌های FTP، ⏰ کران‌جاب‌ها، 🐘 نسخه‌ی PHP هر دامنه، 💾 بکاپ کامل\n"
            . "🖥 و روی WHM: لیست حساب‌ها، ساخت/تعلیق/حذف حساب، تغییر رمز، لینک ورود یک‌بارمصرف و وضعیت سرویس‌ها\n\n"
            . "<b>دستورهای سریع:</b> /panel /summary /emails /db /domains /ftp /cron /dns /ssl /php /whm /servers /ping /log /cancel\n\n"
            . 'می‌توانید چند سرور تعریف کنید و با «⚙️ سرورها» بینشان جابه‌جا شوید.';
    }
}
