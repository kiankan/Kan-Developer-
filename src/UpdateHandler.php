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
    /** گزینه‌های آماده‌ی سهمیه (مگابایت؛ صفر یعنی نامحدود). */
    private const QUOTA_CHOICES = [
        ['♾ نامحدود', '0'],
        ['250 مگابایت', '250'],
        ['500 مگابایت', '500'],
        ['1 گیگابایت', '1024'],
        ['5 گیگابایت', '5120'],
    ];

    private const FLOWS = [
        'email_add' => [
            'title' => '✉️ افزودن حساب ایمیل',
            'scope' => 'account',
            'steps' => [
                ['key' => 'domain', 'prompt' => 'ایمیل روی کدام دامنه ساخته شود؟', 'picker' => 'domains'],
                ['key' => 'local', 'prompt' => 'بخش قبل از @ را بفرستید (مثل <code>info</code>):'],
                ['key' => 'password', 'prompt' => 'رمز عبور را بفرستید (یا <code>/gen</code> برای ساخت رمز قوی):', 'secret' => true],
                ['key' => 'quota', 'prompt' => 'سهمیه‌ی صندوق ایمیل:', 'optional' => true, 'default' => '0', 'choices' => self::QUOTA_CHOICES],
            ],
        ],
        'email_passwd' => [
            'title' => '🔑 تغییر رمز ایمیل',
            'scope' => 'account',
            'steps' => [
                ['key' => 'email', 'prompt' => 'کدام حساب ایمیل؟', 'picker' => 'emails'],
                ['key' => 'password', 'prompt' => 'رمز جدید را بفرستید (یا <code>/gen</code>):', 'secret' => true],
            ],
        ],
        'email_quota' => [
            'title' => '📦 تغییر سهمیه‌ی ایمیل',
            'scope' => 'account',
            'steps' => [
                ['key' => 'email', 'prompt' => 'کدام حساب ایمیل؟', 'picker' => 'emails'],
                ['key' => 'quota', 'prompt' => 'سهمیه‌ی جدید:', 'choices' => self::QUOTA_CHOICES],
            ],
        ],
        'email_del' => [
            'title' => '🗑 حذف حساب ایمیل',
            'scope' => 'account',
            'confirm' => '⚠️ حساب <code>{email}</code> و همه‌ی پیام‌هایش برای همیشه حذف می‌شود.',
            'steps' => [
                ['key' => 'email', 'prompt' => 'کدام حساب ایمیل حذف شود؟', 'picker' => 'emails'],
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
                ['key' => 'name', 'prompt' => 'کدام دیتابیس حذف شود؟', 'picker' => 'databases'],
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
                ['key' => 'name', 'prompt' => 'کدام کاربر دیتابیس حذف شود؟', 'picker' => 'dbusers'],
            ],
        ],
        'db_grant' => [
            'title' => '🔗 دادن دسترسی کاربر به دیتابیس',
            'scope' => 'account',
            'steps' => [
                ['key' => 'user', 'prompt' => 'کدام کاربر دیتابیس؟', 'picker' => 'dbusers'],
                ['key' => 'database', 'prompt' => 'روی کدام دیتابیس؟', 'picker' => 'databases'],
                ['key' => 'privileges', 'prompt' => 'چه سطحی از دسترسی؟', 'optional' => true, 'default' => 'ALL PRIVILEGES', 'choices' => [
                    ['🔓 همه‌ی دسترسی‌ها', 'ALL PRIVILEGES'],
                    ['📖 فقط خواندن (SELECT)', 'SELECT'],
                    ['✏️ خواندن و نوشتن', 'SELECT,INSERT,UPDATE,DELETE'],
                ]],
            ],
        ],
        'sub_add' => [
            'title' => '➕ افزودن ساب‌دامنه',
            'scope' => 'account',
            'steps' => [
                ['key' => 'rootdomain', 'prompt' => 'روی کدام دامنه؟', 'picker' => 'zones'],
                ['key' => 'subdomain', 'prompt' => 'فقط بخش ساب‌دامنه را بفرستید (مثل <code>shop</code>):'],
                ['key' => 'dir', 'prompt' => 'مسیر پوشه (اختیاری، خالی = پیش‌فرض cPanel):', 'optional' => true, 'default' => ''],
            ],
        ],
        'sub_del' => [
            'title' => '🗑 حذف ساب‌دامنه',
            'scope' => 'account',
            'confirm' => '⚠️ ساب‌دامنه‌ی <code>{domain}</code> حذف می‌شود (فایل‌های پوشه‌اش باقی می‌مانند).',
            'steps' => [
                ['key' => 'domain', 'prompt' => 'کدام ساب‌دامنه حذف شود؟', 'picker' => 'subdomains'],
            ],
        ],
        'ftp_add' => [
            'title' => '📁 افزودن حساب FTP',
            'scope' => 'account',
            'steps' => [
                ['key' => 'user', 'prompt' => 'نام کاربری FTP را بفرستید (مثل <code>deploy@example.com</code> یا فقط <code>deploy</code>):'],
                ['key' => 'password', 'prompt' => 'رمز عبور را بفرستید (یا <code>/gen</code>):', 'secret' => true],
                ['key' => 'dir', 'prompt' => 'مسیر پوشه (اختیاری):', 'optional' => true, 'default' => ''],
                ['key' => 'quota', 'prompt' => 'سهمیه‌ی فضا:', 'optional' => true, 'default' => '0', 'choices' => self::QUOTA_CHOICES],
            ],
        ],
        'ftp_del' => [
            'title' => '🗑 حذف حساب FTP',
            'scope' => 'account',
            'confirm' => '⚠️ حساب FTP <code>{user}</code> حذف می‌شود (فایل‌ها پاک نمی‌شوند).',
            'steps' => [
                ['key' => 'user', 'prompt' => 'کدام حساب FTP حذف شود؟', 'picker' => 'ftp'],
            ],
        ],
        'cron_add' => [
            'title' => '⏰ افزودن کران‌جاب',
            'scope' => 'account',
            'steps' => [
                ['key' => 'schedule', 'prompt' => "هر چند وقت یک‌بار اجرا شود؟\n\nمی‌توانید زمان‌بندی دلخواه را هم با ۵ فیلد استاندارد کران بفرستید، مثل <code>*/10 * * * *</code>.", 'choices' => [
                    ['هر دقیقه', '* * * * *'],
                    ['هر ۵ دقیقه', '*/5 * * * *'],
                    ['هر ۱۵ دقیقه', '*/15 * * * *'],
                    ['هر ساعت', '0 * * * *'],
                    ['هر ۶ ساعت', '0 */6 * * *'],
                    ['هر شب ساعت ۰۰:۰۰', '0 0 * * *'],
                    ['هر هفته (شنبه ۰۰:۰۰)', '0 0 * * 6'],
                ]],
                ['key' => 'command', 'prompt' => 'دستوری که باید اجرا شود را بفرستید:'],
            ],
        ],
        'cron_del' => [
            'title' => '🗑 حذف کران‌جاب',
            'scope' => 'account',
            'confirm' => '⚠️ کران‌جاب انتخاب‌شده حذف می‌شود.',
            'steps' => [
                ['key' => 'key', 'prompt' => 'کدام کران‌جاب حذف شود؟', 'picker' => 'cron'],
            ],
        ],
        'dns_list' => [
            'title' => '🧭 نمایش رکوردهای DNS',
            'scope' => 'account',
            'steps' => [
                ['key' => 'zone', 'prompt' => 'رکوردهای کدام دامنه؟', 'picker' => 'zones'],
            ],
        ],
        'dns_add' => [
            'title' => '➕ افزودن رکورد DNS',
            'scope' => 'account',
            'steps' => [
                ['key' => 'zone', 'prompt' => 'روی کدام دامنه؟', 'picker' => 'zones'],
                ['key' => 'type', 'prompt' => 'نوع رکورد؟', 'picker' => 'record_types'],
                ['key' => 'name', 'prompt' => "نام رکورد را بفرستید (مثل <code>www</code> یا <code>@</code> برای خود دامنه):"],
                ['key' => 'value', 'prompt' => "مقدار رکورد را بفرستید.\n\nبرای MX اول اولویت و بعد میزبان، مثل: <code>10 mail.example.com.</code>"],
                ['key' => 'ttl', 'prompt' => 'TTL چقدر باشد؟', 'optional' => true, 'default' => '14400', 'choices' => [
                    ['۵ دقیقه (300)', '300'],
                    ['۱ ساعت (3600)', '3600'],
                    ['۴ ساعت (14400)', '14400'],
                    ['۲۴ ساعت (86400)', '86400'],
                ]],
            ],
        ],
        'dns_del' => [
            'title' => '🗑 حذف رکورد DNS',
            'scope' => 'account',
            'confirm' => '⚠️ رکورد شماره‌ی <code>{line}</code> از زون <code>{zone}</code> حذف می‌شود.',
            'steps' => [
                ['key' => 'zone', 'prompt' => 'از کدام دامنه؟', 'picker' => 'zones'],
                ['key' => 'line', 'prompt' => 'کدام رکورد حذف شود؟', 'picker' => 'dns_records'],
            ],
        ],
        'php_set' => [
            'title' => '🐘 تغییر نسخه‌ی PHP دامنه',
            'scope' => 'account',
            'steps' => [
                ['key' => 'vhost', 'prompt' => 'کدام دامنه؟', 'picker' => 'vhosts'],
                ['key' => 'version', 'prompt' => 'کدام نسخه‌ی PHP؟', 'picker' => 'php_versions'],
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
                ['key' => 'user', 'prompt' => 'کدام حساب را مدیریت می‌کنید؟', 'picker' => 'whm_accounts'],
            ],
        ],
        'whm_summary' => [
            'title' => '🔍 جزئیات حساب',
            'scope' => 'whm',
            'steps' => [
                ['key' => 'user', 'prompt' => 'کدام حساب؟', 'picker' => 'whm_accounts'],
            ],
        ],
        'whm_suspend' => [
            'title' => '⛔️ تعلیق حساب',
            'scope' => 'whm',
            'confirm' => '⚠️ حساب <code>{user}</code> معلق می‌شود؛ سایت‌ها و ایمیل‌هایش از کار می‌افتند.',
            'steps' => [
                ['key' => 'user', 'prompt' => 'کدام حساب معلق شود؟', 'picker' => 'whm_accounts'],
                ['key' => 'reason', 'prompt' => 'دلیل تعلیق (اختیاری):', 'optional' => true, 'default' => ''],
            ],
        ],
        'whm_unsuspend' => [
            'title' => '✅ رفع تعلیق حساب',
            'scope' => 'whm',
            'steps' => [
                ['key' => 'user', 'prompt' => 'کدام حساب از تعلیق دربیاید؟', 'picker' => 'whm_accounts'],
            ],
        ],
        'whm_passwd' => [
            'title' => '🔑 تغییر رمز حساب',
            'scope' => 'whm',
            'confirm' => '⚠️ رمز حساب <code>{user}</code> عوض می‌شود (رمز دیتابیس‌ها و FTP همان حساب هم می‌تواند تغییر کند).',
            'steps' => [
                ['key' => 'user', 'prompt' => 'کدام حساب؟', 'picker' => 'whm_accounts'],
                ['key' => 'password', 'prompt' => 'رمز جدید را بفرستید (یا <code>/gen</code>):', 'secret' => true],
            ],
        ],
        'whm_login' => [
            'title' => '🔐 لینک ورود یک‌بارمصرف',
            'scope' => 'whm',
            'steps' => [
                ['key' => 'user', 'prompt' => 'برای کدام حساب لینک ساخته شود؟', 'picker' => 'whm_accounts'],
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
                ['key' => 'plan', 'prompt' => 'کدام پلن؟ (اختیاری، خالی = پیش‌فرض)', 'optional' => true, 'default' => '', 'picker' => 'whm_plans'],
            ],
        ],
        'whm_terminate' => [
            'title' => '💀 حذف کامل حساب',
            'scope' => 'whm',
            'confirm' => '⚠️ حساب <code>{user}</code> با تمام سایت‌ها، ایمیل‌ها و دیتابیس‌هایش برای همیشه حذف می‌شود. این کار برگشت‌پذیر نیست.',
            'steps' => [
                ['key' => 'user', 'prompt' => 'کدام حساب حذف شود؟', 'picker' => 'whm_accounts'],
                ['key' => 'confirm_user', 'prompt' => 'برای اطمینان، دقیقاً همان نام کاربری را تایپ کنید:'],
            ],
        ],
        'selfhost_cron' => [
            'title' => '🧩 اجرای ربات با کران روی همین هاست',
            'scope' => 'account',
            'confirm' => '⚠️ یک کران‌جاب هر دقیقه‌ای روی این حساب ساخته می‌شود که <code>{path}/poll.php</code> را اجرا کند.',
            'steps' => [
                ['key' => 'php_bin', 'prompt' => "مسیر PHP روی آن هاست کدام است؟", 'choices' => [
                    ['/usr/local/bin/php (رایج در cPanel)', '/usr/local/bin/php'],
                    ['/usr/bin/php', '/usr/bin/php'],
                    ['/usr/local/bin/ea-php82', '/usr/local/bin/ea-php82'],
                    ['/usr/local/bin/ea-php83', '/usr/local/bin/ea-php83'],
                ]],
                ['key' => 'path', 'prompt' => "پوشه‌ی پروژه روی آن هاست کجاست؟ (مثل <code>/home/user/teamkan</code>)"],
            ],
        ],
        'admin_add' => [
            'title' => '👮 افزودن ادمین',
            'scope' => 'local',
            'steps' => [
                ['key' => 'id', 'prompt' => "آیدی عددی تلگرام فرد را بفرستید.\n\n💡 هر کسی می‌تواند آیدی خودش را از ربات @userinfobot بگیرد."],
            ],
        ],
        'admin_del' => [
            'title' => '🗑 حذف ادمین',
            'scope' => 'local',
            'confirm' => '⚠️ دسترسی ادمین <code>{id}</code> به این ربات قطع می‌شود.',
            'steps' => [
                ['key' => 'id', 'prompt' => 'کدام ادمین حذف شود؟', 'picker' => 'admins'],
            ],
        ],
        'srv_add' => [
            'title' => '➕ افزودن سرور',
            'scope' => 'local',
            'steps' => [
                ['key' => 'label', 'prompt' => 'یک اسم دلخواه برای این سرور بفرستید (مثل <code>هاست اصلی</code>):'],
                ['key' => 'type', 'prompt' => 'توکن از کجاست؟', 'choices' => [
                    ['🏠 cPanel (یک حساب هاست)', 'cpanel'],
                    ['🖥 WHM (کل سرور / ریسلر)', 'whm'],
                ]],
                ['key' => 'host', 'prompt' => "آدرس سرور را بفرستید.\n\nمی‌توانید همان چیزی را بفرستید که در مرورگر می‌بینید — پورتش هم اگر داخل آدرس باشد خودکار برداشته می‌شود:\n<code>server.example.com</code>\n<code>server.example.com:2083</code>\n<code>https://cp.example.com:2096/</code>"],
                ['key' => 'port', 'prompt' => "پورت پنل؟ اگر داخل آدرس نوشتید، «همان داخل آدرس» را بزنید.", 'optional' => true, 'default' => '', 'choices' => [
                    ['↩️ همان داخل آدرس / پیش‌فرض', ''],
                    ['2083 (cPanel)', '2083'],
                    ['2087 (WHM)', '2087'],
                    ['2096 (وبمیل/پروکسی)', '2096'],
                    ['443 (پشت ریورس‌پراکسی)', '443'],
                ]],
                ['key' => 'user', 'prompt' => 'نام کاربری (برای WHM معمولاً <code>root</code>):'],
                ['key' => 'token', 'prompt' => "API Token را بفرستید.\n\ncPanel: Security → Manage API Tokens\nWHM: Development → Manage API Tokens", 'secret' => true],
            ],
        ],
        'srv_use' => [
            'title' => '🔀 انتخاب سرور فعال',
            'scope' => 'local',
            'steps' => [
                ['key' => 'id', 'prompt' => 'کدام سرور فعال شود؟', 'picker' => 'servers'],
            ],
        ],
        'srv_del' => [
            'title' => '🗑 حذف سرور',
            'scope' => 'local',
            'confirm' => '⚠️ سرور <code>{id}</code> از فهرست حذف می‌شود (روی خود سرور چیزی تغییر نمی‌کند).',
            'steps' => [
                ['key' => 'id', 'prompt' => 'کدام سرور از فهرست حذف شود؟', 'picker' => 'removable_servers'],
            ],
        ],
    ];

    public static function handle(TelegramApi $api, array $message): void
    {
        $chatId = $message['chat']['id'] ?? null;
        $fromId = $message['from']['id'] ?? null;
        $messageId = $message['message_id'] ?? null;
        $text = trim((string) ($message['text'] ?? ''));

        if ($chatId === null || $text === '') {
            return;
        }

        // ربات تازه‌نصب: تا وقتی کسی با کد نصب تحویلش نگرفته، فقط همان کد را
        // قبول می‌کند.
        if (!Admins::isClaimed()) {
            self::handleClaim($api, (int) $chatId, $fromId, $text, is_int($messageId) ? $messageId : null);

            return;
        }

        if (!is_numeric($fromId) || !Admins::isAdmin((int) $fromId)) {
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

    public static function handleCallback(TelegramApi $api, array $callbackQuery): void
    {
        $callbackId = $callbackQuery['id'] ?? null;
        $data = (string) ($callbackQuery['data'] ?? '');
        $fromId = $callbackQuery['from']['id'] ?? null;
        $chatId = $callbackQuery['message']['chat']['id'] ?? null;

        if ($callbackId !== null) {
            $api->answerCallbackQuery((string) $callbackId);
        }
        if ($chatId === null || !is_numeric($fromId) || !Admins::isAdmin((int) $fromId)) {
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

        if ($data === 'noop') {
            return;
        }

        // انتخاب یکی از دکمه‌های یک مرحله، صفحه‌ی بعدی فهرست، یا رد کردن مرحله
        if (str_starts_with($data, 'c:')) {
            self::chooseOption($api, $chatId, (int) substr($data, 2));

            return;
        }

        if (str_starts_with($data, 'pg:')) {
            self::changePage($api, $chatId, (int) substr($data, 3));

            return;
        }

        if ($data === 'skip') {
            $state = State::get($chatId);
            if ($state === null || $state['step'] < 0) {
                $api->sendHtml($chatId, 'چیزی برای رد کردن در جریان نیست.', self::mainKeyboard());

                return;
            }
            self::continueFlow($api, $chatId, $state, '/skip', null);

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
            case '/admins':
                self::sendMenu($api, $chatId, 'adm');

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
            'adm' => ['👮 <b>ادمین‌های ربات</b>', [
                [self::btn('📋 لیست ادمین‌ها', 'a:admins')],
                [self::btn('➕ افزودن ادمین', 'f:admin_add'), self::btn('🗑 حذف ادمین', 'f:admin_del')],
                [self::btn('⬅️ بازگشت', 'menu')],
            ]],
            'srv' => ['🖥 <b>سرورها</b>', array_merge(
                [[self::btn('📋 لیست سرورها', 'a:servers'), self::btn('🔌 تست اتصال', 'a:ping')]],
                self::serverPickerRows(),
                [
                    [self::btn('➕ افزودن سرور', 'f:srv_add'), self::btn('🗑 حذف سرور', 'f:srv_del')],
                    [self::btn('🧩 اجرای ربات با کران روی هاست', 'f:selfhost_cron')],
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
            [self::btn('👮 ادمین‌ها', 'm:adm'), self::btn('🧾 لاگ عملیات', 'a:log')],
            [self::btn('❓ راهنما', 'a:help')],
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

        if ($action === 'admins') {
            $lines = ['👮 <b>ادمین‌های ربات</b>', ''];
            foreach (Admins::fromEnv() as $adminId) {
                $lines[] = '• <code>' . $adminId . '</code> — تعریف‌شده در <code>.env</code> (از ربات حذف نمی‌شود)';
            }
            foreach (Admins::fromFile() as $adminId) {
                $lines[] = '• <code>' . $adminId . '</code>';
            }
            $api->sendHtml($chatId, implode("\n", $lines), self::mainKeyboard());

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

        self::askStep($api, $chatId, $flow, 0, []);
    }

    /**
     * پرسیدن یک مرحله. هرجا بشود، به‌جای «تایپ کن»، دکمه‌ی شیشه‌ای می‌سازیم:
     * گزینه‌های ثابت (choices) یا فهرست زنده‌ای که همان لحظه از خود سرور
     * گرفته می‌شود (picker) — مثل فهرست ایمیل‌ها برای حذف.
     *
     * @param array<string, mixed> $data
     */
    private static function askStep(TelegramApi $api, int $chatId, string $flow, int $step, array $data, int $page = 0): void
    {
        $definition = self::FLOWS[$flow];
        $stepDefinition = $definition['steps'][$step];
        $optional = ($stepDefinition['optional'] ?? false) === true;

        [$options, $optionsError] = self::stepOptions($stepDefinition, $data);

        $text = '<b>' . $definition['title'] . '</b>  (مرحله ' . ($step + 1) . ' از ' . count($definition['steps']) . ")\n\n"
            . $stepDefinition['prompt'];
        if ($optionsError !== null) {
            $text .= "\n\n⚠️ فهرست آماده را نتوانستم بگیرم، مقدار را دستی بفرستید:\n" . Format::escapeHtml($optionsError);
        } elseif ($options !== []) {
            $text .= "\n\nیکی از دکمه‌های زیر را بزنید، یا مقدار را دستی بفرستید.";
        }
        $text .= $optional ? "\n\nرد شدن: <code>/skip</code> — لغو: <code>/cancel</code>" : "\n\nلغو: <code>/cancel</code>";

        // گزینه‌ها را در خود وضعیت نگه می‌داریم تا callback فقط شماره بفرستد
        // (تلگرام برای callback_data فقط ۶۴ بایت می‌دهد و ایمیل/نام دیتابیس
        // راحت از آن بلندتر می‌شود).
        $data['__options'] = $options;
        $data['__page'] = $page;
        State::set($chatId, $flow, $step, $data);

        $api->sendHtml($chatId, $text, self::optionsKeyboard($options, $page, $optional));
    }

    /**
     * @param array<int, array{label: string, value: string}> $options
     *
     * @return array{inline_keyboard: array<int, array<int, array<string, string>>>}|null
     */
    private static function optionsKeyboard(array $options, int $page, bool $optional): ?array
    {
        $rows = [];
        $perPage = 8;
        $pages = max(1, (int) ceil(count($options) / $perPage));
        $page = max(0, min($page, $pages - 1));

        foreach (array_slice($options, $page * $perPage, $perPage) as $index => $option) {
            $rows[] = [self::btn(self::shorten($option['label']), 'c:' . ($page * $perPage + $index))];
        }

        if ($pages > 1) {
            $nav = [];
            if ($page > 0) {
                $nav[] = self::btn('◀️ قبلی', 'pg:' . ($page - 1));
            }
            $nav[] = self::btn('صفحه ' . ($page + 1) . ' از ' . $pages, 'noop');
            if ($page < $pages - 1) {
                $nav[] = self::btn('بعدی ▶️', 'pg:' . ($page + 1));
            }
            $rows[] = $nav;
        }

        $footer = [];
        if ($optional) {
            $footer[] = self::btn('⏭ رد کردن', 'skip');
        }
        $footer[] = self::btn('❌ لغو', 'no');
        $rows[] = $footer;

        return ['inline_keyboard' => $rows];
    }

    /**
     * گزینه‌های یک مرحله: یا ثابت‌اند، یا زنده از سرور گرفته می‌شوند.
     *
     * @param array<string, mixed> $stepDefinition
     * @param array<string, mixed> $data
     *
     * @return array{0: array<int, array{label: string, value: string}>, 1: string|null}
     */
    private static function stepOptions(array $stepDefinition, array $data): array
    {
        if (isset($stepDefinition['choices'])) {
            $options = [];
            foreach ($stepDefinition['choices'] as $choice) {
                $options[] = ['label' => (string) $choice[0], 'value' => (string) $choice[1]];
            }

            return [$options, null];
        }

        if (!isset($stepDefinition['picker'])) {
            return [[], null];
        }

        try {
            return [self::pickerOptions((string) $stepDefinition['picker'], $data), null];
        } catch (Throwable $e) {
            return [[], $e->getMessage()];
        }
    }

    /**
     * فهرست‌های زنده‌ای که از خود سرور می‌آیند.
     *
     * @param array<string, mixed> $data
     *
     * @return array<int, array{label: string, value: string}>
     */
    private static function pickerOptions(string $picker, array $data): array
    {
        $simple = static function (array $values): array {
            $out = [];
            foreach ($values as $value) {
                $out[] = ['label' => (string) $value, 'value' => (string) $value];
            }

            return $out;
        };

        switch ($picker) {
            case 'emails':
                return $simple(array_column(CpanelService::emails(self::accountClient()), 'email'));

            case 'databases':
                return $simple(array_column(CpanelService::databases(self::accountClient()), 'name'));

            case 'dbusers':
                return $simple(CpanelService::databaseUsers(self::accountClient()));

            case 'ftp':
                return $simple(array_column(CpanelService::ftpAccounts(self::accountClient()), 'user'));

            case 'subdomains':
                return $simple(CpanelService::domains(self::accountClient())['sub_domains']);

            case 'domains':
                $domains = CpanelService::domains(self::accountClient());

                return $simple(array_values(array_filter(array_merge(
                    [$domains['main_domain']],
                    $domains['addon_domains'],
                    $domains['sub_domains'],
                ))));

            case 'zones':
                $domains = CpanelService::domains(self::accountClient());

                return $simple(array_values(array_filter(array_merge(
                    [$domains['main_domain']],
                    $domains['addon_domains'],
                ))));

            case 'vhosts':
                return $simple(array_column(CpanelService::phpVersions(self::accountClient()), 'vhost'));

            case 'php_versions':
                $versions = CpanelService::installedPhpVersions(self::accountClient());
                $versions[] = 'inherit';

                return $simple($versions);

            case 'cron':
                $options = [];
                foreach (CpanelService::cronJobs(self::accountClient()) as $job) {
                    if ($job['key'] === '') {
                        continue;
                    }
                    $options[] = ['label' => $job['schedule'] . ' — ' . $job['command'], 'value' => $job['key']];
                }

                return $options;

            case 'dns_records':
                $zone = (string) ($data['zone'] ?? '');
                if ($zone === '') {
                    return [];
                }
                $options = [];
                foreach (CpanelService::dnsZone(self::accountClient(), $zone)['records'] as $record) {
                    if (in_array($record['type'], ['SOA', 'NS'], true)) {
                        // حذف SOA/NS زون را خراب می‌کند؛ اصلاً پیشنهادشان نمی‌دهیم.
                        continue;
                    }
                    $options[] = [
                        'label' => $record['type'] . ' ' . $record['name'] . ' → ' . implode(' ', $record['data']),
                        'value' => (string) $record['line'],
                    ];
                }

                return $options;

            case 'record_types':
                return $simple(['A', 'AAAA', 'CNAME', 'TXT', 'MX', 'NS']);

            case 'whm_accounts':
                return $simple(array_column(CpanelService::whmAccounts(self::whmClient()), 'user'));

            case 'whm_plans':
                return $simple(CpanelService::whmPackages(self::whmClient()));

            case 'admins':
                $options = [];
                foreach (Admins::fromFile() as $adminId) {
                    if ($adminId === self::currentAdminId()) {
                        // خود آدم را برای حذف پیشنهاد نمی‌دهیم تا با یک اشتباه
                        // دسترسی خودش را قطع نکند.
                        continue;
                    }
                    $options[] = ['label' => (string) $adminId, 'value' => (string) $adminId];
                }

                return $options;

            case 'servers':
                $options = [];
                foreach (ServerStore::all() as $id => $server) {
                    $options[] = ['label' => $server['label'] . ' (' . $id . ')', 'value' => (string) $id];
                }

                return $options;

            case 'removable_servers':
                $options = [];
                foreach (ServerStore::all() as $id => $server) {
                    if (($server['source'] ?? '') === 'env') {
                        continue;
                    }
                    $options[] = ['label' => $server['label'] . ' (' . $id . ')', 'value' => (string) $id];
                }

                return $options;

            default:
                return [];
        }
    }

    private static function shorten(string $text, int $limit = 48): string
    {
        $text = trim(preg_replace('/\s+/', ' ', $text) ?? $text);

        return mb_strlen($text, 'UTF-8') > $limit ? mb_substr($text, 0, $limit - 1, 'UTF-8') . '…' : $text;
    }

    /**
     * انتخاب یک گزینه از دکمه‌ها — دقیقاً مثل این است که کاربر همان مقدار را
     * تایپ کرده باشد.
     */
    private static function chooseOption(TelegramApi $api, int $chatId, int $index): void
    {
        $state = State::get($chatId);
        if ($state === null || $state['step'] < 0) {
            $api->sendHtml($chatId, 'این دکمه دیگر معتبر نیست؛ از منو دوباره شروع کنید.', self::mainKeyboard());

            return;
        }

        $options = $state['data']['__options'] ?? [];
        $option = is_array($options) ? ($options[$index] ?? null) : null;
        if (!is_array($option)) {
            $api->sendHtml($chatId, 'این گزینه دیگر در دسترس نیست؛ مقدار را دستی بفرستید.');

            return;
        }

        self::submitStep($api, $chatId, $state, (string) $option['value'], null);
    }

    private static function changePage(TelegramApi $api, int $chatId, int $page): void
    {
        $state = State::get($chatId);
        if ($state === null || $state['step'] < 0) {
            return;
        }
        self::askStep($api, $chatId, $state['flow'], $state['step'], $state['data'], $page);
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

        $stepDefinition = self::FLOWS[$flow]['steps'][$state['step']];
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

        self::submitStep($api, $chatId, $state, $value, $messageId);
    }

    /**
     * ثبت مقدار یک مرحله و رفتن به مرحله‌ی بعد/تأیید/اجرا — چه مقدار تایپ
     * شده باشد چه از دکمه آمده باشد.
     *
     * @param array{flow: string, step: int, data: array<string, mixed>} $state
     */
    private static function submitStep(TelegramApi $api, int $chatId, array $state, string $value, ?int $messageId): void
    {
        $flow = $state['flow'];
        $definition = self::FLOWS[$flow];
        $stepDefinition = $definition['steps'][$state['step']];
        $data = $state['data'];
        unset($data['__options'], $data['__page']);

        if ($value === '' && ($stepDefinition['optional'] ?? false) !== true) {
            $api->sendHtml($chatId, '⚠️ مقدار خالی است، دوباره بفرستید.');

            return;
        }

        $data[$stepDefinition['key']] = $value;
        $nextStep = $state['step'] + 1;

        if ($nextStep < count($definition['steps'])) {
            self::askStep($api, $chatId, $flow, $nextStep, $data);

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
        // کلیدهای داخلی (فهرست دکمه‌ها و شماره‌ی صفحه) به عملیات نمی‌رسند.
        unset($data['__options'], $data['__page']);
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
        $email = trim((string) $data['local']) . '@' . trim((string) $data['domain']);
        CpanelService::addEmail(self::accountClient(), $email, (string) $data['password'], $data['quota'] ?? 0);

        return '✅ حساب ایمیل <code>' . Format::escapeHtml($email) . '</code> ساخته شد.';
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
    private static function runAdminAdd(array $data): string
    {
        $id = trim((string) $data['id']);
        if (!ctype_digit($id)) {
            throw CpanelError::config('آیدی تلگرام باید فقط عدد باشد (یوزرنیم قبول نیست).');
        }
        Admins::add((int) $id);

        return '✅ کاربر <code>' . Format::escapeHtml($id) . '</code> به ادمین‌ها اضافه شد.';
    }

    /** @param array<string, mixed> $data */
    private static function runAdminDel(array $data): string
    {
        $id = (int) $data['id'];
        if ($id === self::currentAdminId()) {
            throw CpanelError::config('خودتان را نمی‌توانید حذف کنید؛ اگر می‌خواهید بروید، اول یک ادمین دیگر اضافه کنید و از حساب او حذفتان کند.');
        }
        if (in_array($id, Admins::fromEnv(), true)) {
            throw CpanelError::config('این ادمین در فایل <code>.env</code> تعریف شده و از داخل ربات حذف نمی‌شود.');
        }
        if (count(Admins::all()) <= 1) {
            throw CpanelError::config('این تنها ادمین ربات است؛ اگر حذفش کنید هیچ‌کس به ربات دسترسی نخواهد داشت.');
        }
        Admins::remove($id);

        return '🗑 دسترسی <code>' . Format::escapeHtml((string) $id) . '</code> قطع شد.';
    }

    /** @param array<string, mixed> $data */
    private static function runSelfhostCron(array $data): string
    {
        $path = rtrim(trim((string) $data['path']), '/');
        $command = trim((string) $data['php_bin']) . ' ' . $path . '/poll.php >/dev/null 2>&1';

        CpanelService::addCron(
            self::accountClient(),
            ['minute' => '*', 'hour' => '*', 'day' => '*', 'month' => '*', 'weekday' => '*'],
            $command,
        );

        return "✅ کران ساخته شد و ربات از این به بعد روی همین هاست اجرا می‌شود:\n<code>"
            . Format::escapeHtml($command) . "</code>\n\n"
            . '💡 اگر ربات جای دیگری هم (به‌صورت سرویس یا وبهوک) در حال اجراست، آن یکی را خاموش کنید تا دو نسخه هم‌زمان جواب ندهند.';
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

    /**
     * آیدی ادمینی که همین حالا دارد با ربات کار می‌کند (از روی actor).
     */
    private static function currentAdminId(): ?int
    {
        if (!str_starts_with(self::$actor, 'tg:')) {
            return null;
        }
        $id = substr(self::$actor, 3);

        return ctype_digit($id) ? (int) $id : null;
    }

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
     * قبل از تحویل‌گرفتن ربات: فقط کد نصب کار می‌کند. اولین کسی که کد درست را
     * بفرستد مالک می‌شود و از آن به بعد بقیه‌ی تنظیمات از داخل ربات انجام
     * می‌شود.
     */
    private static function handleClaim(TelegramApi $api, int $chatId, mixed $fromId, string $text, ?int $messageId): void
    {
        if (!is_numeric($fromId)) {
            return;
        }

        if (Admins::claim((int) $fromId, $text)) {
            if ($messageId !== null) {
                // کد از تاریخچه‌ی چت پاک می‌شود.
                $api->deleteMessage($chatId, $messageId);
            }
            AuditLog::record('tg:' . $fromId, '-', 'claim_bot', []);
            $api->sendHtml(
                $chatId,
                "✅ ربات تحویل شما شد؛ از این به بعد فقط شما (و هر ادمینی که خودتان اضافه کنید) به آن دسترسی دارید.\n\n"
                . self::welcome(),
                self::mainKeyboard(),
            );

            return;
        }

        $api->sendHtml(
            $chatId,
            "🔒 این ربات هنوز تحویل گرفته نشده.\n\n"
            . "کد نصب را که موقع نصب روی سرور چاپ شد برای من بفرستید تا مالکش شوید.\n"
            . 'اگر کد را ندارید، روی سرور بزنید: <code>teamkan code</code>',
        );
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
