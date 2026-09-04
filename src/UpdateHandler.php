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
     * پیامی که «صفحه‌ی» فعلی ربات است. تا جای ممکن همین پیام ویرایش می‌شود
     * تا با هر دکمه یک پیام تازه به چت اضافه نشود.
     */
    private static ?int $screen = null;

    /** وضعیت مرور فایل‌ها (جریان نیست، فقط یک صفحه‌ی زنده است). */
    private const BROWSE = '__browse';

    /** حداکثر حجم فایلی که تلگرام اجازه‌ی دانلودش را به ربات می‌دهد. */
    private const MAX_UPLOAD = 20 * 1024 * 1024;

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
            'back' => 'email',
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
            'back' => 'email',
            'steps' => [
                ['key' => 'email', 'prompt' => 'کدام حساب ایمیل؟', 'picker' => 'emails'],
                ['key' => 'password', 'prompt' => 'رمز جدید را بفرستید (یا <code>/gen</code>):', 'secret' => true],
            ],
        ],
        'email_quota' => [
            'title' => '📦 تغییر سهمیه‌ی ایمیل',
            'scope' => 'account',
            'back' => 'email',
            'steps' => [
                ['key' => 'email', 'prompt' => 'کدام حساب ایمیل؟', 'picker' => 'emails'],
                ['key' => 'quota', 'prompt' => 'سهمیه‌ی جدید:', 'choices' => self::QUOTA_CHOICES],
            ],
        ],
        'email_del' => [
            'title' => '🗑 حذف حساب ایمیل',
            'scope' => 'account',
            'back' => 'email',
            'confirm' => '⚠️ حساب <code>{email}</code> و همه‌ی پیام‌هایش برای همیشه حذف می‌شود.',
            'steps' => [
                ['key' => 'email', 'prompt' => 'کدام حساب ایمیل حذف شود؟', 'picker' => 'emails'],
            ],
        ],
        'db_add' => [
            'title' => '🗄 ساخت دیتابیس',
            'scope' => 'account',
            'back' => 'db',
            'steps' => [
                ['key' => 'name', 'prompt' => "نام دیتابیس را بفرستید.\n\n💡 در cPanel نام باید با پیشوند حساب باشد، مثل <code>user_shop</code>."],
            ],
        ],
        'db_del' => [
            'title' => '🗑 حذف دیتابیس',
            'scope' => 'account',
            'back' => 'db',
            'confirm' => '⚠️ دیتابیس <code>{name}</code> و تمام داده‌هایش برای همیشه حذف می‌شود.',
            'steps' => [
                ['key' => 'name', 'prompt' => 'کدام دیتابیس حذف شود؟', 'picker' => 'databases'],
            ],
        ],
        'dbuser_add' => [
            'title' => '👤 ساخت کاربر دیتابیس',
            'scope' => 'account',
            'back' => 'db',
            'steps' => [
                ['key' => 'name', 'prompt' => 'نام کاربر را بفرستید (با پیشوند حساب، مثل <code>user_shop</code>):'],
                ['key' => 'password', 'prompt' => 'رمز عبور را بفرستید (یا <code>/gen</code>):', 'secret' => true],
            ],
        ],
        'dbuser_del' => [
            'title' => '🗑 حذف کاربر دیتابیس',
            'scope' => 'account',
            'back' => 'db',
            'confirm' => '⚠️ کاربر <code>{name}</code> حذف می‌شود و دسترسی‌هایش از بین می‌رود.',
            'steps' => [
                ['key' => 'name', 'prompt' => 'کدام کاربر دیتابیس حذف شود؟', 'picker' => 'dbusers'],
            ],
        ],
        'db_passwd' => [
            'title' => '🔑 تغییر رمز کاربر دیتابیس',
            'scope' => 'account',
            'back' => 'db',
            'steps' => [
                ['key' => 'user', 'prompt' => 'کدام کاربر دیتابیس؟', 'picker' => 'dbusers'],
                ['key' => 'password', 'prompt' => 'رمز جدید را بفرستید (یا <code>/gen</code>):', 'secret' => true],
            ],
        ],
        'db_rename' => [
            'title' => '✏️ تغییر نام دیتابیس',
            'scope' => 'account',
            'back' => 'db',
            'confirm' => '⚠️ نام دیتابیس <code>{name}</code> به <code>{newname}</code> تغییر می‌کند. یادتان باشد فایل تنظیمات سایت (مثل wp-config.php) را هم به‌روز کنید.',
            'steps' => [
                ['key' => 'name', 'prompt' => 'کدام دیتابیس؟', 'picker' => 'databases'],
                ['key' => 'newname', 'prompt' => 'نام جدید (با همان پیشوند حساب):'],
            ],
        ],
        'dbuser_rename' => [
            'title' => '✏️ تغییر نام کاربر دیتابیس',
            'scope' => 'account',
            'back' => 'db',
            'confirm' => '⚠️ نام کاربر <code>{name}</code> به <code>{newname}</code> تغییر می‌کند؛ تنظیمات سایت را هم عوض کنید.',
            'steps' => [
                ['key' => 'name', 'prompt' => 'کدام کاربر دیتابیس؟', 'picker' => 'dbusers'],
                ['key' => 'newname', 'prompt' => 'نام جدید (با همان پیشوند حساب):'],
            ],
        ],
        'db_repair' => [
            'title' => '🛠 بررسی و تعمیر دیتابیس',
            'scope' => 'account',
            'back' => 'db',
            'steps' => [
                ['key' => 'name', 'prompt' => "کدام دیتابیس بررسی/تعمیر شود؟\n\n💡 وقتی سایت خطای «table is marked as crashed» می‌دهد، همین کار معمولاً مشکل را حل می‌کند.", 'picker' => 'databases'],
                ['key' => 'mode', 'prompt' => 'کدام کار؟', 'choices' => [
                    ['🛠 تعمیر (Repair)', 'repair'],
                    ['🔍 فقط بررسی (Check)', 'check'],
                ]],
            ],
        ],
        'db_revoke' => [
            'title' => '🚫 گرفتن دسترسی کاربر از دیتابیس',
            'scope' => 'account',
            'back' => 'db',
            'confirm' => '⚠️ دسترسی <code>{user}</code> به دیتابیس <code>{database}</code> برداشته می‌شود.',
            'steps' => [
                ['key' => 'user', 'prompt' => 'کدام کاربر؟', 'picker' => 'dbusers'],
                ['key' => 'database', 'prompt' => 'از کدام دیتابیس؟', 'picker' => 'databases'],
            ],
        ],
        'mysql_host_add' => [
            'title' => '🌍 اجازه‌ی اتصال از راه دور به MySQL',
            'scope' => 'account',
            'back' => 'db',
            'steps' => [
                ['key' => 'host', 'prompt' => "آی‌پی یا هاستی که باید اجازه‌ی اتصال داشته باشد را بفرستید.\n\nمثال: <code>203.0.113.10</code> — با <code>%</code> هم می‌شود بازه داد (مثل <code>203.0.113.%</code>).\n⚠️ فقط <code>%</code> یعنی «همه‌جا»، که امن نیست."],
            ],
        ],
        'mysql_host_del' => [
            'title' => '🗑 حذف دسترسی از راه دور',
            'scope' => 'account',
            'back' => 'db',
            'confirm' => '⚠️ اجازه‌ی اتصال <code>{host}</code> به MySQL برداشته می‌شود.',
            'steps' => [
                ['key' => 'host', 'prompt' => 'کدام میزبان حذف شود؟', 'picker' => 'mysql_hosts'],
            ],
        ],
        'redirect_add' => [
            'title' => '↪️ افزودن ریدایرکت',
            'scope' => 'account',
            'back' => 'dom',
            'steps' => [
                ['key' => 'domain', 'prompt' => 'کدام دامنه ریدایرکت شود؟', 'picker' => 'domains'],
                ['key' => 'destination', 'prompt' => "آدرس مقصد را کامل بفرستید، مثل:\n<code>https://example.com/new</code>"],
                ['key' => 'type', 'prompt' => "نوع ریدایرکت؟\n\n۳۰۱ یعنی «برای همیشه» (گوگل هم آدرس جدید را جایگزین می‌کند) و ۳۰۲ یعنی «موقت».", 'choices' => [
                    ['↪️ دائمی (301)', 'permanent'],
                    ['↩️ موقت (302)', 'temporary'],
                ]],
            ],
        ],
        'redirect_del' => [
            'title' => '🗑 حذف ریدایرکت',
            'scope' => 'account',
            'back' => 'dom',
            'confirm' => '⚠️ ریدایرکت <code>{source}</code> حذف می‌شود.',
            'steps' => [
                ['key' => 'source', 'prompt' => 'کدام ریدایرکت حذف شود؟', 'picker' => 'redirects'],
            ],
        ],
        'db_grant' => [
            'title' => '🔗 دادن دسترسی کاربر به دیتابیس',
            'scope' => 'account',
            'back' => 'db',
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
            'back' => 'dom',
            'steps' => [
                ['key' => 'rootdomain', 'prompt' => 'روی کدام دامنه؟', 'picker' => 'zones'],
                ['key' => 'subdomain', 'prompt' => 'فقط بخش ساب‌دامنه را بفرستید (مثل <code>shop</code>):'],
                ['key' => 'dir', 'prompt' => 'مسیر پوشه (اختیاری، خالی = پیش‌فرض cPanel):', 'optional' => true, 'default' => ''],
            ],
        ],
        'sub_del' => [
            'title' => '🗑 حذف ساب‌دامنه',
            'scope' => 'account',
            'back' => 'dom',
            'confirm' => '⚠️ ساب‌دامنه‌ی <code>{domain}</code> حذف می‌شود (فایل‌های پوشه‌اش باقی می‌مانند).',
            'steps' => [
                ['key' => 'domain', 'prompt' => 'کدام ساب‌دامنه حذف شود؟', 'picker' => 'subdomains'],
            ],
        ],
        'ftp_add' => [
            'title' => '📁 افزودن حساب FTP',
            'scope' => 'account',
            'back' => 'ftp',
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
            'back' => 'ftp',
            'confirm' => '⚠️ حساب FTP <code>{user}</code> حذف می‌شود (فایل‌ها پاک نمی‌شوند).',
            'steps' => [
                ['key' => 'user', 'prompt' => 'کدام حساب FTP حذف شود؟', 'picker' => 'ftp'],
            ],
        ],
        'cron_add' => [
            'title' => '⏰ افزودن کران‌جاب',
            'scope' => 'account',
            'back' => 'cron',
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
            'back' => 'cron',
            'confirm' => '⚠️ کران‌جاب انتخاب‌شده حذف می‌شود.',
            'steps' => [
                ['key' => 'key', 'prompt' => 'کدام کران‌جاب حذف شود؟', 'picker' => 'cron'],
            ],
        ],
        'dns_list' => [
            'title' => '🧭 نمایش رکوردهای DNS',
            'scope' => 'account',
            'back' => 'dns',
            'steps' => [
                ['key' => 'zone', 'prompt' => 'رکوردهای کدام دامنه؟', 'picker' => 'zones'],
            ],
        ],
        'dns_add' => [
            'title' => '➕ افزودن رکورد DNS',
            'scope' => 'account',
            'back' => 'dns',
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
            'back' => 'dns',
            'confirm' => '⚠️ رکورد شماره‌ی <code>{line}</code> از زون <code>{zone}</code> حذف می‌شود.',
            'steps' => [
                ['key' => 'zone', 'prompt' => 'از کدام دامنه؟', 'picker' => 'zones'],
                ['key' => 'line', 'prompt' => 'کدام رکورد حذف شود؟', 'picker' => 'dns_records'],
            ],
        ],
        'php_set' => [
            'title' => '🐘 تغییر نسخه‌ی PHP دامنه',
            'scope' => 'account',
            'back' => 'php',
            'steps' => [
                ['key' => 'vhost', 'prompt' => 'کدام دامنه؟', 'picker' => 'vhosts'],
                ['key' => 'version', 'prompt' => 'کدام نسخه‌ی PHP؟', 'picker' => 'php_versions'],
            ],
        ],
        'backup_run' => [
            'title' => '💾 گرفتن بکاپ کامل',
            'scope' => 'account',
            'back' => 'backup',
            'confirm' => '⚠️ ساخت بکاپ کامل شروع می‌شود؛ بسته به حجم حساب، فضای هوم و مقداری CPU مصرف می‌کند.',
            'steps' => [
                ['key' => 'email', 'prompt' => 'ایمیل برای اطلاع‌رسانی پایان بکاپ (اختیاری):', 'optional' => true, 'default' => ''],
            ],
        ],
        'whm_user' => [
            'title' => '🎯 انتخاب کاربر مقصد',
            'scope' => 'local',
            'back' => 'whm',
            'steps' => [
                ['key' => 'user', 'prompt' => 'کدام حساب را مدیریت می‌کنید؟', 'picker' => 'whm_accounts'],
            ],
        ],
        'whm_summary' => [
            'title' => '🔍 جزئیات حساب',
            'scope' => 'whm',
            'back' => 'whm',
            'steps' => [
                ['key' => 'user', 'prompt' => 'کدام حساب؟', 'picker' => 'whm_accounts'],
            ],
        ],
        'whm_suspend' => [
            'title' => '⛔️ تعلیق حساب',
            'scope' => 'whm',
            'back' => 'whm',
            'confirm' => '⚠️ حساب <code>{user}</code> معلق می‌شود؛ سایت‌ها و ایمیل‌هایش از کار می‌افتند.',
            'steps' => [
                ['key' => 'user', 'prompt' => 'کدام حساب معلق شود؟', 'picker' => 'whm_accounts'],
                ['key' => 'reason', 'prompt' => 'دلیل تعلیق (اختیاری):', 'optional' => true, 'default' => ''],
            ],
        ],
        'whm_unsuspend' => [
            'title' => '✅ رفع تعلیق حساب',
            'scope' => 'whm',
            'back' => 'whm',
            'steps' => [
                ['key' => 'user', 'prompt' => 'کدام حساب از تعلیق دربیاید؟', 'picker' => 'whm_accounts'],
            ],
        ],
        'whm_passwd' => [
            'title' => '🔑 تغییر رمز حساب',
            'scope' => 'whm',
            'back' => 'whm',
            'confirm' => '⚠️ رمز حساب <code>{user}</code> عوض می‌شود (رمز دیتابیس‌ها و FTP همان حساب هم می‌تواند تغییر کند).',
            'steps' => [
                ['key' => 'user', 'prompt' => 'کدام حساب؟', 'picker' => 'whm_accounts'],
                ['key' => 'password', 'prompt' => 'رمز جدید را بفرستید (یا <code>/gen</code>):', 'secret' => true],
            ],
        ],
        'whm_login' => [
            'title' => '🔐 لینک ورود یک‌بارمصرف',
            'scope' => 'whm',
            'back' => 'whm',
            'steps' => [
                ['key' => 'user', 'prompt' => 'برای کدام حساب لینک ساخته شود؟', 'picker' => 'whm_accounts'],
            ],
        ],
        'whm_create' => [
            'title' => '🆕 ساخت حساب جدید',
            'scope' => 'whm',
            'back' => 'whm',
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
            'back' => 'whm',
            'confirm' => '⚠️ حساب <code>{user}</code> با تمام سایت‌ها، ایمیل‌ها و دیتابیس‌هایش برای همیشه حذف می‌شود. این کار برگشت‌پذیر نیست.',
            'steps' => [
                ['key' => 'user', 'prompt' => 'کدام حساب حذف شود؟', 'picker' => 'whm_accounts'],
                ['key' => 'confirm_user', 'prompt' => 'برای اطمینان، دقیقاً همان نام کاربری را تایپ کنید:'],
            ],
        ],
        'file_upload' => [
            'title' => '⬆️ آپلود فایل روی هاست',
            'scope' => 'account',
            'back' => 'files',
            'steps' => [
                ['key' => 'dir', 'prompt' => 'فایل کجا آپلود شود؟', 'picker' => 'dirs'],
                ['key' => 'file', 'prompt' => "حالا خود فایل را همین‌جا بفرستید (به‌صورت Document، نه عکس).\n\n💡 برای سورس سایت، یک <code>zip</code> بفرستید و بعد همین‌جا بازش کنید.\n⚠️ سقف حجم در تلگرام ۲۰ مگابایت است.", 'file' => true],
            ],
        ],
        'file_extract' => [
            'title' => '🗜 باز کردن آرشیو روی هاست',
            'scope' => 'account',
            'back' => 'files',
            'steps' => [
                ['key' => 'path', 'prompt' => "مسیر کامل فایل آرشیو را بفرستید، مثل:\n<code>/home/user/public_html/site.zip</code>"],
                ['key' => 'dest', 'prompt' => 'کجا باز شود؟ (خالی = کنار خود فایل)', 'optional' => true, 'default' => ''],
            ],
        ],
        'file_mkdir' => [
            'title' => '📁 ساخت پوشه',
            'scope' => 'account',
            'back' => 'files',
            'steps' => [
                ['key' => 'dir', 'prompt' => 'پوشه‌ی جدید داخل کدام مسیر ساخته شود؟', 'picker' => 'dirs'],
                ['key' => 'name', 'prompt' => 'نام پوشه‌ی جدید:'],
            ],
        ],
        'file_delete' => [
            'title' => '🗑 حذف فایل یا پوشه',
            'scope' => 'account',
            'back' => 'files',
            'confirm' => '⚠️ <code>{path}</code> برای همیشه از هاست حذف می‌شود.',
            'steps' => [
                ['key' => 'path', 'prompt' => 'مسیر کامل فایل یا پوشه‌ای که باید حذف شود:'],
            ],
        ],
        'fwd_add' => [
            'title' => '📮 افزودن فوروارد ایمیل',
            'scope' => 'account',
            'back' => 'email',
            'steps' => [
                ['key' => 'address', 'prompt' => 'ایمیلی که پیام‌ها به آن می‌رسد (مبدأ)، مثل <code>info@example.com</code>:'],
                ['key' => 'destination', 'prompt' => 'ایمیلی که پیام‌ها به آن فرستاده شود (مقصد):'],
            ],
        ],
        'fwd_del' => [
            'title' => '🗑 حذف فوروارد',
            'scope' => 'account',
            'back' => 'email',
            'confirm' => '⚠️ فوروارد <code>{address}</code> ← <code>{destination}</code> حذف می‌شود.',
            'steps' => [
                ['key' => 'address', 'prompt' => 'آدرس مبدأ فوروارد:'],
                ['key' => 'destination', 'prompt' => 'آدرس مقصد همان فوروارد:'],
            ],
        ],
        'cron_email' => [
            'title' => '📧 ایمیل خروجی کران',
            'scope' => 'account',
            'back' => 'cron',
            'steps' => [
                ['key' => 'email', 'prompt' => "خروجی کران‌جاب‌ها به کدام ایمیل فرستاده شود؟\n\n(برای خاموش‌کردن ایمیل، یک فاصله بفرستید.)"],
            ],
        ],
        'whm_package' => [
            'title' => '📦 تغییر پلن حساب',
            'scope' => 'whm',
            'back' => 'whm',
            'confirm' => '⚠️ پلن حساب <code>{user}</code> به <code>{package}</code> تغییر می‌کند (سهمیه‌هایش عوض می‌شود).',
            'steps' => [
                ['key' => 'user', 'prompt' => 'کدام حساب؟', 'picker' => 'whm_accounts'],
                ['key' => 'package', 'prompt' => 'کدام پلن؟', 'picker' => 'whm_plans'],
            ],
        ],
        'whm_service' => [
            'title' => '♻️ ری‌استارت سرویس سرور',
            'scope' => 'whm',
            'back' => 'whm',
            'confirm' => '⚠️ سرویس <code>{service}</code> روی کل سرور ری‌استارت می‌شود؛ چند لحظه قطعی خواهد داشت.',
            'steps' => [
                ['key' => 'service', 'prompt' => 'کدام سرویس ری‌استارت شود؟', 'choices' => [
                    ['🌐 وب‌سرور (Apache)', 'httpd'],
                    ['✉️ ایمیل (Exim)', 'exim'],
                    ['📥 دریافت ایمیل (Dovecot)', 'dovecot'],
                    ['🗄 دیتابیس (MySQL)', 'mysql'],
                    ['📁 FTP', 'ftpserver'],
                    ['🧭 DNS (named)', 'named'],
                    ['🖥 خود cPanel', 'cpsrvd'],
                ]],
            ],
        ],
        'selfhost_cron' => [
            'title' => '🧩 اجرای ربات با کران روی همین هاست',
            'scope' => 'account',
            'back' => 'srv',
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
            'back' => 'adm',
            'steps' => [
                ['key' => 'id', 'prompt' => "آیدی عددی تلگرام فرد را بفرستید.\n\n💡 هر کسی می‌تواند آیدی خودش را از ربات @userinfobot بگیرد."],
            ],
        ],
        'admin_del' => [
            'title' => '🗑 حذف ادمین',
            'scope' => 'local',
            'back' => 'adm',
            'confirm' => '⚠️ دسترسی ادمین <code>{id}</code> به این ربات قطع می‌شود.',
            'steps' => [
                ['key' => 'id', 'prompt' => 'کدام ادمین حذف شود؟', 'picker' => 'admins'],
            ],
        ],
        'srv_add' => [
            'title' => '➕ افزودن سرور',
            'scope' => 'local',
            'back' => 'srv',
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
            'back' => 'srv',
            'steps' => [
                ['key' => 'id', 'prompt' => 'کدام سرور فعال شود؟', 'picker' => 'servers'],
            ],
        ],
        'srv_del' => [
            'title' => '🗑 حذف سرور',
            'scope' => 'local',
            'back' => 'srv',
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
        $document = is_array($message['document'] ?? null) ? $message['document'] : null;

        if ($chatId === null || ($text === '' && $document === null)) {
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

        // پاسخ به پیام متنی، «صفحه‌»ی جریان جاری را ویرایش می‌کند (اگر باشد).
        $current = State::get((int) $chatId);
        $screen = $current['data']['__msg'] ?? null;
        self::$screen = is_numeric($screen) ? (int) $screen : null;

        if ($document !== null) {
            self::handleDocument($api, (int) $chatId, $document);

            return;
        }

        if ($text === '/cancel') {
            State::clear((int) $chatId);
            self::respond($api, (int) $chatId, '❌ عملیات لغو شد.', self::mainKeyboard());

            return;
        }

        // وسط مرور فایل‌ها، متن تایپ‌شده یعنی «برو به این مسیر».
        if ($current !== null && $current['flow'] === self::BROWSE && !str_starts_with($text, '/')) {
            self::browseFiles($api, (int) $chatId, $text);

            return;
        }

        // اگر وسط یک جریان چندمرحله‌ای هستیم، این پیام جواب همان مرحله است.
        $state = $current;
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

        // دکمه‌ها همان پیام خودشان را ویرایش می‌کنند، نه اینکه پیام تازه بفرستند.
        $screen = $callbackQuery['message']['message_id'] ?? null;
        self::$screen = is_numeric($screen) ? (int) $screen : null;

        if ($data === 'no') {
            State::clear($chatId);
            self::respond($api, $chatId, '❌ عملیات لغو شد.', self::mainKeyboard());

            return;
        }

        if ($data === 'ok') {
            $state = State::get($chatId);
            if ($state === null || $state['step'] !== -1) {
                self::respond($api, $chatId, 'چیزی برای تأیید در انتظار نیست.', self::mainKeyboard());

                return;
            }
            State::clear($chatId);
            self::execute($api, $chatId, $state['flow'], $state['data']);

            return;
        }

        if ($data === 'noop') {
            return;
        }

        if (str_starts_with($data, 'h:')) {
            self::howto($api, $chatId, substr($data, 2));

            return;
        }

        // دکمه‌های مرورگر فایل
        if (str_starts_with($data, 'fb') || str_starts_with($data, 'ff:')
            || str_starts_with($data, 'fx:') || str_starts_with($data, 'fd:') || $data === 'fup') {
            if (self::handleBrowseCallback($api, $chatId, $data)) {
                return;
            }
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
                self::respond($api, $chatId, 'چیزی برای رد کردن در جریان نیست.', self::mainKeyboard());

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
                self::respond($api, $chatId, self::welcome(), self::mainKeyboard());

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
            case '/files':
                self::runAction($api, $chatId, 'files');

                return;
            case '/backup':
                self::sendMenu($api, $chatId, 'backup');

                return;
            case '/features':
                self::runAction($api, $chatId, 'features');

                return;
        }

        if (str_starts_with($text, '/')) {
            self::respond($api, $chatId, 'این دستور را نمی‌شناسم. از منوی زیر استفاده کنید:', self::mainKeyboard());

            return;
        }

        self::respond(
            $api,
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
            'email' => ["✉️ <b>مدیریت ایمیل</b>\n\nحساب‌های ایمیل این هاست: ساخت، حذف، رمز، سهمیه و فورواردها.", [
                [self::btn('📋 لیست حساب‌ها', 'a:emails')],
                [self::btn('➕ افزودن', 'f:email_add'), self::btn('🗑 حذف', 'f:email_del')],
                [self::btn('🔑 تغییر رمز', 'f:email_passwd'), self::btn('📦 سهمیه', 'f:email_quota')],
                [self::btn('📮 فورواردها', 'a:forwarders')],
                [self::btn('⬅️ بازگشت', 'menu')],
            ]],
            'db' => [self::databaseMenuText(), [
                [self::btn('📋 دیتابیس‌ها و کاربران', 'a:dbs')],
                [self::btn('➕ دیتابیس جدید', 'f:db_add'), self::btn('👤 کاربر جدید', 'f:dbuser_add')],
                [self::btn('🔗 وصل‌کردن کاربر به دیتابیس', 'f:db_grant')],
                [self::btn('🔑 رمز کاربر', 'f:db_passwd'), self::btn('🚫 گرفتن دسترسی', 'f:db_revoke')],
                [self::btn('✏️ نام دیتابیس', 'f:db_rename'), self::btn('✏️ نام کاربر', 'f:dbuser_rename')],
                [self::btn('🛠 بررسی و تعمیر', 'f:db_repair'), self::btn('🌍 اتصال از راه دور', 'a:mysqlhosts')],
                [self::btn('🗑 حذف دیتابیس', 'f:db_del'), self::btn('🗑 حذف کاربر', 'f:dbuser_del')],
                [self::btn('⬅️ بازگشت', 'menu')],
            ]],
            'dom' => ["🌐 <b>دامنه‌ها</b>\n\nدامنه‌ی اصلی حساب، دامنه‌های اضافه (ادان)، ساب‌دامنه‌ها، ریدایرکت‌ها و گواهی‌های SSL.", [
                [self::btn('📋 لیست دامنه‌ها', 'a:domains')],
                [self::btn('➕ ساب‌دامنه', 'f:sub_add'), self::btn('🗑 حذف ساب‌دامنه', 'f:sub_del')],
                [self::btn('↪️ ریدایرکت‌ها', 'a:redirects'), self::btn('🧭 DNS', 'm:dns')],
                [self::btn('🔒 SSL', 'm:ssl')],
                [self::btn('⬅️ بازگشت', 'menu')],
            ]],
            'files' => ["📂 <b>فایل‌های هاست</b>\n\nسورس سایت را از همین‌جا آپلود کنید: فایل (یا zip) را بفرستید، و اگر آرشیو بود همان‌جا بازش کنید.\n"
                . '⚠️ سقف فایل در تلگرام ۲۰ مگابایت است؛ برای سورس‌های بزرگ‌تر آن را تکه یا zip کنید.', [
                [self::btn('🗂 مرور فایل‌ها', 'a:files')],
                [self::btn('⬆️ آپلود فایل', 'f:file_upload'), self::btn('🗜 باز کردن آرشیو', 'f:file_extract')],
                [self::btn('📁 پوشه‌ی جدید', 'f:file_mkdir'), self::btn('🗑 حذف فایل/پوشه', 'f:file_delete')],
                [self::btn('⬅️ بازگشت', 'menu')],
            ]],
            'ftp' => ["📁 <b>حساب‌های FTP</b>\n\nبرای وصل‌شدن با نرم‌افزارهایی مثل FileZilla.", [
                [self::btn('📋 لیست حساب‌ها', 'a:ftps')],
                [self::btn('➕ افزودن', 'f:ftp_add'), self::btn('🗑 حذف', 'f:ftp_del')],
                [self::btn('⬅️ بازگشت', 'menu')],
            ]],
            'cron' => ["⏰ <b>کران‌جاب‌ها</b>\n\nدستورهایی که سرور خودکار و سر وقت اجرا می‌کند (مثل کران وردپرس یا بکاپ روزانه).", [
                [self::btn('📋 لیست کران‌جاب‌ها', 'a:crons')],
                [self::btn('➕ افزودن', 'f:cron_add'), self::btn('🗑 حذف', 'f:cron_del')],
                [self::btn('📧 ایمیل خروجی کران', 'f:cron_email')],
                [self::btn('⬅️ بازگشت', 'menu')],
            ]],
            'dns' => ["🧭 <b>رکوردهای DNS</b>\n\nمشخص می‌کند دامنه به کجا اشاره کند (A برای آی‌پی، CNAME برای نام، MX برای ایمیل، TXT برای تأییدیه‌ها).", [
                [self::btn('📄 نمایش رکوردها', 'f:dns_list')],
                [self::btn('➕ رکورد جدید', 'f:dns_add'), self::btn('🗑 حذف رکورد', 'f:dns_del')],
                [self::btn('⬅️ بازگشت', 'm:dom')],
            ]],
            'ssl' => ["🔒 <b>گواهی SSL</b>\n\nگواهی همان چیزی است که قفل سبز مرورگر و آدرس <code>https</code> را می‌سازد. روی اکثر هاست‌ها cPanel خودش رایگان صادر می‌کند (AutoSSL).", [
                [self::btn('📋 گواهی‌های نصب‌شده', 'a:ssl')],
                [self::btn('🔄 درخواست صدور/تمدید خودکار', 'a:autossl')],
                [self::btn('⬅️ بازگشت', 'm:dom')],
            ]],
            'php' => ["🐘 <b>نسخه‌ی PHP</b>\n\nنسخه‌ی PHP هر دامنه را جدا می‌شود عوض کرد (MultiPHP).", [
                [self::btn('📋 نسخه‌ی هر دامنه', 'a:php')],
                [self::btn('🔧 تغییر نسخه', 'f:php_set')],
                [self::btn('⬅️ بازگشت', 'menu')],
            ]],
            'backup' => ["💾 <b>بکاپ</b>\n\nبکاپ کامل حساب در پوشه‌ی هوم ساخته می‌شود و بعد می‌توانید دانلودش کنید.", [
                [self::btn('📋 بکاپ‌های موجود', 'a:backups')],
                [self::btn('💾 گرفتن بکاپ کامل', 'f:backup_run')],
                [self::btn('⬅️ بازگشت', 'menu')],
            ]],
            'whm' => ["🖥 <b>مدیریت سرور (WHM)</b>\n\nکارهای سطح سرور: حساب‌ها، پلن‌ها و سرویس‌ها.", [
                [self::btn('👥 لیست حساب‌ها', 'a:whmaccts'), self::btn('🔍 جزئیات حساب', 'f:whm_summary')],
                [self::btn('⛔️ تعلیق', 'f:whm_suspend'), self::btn('✅ رفع تعلیق', 'f:whm_unsuspend')],
                [self::btn('🆕 ساخت حساب', 'f:whm_create'), self::btn('🔑 تغییر رمز', 'f:whm_passwd')],
                [self::btn('🔐 لینک ورود', 'f:whm_login'), self::btn('📦 تغییر پلن', 'f:whm_package')],
                [self::btn('🖥 وضعیت سرور', 'a:whmstatus'), self::btn('♻️ ری‌استارت سرویس', 'f:whm_service')],
                [self::btn('📦 پلن‌ها', 'a:whmpkgs'), self::btn('💀 حذف حساب', 'f:whm_terminate')],
                [self::btn('🎯 انتخاب کاربر مقصد', 'f:whm_user')],
                [self::btn('⬅️ بازگشت', 'menu')],
            ]],
            'howto' => ["🧭 <b>کارهای رایج</b>\n\nنمی‌دانید از کجا شروع کنید؟ کاری که می‌خواهید انجام دهید را بزنید تا قدم‌به‌قدم بگویم.", [
                [self::btn('🌐 راه‌اندازی یک سایت جدید', 'h:site')],
                [self::btn('📤 گذاشتن سورس روی هاست', 'h:upload')],
                [self::btn('🗄 ساخت دیتابیس و وصل‌کردنش', 'h:db')],
                [self::btn('✉️ ساخت ایمیل جدید', 'h:email')],
                [self::btn('🔒 گرفتن SSL برای دامنه', 'h:ssl')],
                [self::btn('💾 گرفتن بکاپ', 'h:backup')],
                [self::btn('⬅️ بازگشت', 'menu')],
            ]],
            'adm' => ["👮 <b>ادمین‌های ربات</b>\n\nهر کسی که اینجا باشد به همه‌ی امکانات ربات دسترسی دارد.", [
                [self::btn('📋 لیست ادمین‌ها', 'a:admins')],
                [self::btn('➕ افزودن ادمین', 'f:admin_add'), self::btn('🗑 حذف ادمین', 'f:admin_del')],
                [self::btn('⬅️ بازگشت', 'menu')],
            ]],
            'srv' => ["🖥 <b>سرورها</b>\n\nهر تعداد هاست cPanel یا سرور WHM می‌توانید اضافه کنید و بینشان جابه‌جا شوید.", array_merge(
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

        self::respond($api, $chatId, $header . "\n\n" . $text, ['inline_keyboard' => $keyboard]);
    }

    /**
     * منوی اصلی، متناسب با وضعیت:
     *   - تازه‌نصب (بدون سرور): فقط راهنما و سرورها
     *   - حساب cPanel: فقط کارهای سطح حساب
     *   - WHM بدون کاربر مقصد: فقط کارهای سطح سرور
     *   - WHM با کاربر مقصد: هر دو
     * یعنی هیچ دکمه‌ای که روی این سرور کار نمی‌کند اصلاً نشان داده نمی‌شود.
     *
     * @return array{inline_keyboard: array<int, array<int, array<string, string>>>}
     */
    private static function mainKeyboard(): array
    {
        $context = self::context();

        if ($context['state'] === 'none') {
            return ['inline_keyboard' => [
                [self::btn('⚙️ افزودن سرور / هاست', 'm:srv')],
                [self::btn('❓ راهنما', 'a:help')],
            ]];
        }

        $rows = [];

        if ($context['is_whm']) {
            $rows[] = [self::btn('🖥 مدیریت سرور (WHM)', 'm:whm'), self::btn('👥 حساب‌ها', 'a:whmaccts')];
            $rows[] = [self::btn(
                $context['target'] !== '' ? '🎯 کاربر مقصد: ' . $context['target'] : '🎯 انتخاب کاربر مقصد',
                'f:whm_user',
            )];
        }

        if ($context['state'] === 'cpanel' || $context['state'] === 'whm_ready') {
            $rows[] = [self::btn('📊 خلاصه‌ی حساب', 'a:summary'), self::btn('🌐 دامنه‌ها', 'm:dom')];
            $rows[] = [self::btn('✉️ ایمیل‌ها', 'm:email'), self::btn('🗄 دیتابیس‌ها', 'm:db')];
            $rows[] = [self::btn('📂 فایل‌ها', 'm:files'), self::btn('📁 FTP', 'm:ftp')];
            $rows[] = [self::btn('⏰ کران‌جاب', 'm:cron'), self::btn('🧭 DNS', 'm:dns')];
            $rows[] = [self::btn('🐘 نسخه PHP', 'm:php'), self::btn('💾 بکاپ', 'm:backup')];
        }

        $rows[] = [self::btn('⚙️ سرورها', 'm:srv'), self::btn('👮 ادمین‌ها', 'm:adm')];
        $rows[] = [self::btn('🧭 کارهای رایج', 'm:howto'), self::btn('✨ قابلیت‌ها', 'a:features')];
        $rows[] = [self::btn('🧾 لاگ عملیات', 'a:log')];
        $rows[] = [self::btn('❓ راهنما', 'a:help')];

        return ['inline_keyboard' => $rows];
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

    /**
     * پاسخ ربات: اگر صفحه‌ای برای ویرایش داریم همان را به‌روز می‌کنیم، وگرنه
     * پیام تازه می‌فرستیم. متن‌های خیلی بلند (که در یک پیام جا نمی‌شوند)
     * همیشه به‌صورت پیام تازه می‌روند.
     *
     * @param array<string, mixed>|null $keyboard
     */
    private static function respond(TelegramApi $api, int $chatId, string $html, ?array $keyboard = null): void
    {
        $chunks = Render::chunks($html);

        if (self::$screen !== null && count($chunks) === 1
            && $api->editHtml($chatId, self::$screen, $chunks[0], $keyboard)) {
            return;
        }

        self::$screen = $api->sendHtml($chatId, $html, $keyboard);
    }

    /**
     * دکمه‌ی بازگشت — هر صفحه‌ای باید راه برگشت داشته باشد.
     *
     * @return array{inline_keyboard: array<int, array<int, array<string, string>>>}
     */
    private static function backKeyboard(?string $section = null): array
    {
        $row = [];
        if ($section !== null && $section !== 'menu') {
            $row[] = self::btn('⬅️ بازگشت', 'm:' . $section);
        }
        $row[] = self::btn('🏠 منوی اصلی', 'menu');

        return ['inline_keyboard' => [$row]];
    }

    /**
     * متن منوی دیتابیس با اطلاعات زنده‌ی همان حساب (پیشوند اجباری نام‌ها) —
     * چون بیشترین سردرگمی کاربرها سر همین پیشوند است.
     */
    private static function databaseMenuText(): string
    {
        $text = "🗄 <b>دیتابیس‌های MySQL</b>\n\n"
            . "برای وصل‌کردن یک سایت به دیتابیس سه قدم لازم است:\n"
            . "۱) ساخت دیتابیس  ۲) ساخت کاربر  ۳) وصل‌کردن کاربر به دیتابیس\n"
            . 'بعد همان سه مقدار (نام دیتابیس، نام کاربر، رمز) را در فایل تنظیمات سایت می‌گذارید.';

        try {
            $info = CpanelService::databaseInfo(self::accountClient());
            if ($info['prefix'] !== '') {
                $text .= "\n\n💡 روی این هاست نام دیتابیس و کاربر باید با <code>"
                    . Format::escapeHtml($info['prefix']) . '</code> شروع شود.';
            }
        } catch (Throwable) {
            // اطلاعات زنده اختیاری است؛ نبودنش نباید منو را خراب کند.
        }

        return $text;
    }

    /**
     * وضعیت سرور فعال — چیدمان منو بر اساس همین ساخته می‌شود.
     *
     * @return array{state: string, is_whm: bool, target: string, server: array<string, mixed>|null}
     */
    private static function context(): array
    {
        $server = ServerStore::active();
        if ($server === null) {
            return ['state' => 'none', 'is_whm' => false, 'target' => '', 'server' => null];
        }

        $isWhm = ($server['type'] ?? '') === CpanelClient::TYPE_WHM;
        $target = trim((string) ($server['as_user'] ?? ''));

        return [
            'state' => $isWhm ? ($target !== '' ? 'whm_ready' : 'whm') : 'cpanel',
            'is_whm' => $isWhm,
            'target' => $target,
            'server' => $server,
        ];
    }

    // ------------------------------------------------------------ کارها

    private static function runAction(TelegramApi $api, int $chatId, string $action): void
    {
        if ($action === 'help') {
            self::respond($api, $chatId, self::welcome(), self::mainKeyboard());

            return;
        }

        if ($action === 'servers') {
            self::respond($api, $chatId, Render::servers(ServerStore::all(), ServerStore::activeId()), self::backKeyboard('srv'));

            return;
        }

        if ($action === 'files') {
            self::browseFiles($api, $chatId);

            return;
        }

        if ($action === 'features') {
            self::respond($api, $chatId, self::features(), self::backKeyboard());

            return;
        }

        if ($action === 'redirects') {
            $api->sendChatAction($chatId);
            try {
                $text = Render::redirects(CpanelService::redirects(self::accountClient()));
                $keyboard = ['inline_keyboard' => [
                    [self::btn('➕ افزودن ریدایرکت', 'f:redirect_add'), self::btn('🗑 حذف', 'f:redirect_del')],
                    [self::btn('⬅️ بازگشت', 'm:dom')],
                ]];
            } catch (Throwable $e) {
                $text = Render::error($e);
                $keyboard = self::backKeyboard('dom');
            }
            self::respond($api, $chatId, $text, $keyboard);

            return;
        }

        if ($action === 'mysqlhosts') {
            $api->sendChatAction($chatId);
            try {
                $hosts = CpanelService::remoteMysqlHosts(self::accountClient());
                $text = $hosts === []
                    ? "🌍 <b>اتصال از راه دور به MySQL</b>\n\nهیچ میزبانی اجازه‌ی اتصال از بیرون ندارد.\n\n💡 اگر می‌خواهید از کامپیوتر خودتان یا یک سرور دیگر مستقیم به دیتابیس وصل شوید، آی‌پی‌اش را اینجا اضافه کنید."
                    : "🌍 <b>میزبان‌های مجاز برای اتصال از راه دور</b>\n\n"
                        . implode("\n", array_map(static fn (string $host): string => '• <code>' . Format::escapeHtml($host) . '</code>', $hosts));
                $keyboard = ['inline_keyboard' => [
                    [self::btn('➕ افزودن میزبان', 'f:mysql_host_add'), self::btn('🗑 حذف', 'f:mysql_host_del')],
                    [self::btn('⬅️ بازگشت', 'm:db')],
                ]];
            } catch (Throwable $e) {
                $text = Render::error($e);
                $keyboard = self::backKeyboard('db');
            }
            self::respond($api, $chatId, $text, $keyboard);

            return;
        }

        if ($action === 'forwarders') {
            $api->sendChatAction($chatId);
            try {
                $text = Render::forwarders(CpanelService::emailForwarders(self::accountClient()));
                $keyboard = ['inline_keyboard' => [
                    [self::btn('➕ افزودن فوروارد', 'f:fwd_add'), self::btn('🗑 حذف فوروارد', 'f:fwd_del')],
                    [self::btn('⬅️ بازگشت', 'm:email')],
                ]];
            } catch (Throwable $e) {
                $text = Render::error($e);
                $keyboard = self::backKeyboard('email');
            }
            self::respond($api, $chatId, $text, $keyboard);

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
            self::respond($api, $chatId, implode("\n", $lines), self::backKeyboard('adm'));

            return;
        }

        if ($action === 'log') {
            $lines = AuditLog::tail(15);
            $text = $lines === []
                ? '🧾 هنوز عملیاتی ثبت نشده.'
                : "🧾 <b>آخرین عملیات‌ها</b>\n\n<code>" . Format::escapeHtml(implode("\n", $lines)) . '</code>';
            self::respond($api, $chatId, $text, self::backKeyboard());

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
                'backups' => Render::backups(CpanelService::listBackups(self::accountClient())),
                'autossl' => self::runAutoSslAction(),
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

        self::respond($api, $chatId, $text, self::backKeyboard(self::sectionOf($action)));
    }

    /**
     * راهنمای گام‌به‌گام کارهای رایج: هر قدم یک دکمه است که مستقیم می‌برد به
     * همان بخش.
     */
    private static function howto(TelegramApi $api, int $chatId, string $topic): void
    {
        [$text, $rows] = match ($topic) {
            'site' => [
                "🌐 <b>راه‌اندازی یک سایت جدید</b>\n\n"
                . "۱️⃣ دامنه یا ساب‌دامنه را بسازید (اگر دامنه‌ی اصلی است، همین حالا آماده است)\n"
                . "۲️⃣ سورس سایت را به‌صورت zip در پوشه‌ی سایت آپلود و بازش کنید\n"
                . "۳️⃣ دیتابیس و کاربر بسازید و کاربر را به دیتابیس وصل کنید\n"
                . "۴️⃣ اطلاعات دیتابیس را در فایل تنظیمات سایت بگذارید (مثل wp-config.php)\n"
                . "۵️⃣ نسخه‌ی PHP مناسب را انتخاب کنید و SSL بگیرید",
                [
                    [self::btn('۱ ➕ ساب‌دامنه', 'f:sub_add')],
                    [self::btn('۲ 📂 آپلود سورس', 'm:files')],
                    [self::btn('۳ 🗄 دیتابیس', 'm:db')],
                    [self::btn('۵ 🐘 نسخه PHP', 'm:php'), self::btn('🔒 SSL', 'm:ssl')],
                ],
            ],
            'upload' => [
                "📤 <b>گذاشتن سورس روی هاست</b>\n\n"
                . "۱️⃣ «مرور فایل‌ها» را بزنید و بروید داخل پوشه‌ی سایت (معمولاً <code>public_html</code>)\n"
                . "۲️⃣ «آپلود در همین پوشه» را بزنید\n"
                . "۳️⃣ فایل <code>zip</code> سورس را همین‌جا در تلگرام بفرستید\n"
                . "۴️⃣ بعد از آپلود، دکمه‌ی «باز کردن آرشیو» را بزنید\n\n"
                . '⚠️ سقف حجم فایل در تلگرام ۲۰ مگابایت است.',
                [[self::btn('🗂 شروع: مرور فایل‌ها', 'a:files')]],
            ],
            'db' => [
                "🗄 <b>ساخت دیتابیس و وصل‌کردنش به سایت</b>\n\n"
                . "۱️⃣ یک دیتابیس بسازید (نامش باید با پیشوند حساب شروع شود)\n"
                . "۲️⃣ یک کاربر دیتابیس با رمز قوی بسازید (<code>/gen</code> برایتان می‌سازد)\n"
                . "۳️⃣ کاربر را با دسترسی کامل به دیتابیس وصل کنید\n"
                . "۴️⃣ نام دیتابیس، نام کاربر و رمز را در تنظیمات سایت بگذارید\n\n"
                . '💡 اگر سایت خطای «table is marked as crashed» داد، از «بررسی و تعمیر» استفاده کنید.',
                [
                    [self::btn('۱ ➕ دیتابیس جدید', 'f:db_add')],
                    [self::btn('۲ 👤 کاربر جدید', 'f:dbuser_add')],
                    [self::btn('۳ 🔗 وصل‌کردن', 'f:db_grant')],
                ],
            ],
            'email' => [
                "✉️ <b>ساخت ایمیل جدید</b>\n\n"
                . "۱️⃣ «افزودن» را بزنید، دامنه را انتخاب و بخش قبل از @ را بنویسید\n"
                . "۲️⃣ رمز بگذارید (<code>/gen</code> رمز قوی می‌سازد) و سهمیه را انتخاب کنید\n"
                . "۳️⃣ برای ورود به ایمیل، از وبمیل هاست یا هر برنامه‌ای با همان آدرس و رمز استفاده کنید\n\n"
                . '💡 اگر می‌خواهید نامه‌ها به ایمیل دیگری هم برود، «فورواردها» را ببینید.',
                [
                    [self::btn('➕ ساخت ایمیل', 'f:email_add')],
                    [self::btn('📮 فورواردها', 'a:forwarders')],
                ],
            ],
            'ssl' => [
                "🔒 <b>گرفتن SSL برای دامنه</b>\n\n"
                . "۱️⃣ مطمئن شوید دامنه به همین هاست اشاره می‌کند (رکورد A درست باشد)\n"
                . "۲️⃣ «درخواست صدور/تمدید خودکار» را بزنید تا cPanel گواهی رایگان بگیرد\n"
                . "۳️⃣ چند دقیقه بعد، «گواهی‌های نصب‌شده» را ببینید\n\n"
                . '💡 اگر دامنه تازه وصل شده، ممکن است چند ساعت طول بکشد تا صادر شود.',
                [
                    [self::btn('🧭 بررسی رکوردهای DNS', 'f:dns_list')],
                    [self::btn('🔄 درخواست AutoSSL', 'a:autossl'), self::btn('📋 گواهی‌ها', 'a:ssl')],
                ],
            ],
            'backup' => [
                "💾 <b>گرفتن بکاپ</b>\n\n"
                . "۱️⃣ «گرفتن بکاپ کامل» را بزنید؛ فایل بکاپ در پوشه‌ی هوم ساخته می‌شود\n"
                . "۲️⃣ ساختش بسته به حجم حساب چند دقیقه طول می‌کشد\n"
                . "۳️⃣ بعد از آماده‌شدن، از «بکاپ‌های موجود» ببینیدش و از فایل‌منیجر دانلودش کنید\n\n"
                . '💡 اگر ایمیل بدهید، پایان کار به شما اطلاع داده می‌شود.',
                [
                    [self::btn('💾 گرفتن بکاپ کامل', 'f:backup_run')],
                    [self::btn('📋 بکاپ‌های موجود', 'a:backups')],
                ],
            ],
            default => ['این راهنما را نمی‌شناسم.', []],
        };

        $rows[] = [self::btn('⬅️ بازگشت', 'm:howto'), self::btn('🏠 منوی اصلی', 'menu')];
        self::respond($api, $chatId, $text, ['inline_keyboard' => $rows]);
    }

    // ------------------------------------------------------- فایل‌های هاست

    /**
     * مرور فایل‌های هاست: هر پوشه یک صفحه است که همان‌جا ویرایش می‌شود.
     */
    private static function browseFiles(TelegramApi $api, int $chatId, ?string $dir = null, int $page = 0): void
    {
        $api->sendChatAction($chatId);

        try {
            $client = self::accountClient();
            $target = ($dir !== null && trim($dir) !== '')
                ? CpanelService::normalizePath($dir)
                : CpanelService::homeDir($client);
            $entries = CpanelService::listFiles($client, $target);
        } catch (Throwable $e) {
            self::respond($api, $chatId, Render::error($e), self::backKeyboard('files'));

            return;
        }

        $perPage = 8;
        $pages = max(1, (int) ceil(count($entries) / $perPage));
        $page = max(0, min($page, $pages - 1));

        $rows = [];
        foreach (array_slice($entries, $page * $perPage, $perPage, true) as $index => $entry) {
            $icon = $entry['dir'] ? '📁' : '📄';
            $rows[] = [self::btn($icon . ' ' . self::shorten($entry['name'], 40), ($entry['dir'] ? 'fb:' : 'ff:') . $index)];
        }

        $nav = [];
        if ($target !== '/' ) {
            $nav[] = self::btn('⬆️ پوشه‌ی بالاتر', 'fbu');
        }
        if ($page > 0) {
            $nav[] = self::btn('◀️ قبلی', 'fbp:' . ($page - 1));
        }
        if ($page < $pages - 1) {
            $nav[] = self::btn('بعدی ▶️', 'fbp:' . ($page + 1));
        }
        if ($nav !== []) {
            $rows[] = $nav;
        }

        $rows[] = [self::btn('⬆️ آپلود در همین پوشه', 'fup'), self::btn('🔄 تازه‌سازی', 'fbr')];
        $rows[] = [self::btn('⬅️ بازگشت', 'm:files'), self::btn('🏠 منوی اصلی', 'menu')];

        State::set($chatId, self::BROWSE, -3, [
            'dir' => $target,
            'entries' => $entries,
            'page' => $page,
            '__msg' => self::$screen,
        ]);

        $text = Render::files($target, $entries)
            . "\n\n💡 می‌توانید مسیر دلخواه را هم مستقیم تایپ کنید.";
        self::respond($api, $chatId, $text, ['inline_keyboard' => $rows]);

        $state = State::get($chatId);
        if ($state !== null) {
            State::set($chatId, self::BROWSE, -3, $state['data'] + ['__msg' => self::$screen]);
        }
    }

    /**
     * دکمه‌های داخل مرورگر فایل.
     */
    private static function handleBrowseCallback(TelegramApi $api, int $chatId, string $data): bool
    {
        $state = State::get($chatId);
        $browse = ($state !== null && $state['flow'] === self::BROWSE) ? $state['data'] : null;
        $dir = is_array($browse) ? (string) ($browse['dir'] ?? '') : '';
        $entries = is_array($browse) && is_array($browse['entries'] ?? null) ? $browse['entries'] : [];
        $page = is_array($browse) ? (int) ($browse['page'] ?? 0) : 0;

        if ($data === 'fbr') {
            self::browseFiles($api, $chatId, $dir, $page);

            return true;
        }

        if ($data === 'fbu') {
            self::browseFiles($api, $chatId, $dir === '' ? null : dirname($dir));

            return true;
        }

        if (str_starts_with($data, 'fbp:')) {
            self::browseFiles($api, $chatId, $dir, (int) substr($data, 4));

            return true;
        }

        if (str_starts_with($data, 'fb:')) {
            $entry = $entries[(int) substr($data, 3)] ?? null;
            self::browseFiles($api, $chatId, is_array($entry) ? (string) $entry['path'] : $dir);

            return true;
        }

        if (str_starts_with($data, 'ff:')) {
            $index = (int) substr($data, 3);
            $entry = $entries[$index] ?? null;
            if (!is_array($entry)) {
                self::browseFiles($api, $chatId, $dir, $page);

                return true;
            }

            $rows = [];
            if (CpanelService::isArchive((string) $entry['name'])) {
                $rows[] = [self::btn('🗜 باز کردن همین‌جا', 'fx:' . $index)];
            }
            $rows[] = [self::btn('🗑 حذف', 'fd:' . $index)];
            $rows[] = [self::btn('⬅️ بازگشت به پوشه', 'fbr')];

            self::respond(
                $api,
                $chatId,
                '📄 <code>' . Format::escapeHtml((string) $entry['path']) . '</code>'
                . ($entry['size'] !== null ? "\n💾 " . Format::bytes((float) $entry['size']) : ''),
                ['inline_keyboard' => $rows],
            );

            return true;
        }

        if (str_starts_with($data, 'fx:') || str_starts_with($data, 'fd:')) {
            $index = (int) substr($data, 3);
            $entry = $entries[$index] ?? null;
            if (!is_array($entry)) {
                self::browseFiles($api, $chatId, $dir, $page);

                return true;
            }

            $isExtract = str_starts_with($data, 'fx:');
            $flow = $isExtract ? 'file_extract' : 'file_delete';
            $payload = $isExtract
                ? ['path' => (string) $entry['path'], 'dest' => $dir]
                : ['path' => (string) $entry['path']];

            if ($isExtract) {
                State::clear($chatId);
                self::execute($api, $chatId, $flow, $payload);

                return true;
            }

            // حذف همیشه تأیید می‌خواهد.
            State::set($chatId, $flow, -1, $payload + ['__msg' => self::$screen]);
            self::respond(
                $api,
                $chatId,
                self::fillTemplate((string) self::FLOWS[$flow]['confirm'], $payload) . "\n\nادامه می‌دهید؟",
                self::confirmKeyboard(),
            );

            return true;
        }

        if ($data === 'fup') {
            // آپلود در همان پوشه‌ای که کاربر ایستاده — مرحله‌ی انتخاب مسیر رد می‌شود.
            State::set($chatId, 'file_upload', 1, ['dir' => $dir !== '' ? $dir : '', '__msg' => self::$screen]);
            self::askStep($api, $chatId, 'file_upload', 1, ['dir' => $dir]);

            return true;
        }

        return false;
    }

    /**
     * فایلی که کاربر در تلگرام فرستاده را می‌گیرد و روی هاست می‌گذارد.
     *
     * @param array<string, mixed> $document
     */
    private static function handleDocument(TelegramApi $api, int $chatId, array $document): void
    {
        $state = State::get($chatId);
        $step = null;
        if ($state !== null && isset(self::FLOWS[$state['flow']]) && $state['step'] >= 0) {
            $step = self::FLOWS[$state['flow']]['steps'][$state['step']] ?? null;
        }

        if (($step['file'] ?? false) !== true) {
            self::respond(
                $api,
                $chatId,
                "📎 فایل را وقتی بفرستید که ربات ازتان خواسته باشد.\n\nبرای آپلود: «📂 فایل‌ها → ⬆️ آپلود فایل».",
                self::mainKeyboard(),
            );

            return;
        }

        $name = trim((string) ($document['file_name'] ?? 'upload.bin'));
        $size = (int) ($document['file_size'] ?? 0);
        $dir = (string) ($state['data']['dir'] ?? '');

        if ($size > self::MAX_UPLOAD) {
            self::respond(
                $api,
                $chatId,
                '⚠️ حجم فایل ' . Format::bytes((float) $size) . ' است و تلگرام اجازه‌ی دانلود بیشتر از '
                . Format::bytes(self::MAX_UPLOAD) . " را به ربات نمی‌دهد.\n\nفایل را کوچک‌تر یا تکه‌تکه کنید.",
                self::backKeyboard('files'),
            );

            return;
        }

        $api->sendChatAction($chatId, 'upload_document');

        try {
            $file = $api->getFile((string) ($document['file_id'] ?? ''));
            $contents = $api->downloadFile($file['file_path']);
            CpanelService::uploadFile(self::accountClient(), $dir, $name, $contents);
        } catch (Throwable $e) {
            AuditLog::record(self::$actor, (string) (ServerStore::activeId() ?? '-'), 'file_upload', ['dir' => $dir, 'name' => $name, 'error' => $e->getMessage()], false);
            self::respond($api, $chatId, Render::error($e), self::backKeyboard('files'));

            return;
        }

        State::clear($chatId);
        AuditLog::record(self::$actor, (string) (ServerStore::activeId() ?? '-'), 'file_upload', ['dir' => $dir, 'name' => $name, 'size' => $size]);

        $path = rtrim($dir, '/') . '/' . $name;
        $text = '✅ آپلود شد: <code>' . Format::escapeHtml($path) . '</code>' . "\n💾 " . Format::bytes((float) $size);

        $rows = [];
        if (CpanelService::isArchive($name)) {
            $text .= "\n\n🗜 این یک آرشیو است؛ می‌توانید همین‌جا بازش کنید.";
            State::set($chatId, self::BROWSE, -3, ['dir' => $dir, 'entries' => [['name' => $name, 'path' => $path, 'dir' => false, 'size' => $size, 'mtime' => null]], 'page' => 0]);
            $rows[] = [self::btn('🗜 باز کردن همین آرشیو', 'fx:0')];
        }
        $rows[] = [self::btn('🗂 مرور پوشه', 'fbr'), self::btn('⬅️ بازگشت', 'm:files')];

        self::respond($api, $chatId, $text, ['inline_keyboard' => $rows]);
    }

    private static function runAutoSslAction(): string
    {
        CpanelService::runAutoSsl(self::accountClient());
        AuditLog::record(self::$actor, (string) (ServerStore::activeId() ?? '-'), 'autossl', []);

        return "🔄 درخواست صدور/تمدید خودکار گواهی فرستاده شد.\n\n"
            . 'چند دقیقه تا چند ساعت طول می‌کشد؛ بعد از «گواهی‌های نصب‌شده» نتیجه را ببینید.';
    }

    /**
     * هر «کار» زیرمجموعه‌ی کدام منوست — برای اینکه دکمه‌ی بازگشت درست باشد.
     */
    private static function sectionOf(string $action): ?string
    {
        return match ($action) {
            'emails', 'forwarders' => 'email',
            'dbs' => 'db',
            'domains', 'redirects' => 'dom',
            'ssl', 'autossl' => 'ssl',
            'mysqlhosts' => 'db',
            'files' => 'files',
            'ftps' => 'ftp',
            'crons' => 'cron',
            'php' => 'php',
            'backups' => 'backup',
            'whmaccts', 'whmstatus', 'whmpkgs' => 'whm',
            'servers', 'ping' => 'srv',
            'admins' => 'adm',
            default => null,
        };
    }

    // -------------------------------------------------------------- جریان‌ها

    private static function startFlow(TelegramApi $api, int $chatId, string $flow): void
    {
        if (!isset(self::FLOWS[$flow])) {
            self::respond($api, $chatId, 'این عملیات را نمی‌شناسم.', self::mainKeyboard());

            return;
        }

        // خطاهای پیکربندی (سرور نداریم، کاربر مقصد ست نشده و ...) رو همین اول
        // می‌گیریم تا کاربر بعد از پر کردن ۴ مرحله به دیوار نخوره.
        try {
            self::clientFor($flow);
        } catch (Throwable $e) {
            self::respond($api, $chatId, Render::error($e), self::mainKeyboard());

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

        self::respond($api, $chatId, $text, self::optionsKeyboard($options, $page, $optional));

        // شناسه‌ی صفحه را نگه می‌داریم تا مرحله‌ی بعد هم همین پیام ویرایش شود.
        $data['__msg'] = self::$screen;
        State::set($chatId, $flow, $step, $data);
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

            case 'dirs':
                $client = self::accountClient();
                $home = CpanelService::homeDir($client);
                $dirs = [$home, $home . '/public_html'];
                foreach (CpanelService::domains($client)['addon_domains'] as $addon) {
                    $dirs[] = $home . '/' . $addon;
                }

                return $simple(array_values(array_unique($dirs)));

            case 'mysql_hosts':
                return $simple(CpanelService::remoteMysqlHosts(self::accountClient()));

            case 'redirects':
                $options = [];
                foreach (CpanelService::redirects(self::accountClient()) as $redirect) {
                    if ($redirect['source'] === '') {
                        continue;
                    }
                    $options[] = [
                        'label' => $redirect['source'] . ' → ' . $redirect['destination'],
                        'value' => $redirect['source'],
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
            self::respond($api, $chatId, 'این دکمه دیگر معتبر نیست؛ از منو دوباره شروع کنید.', self::mainKeyboard());

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

        if (($stepDefinition['file'] ?? false) === true) {
            $api->sendHtml($chatId, '📎 این مرحله فایل می‌خواهد؛ خود فایل را به‌صورت Document بفرستید (یا <code>/cancel</code>).');

            return;
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
            self::respond($api, $chatId, self::fillTemplate((string) $definition['confirm'], $data) . "\n\nادامه می‌دهید؟", self::confirmKeyboard());

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

        self::respond($api, $chatId, $text, self::backKeyboard(self::FLOWS[$flow]['back'] ?? null));
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

    /**
     * آپلود با فرستادن خود فایل کامل می‌شود (handleDocument)؛ این فقط برای
     * حالتی است که کسی جریان را جور دیگری تمام کند.
     *
     * @param array<string, mixed> $data
     */
    private static function runFileUpload(array $data): string
    {
        return '📎 برای آپلود، خود فایل را به‌صورت Document در همین گفت‌وگو بفرستید.';
    }

    /** @param array<string, mixed> $data */
    private static function runDbPasswd(array $data): string
    {
        CpanelService::setDatabaseUserPassword(self::accountClient(), (string) $data['user'], (string) $data['password']);

        return '✅ رمز کاربر <code>' . Format::escapeHtml((string) $data['user']) . '</code> عوض شد.'
            . "\n\n💡 یادتان باشد رمز جدید را در فایل تنظیمات سایت هم بگذارید.";
    }

    /** @param array<string, mixed> $data */
    private static function runDbRename(array $data): string
    {
        CpanelService::renameDatabase(self::accountClient(), (string) $data['name'], (string) $data['newname']);

        return '✅ دیتابیس حالا <code>' . Format::escapeHtml((string) $data['newname']) . '</code> است.';
    }

    /** @param array<string, mixed> $data */
    private static function runDbuserRename(array $data): string
    {
        CpanelService::renameDatabaseUser(self::accountClient(), (string) $data['name'], (string) $data['newname']);

        return '✅ کاربر دیتابیس حالا <code>' . Format::escapeHtml((string) $data['newname']) . '</code> است.';
    }

    /** @param array<string, mixed> $data */
    private static function runDbRepair(array $data): string
    {
        $repair = (string) ($data['mode'] ?? 'repair') !== 'check';
        $result = CpanelService::repairDatabase(self::accountClient(), (string) $data['name'], $repair);

        return ($repair ? '🛠 تعمیر' : '🔍 بررسی') . ' <code>' . Format::escapeHtml((string) $data['name']) . '</code> انجام شد.'
            . ($result !== '' ? "\n\n<code>" . Format::escapeHtml(mb_substr($result, 0, 500)) . '</code>' : '');
    }

    /** @param array<string, mixed> $data */
    private static function runDbRevoke(array $data): string
    {
        CpanelService::revokeDatabaseUser(self::accountClient(), (string) $data['user'], (string) $data['database']);

        return '🚫 دسترسی <code>' . Format::escapeHtml((string) $data['user']) . '</code> از <code>'
            . Format::escapeHtml((string) $data['database']) . '</code> برداشته شد.';
    }

    /** @param array<string, mixed> $data */
    private static function runMysqlHostAdd(array $data): string
    {
        CpanelService::addRemoteMysqlHost(self::accountClient(), (string) $data['host']);

        return '✅ <code>' . Format::escapeHtml((string) $data['host']) . '</code> حالا می‌تواند از راه دور به MySQL وصل شود.';
    }

    /** @param array<string, mixed> $data */
    private static function runMysqlHostDel(array $data): string
    {
        CpanelService::deleteRemoteMysqlHost(self::accountClient(), (string) $data['host']);

        return '🗑 دسترسی از راه دور <code>' . Format::escapeHtml((string) $data['host']) . '</code> حذف شد.';
    }

    /** @param array<string, mixed> $data */
    private static function runRedirectAdd(array $data): string
    {
        CpanelService::addRedirect(
            self::accountClient(),
            (string) $data['domain'],
            (string) $data['destination'],
            (string) ($data['type'] ?? 'permanent'),
        );

        return '✅ <code>' . Format::escapeHtml((string) $data['domain']) . '</code> به <code>'
            . Format::escapeHtml((string) $data['destination']) . '</code> ریدایرکت شد.';
    }

    /** @param array<string, mixed> $data */
    private static function runRedirectDel(array $data): string
    {
        CpanelService::deleteRedirect(self::accountClient(), (string) $data['source']);

        return '🗑 ریدایرکت حذف شد.';
    }

    /** @param array<string, mixed> $data */
    private static function runFileExtract(array $data): string
    {
        CpanelService::extractArchive(self::accountClient(), (string) $data['path'], (string) ($data['dest'] ?? ''));

        return '✅ آرشیو باز شد: <code>' . Format::escapeHtml((string) $data['path']) . '</code>';
    }

    /** @param array<string, mixed> $data */
    private static function runFileMkdir(array $data): string
    {
        CpanelService::makeDirectory(self::accountClient(), (string) $data['dir'], (string) $data['name']);

        return '✅ پوشه ساخته شد: <code>' . Format::escapeHtml($data['dir'] . '/' . $data['name']) . '</code>';
    }

    /** @param array<string, mixed> $data */
    private static function runFileDelete(array $data): string
    {
        CpanelService::deletePath(self::accountClient(), (string) $data['path']);

        return '🗑 حذف شد: <code>' . Format::escapeHtml((string) $data['path']) . '</code>';
    }

    /** @param array<string, mixed> $data */
    private static function runFwdAdd(array $data): string
    {
        CpanelService::addForwarder(self::accountClient(), (string) $data['address'], (string) $data['destination']);

        return '✅ از این به بعد ایمیل‌های <code>' . Format::escapeHtml((string) $data['address'])
            . '</code> به <code>' . Format::escapeHtml((string) $data['destination']) . '</code> هم می‌رسد.';
    }

    /** @param array<string, mixed> $data */
    private static function runFwdDel(array $data): string
    {
        CpanelService::deleteForwarder(self::accountClient(), (string) $data['address'], (string) $data['destination']);

        return '🗑 فوروارد حذف شد.';
    }

    /** @param array<string, mixed> $data */
    private static function runCronEmail(array $data): string
    {
        $email = trim((string) $data['email']);
        CpanelService::setCronEmail(self::accountClient(), $email);

        return $email === ''
            ? '✅ ایمیل خروجی کران خاموش شد.'
            : '✅ خروجی کران‌ها به <code>' . Format::escapeHtml($email) . '</code> فرستاده می‌شود.';
    }

    /** @param array<string, mixed> $data */
    private static function runWhmPackage(array $data): string
    {
        CpanelService::whmChangePackage(self::whmClient(), (string) $data['user'], (string) $data['package']);

        return '✅ پلن حساب <code>' . Format::escapeHtml((string) $data['user']) . '</code> شد <b>'
            . Format::escapeHtml((string) $data['package']) . '</b>.';
    }

    /** @param array<string, mixed> $data */
    private static function runWhmService(array $data): string
    {
        CpanelService::whmRestartService(self::whmClient(), (string) $data['service']);

        return '♻️ دستور ری‌استارت <code>' . Format::escapeHtml((string) $data['service']) . '</code> فرستاده شد.';
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

    /**
     * صفحه‌ی «قابلیت‌ها» — دقیقاً همان چیزهایی که روی همین سرور در دسترس‌اند.
     */
    private static function features(): string
    {
        $context = self::context();

        if ($context['state'] === 'none') {
            return "✨ <b>این ربات چه کار می‌کند؟</b>\n\n"
                . "به هاست یا سرور شما از طریق API رسمی cPanel/WHM وصل می‌شود و کارهای مدیریتی را از تلگرام انجام می‌دهد.\n\n"
                . "اول یک هاست اضافه کنید: «⚙️ سرورها → ➕ افزودن سرور».\n"
                . 'برای هاست اشتراکی توکن را از cPanel → Security → Manage API Tokens، و برای کل سرور از WHM → Development → Manage API Tokens بسازید.';
        }

        $account = "📊 <b>خلاصه‌ی حساب</b> — مصرف دیسک، پهنای‌باند، اینود و تعداد ایمیل/دیتابیس/دامنه\n"
            . "✉️ <b>ایمیل</b> — ساخت، حذف، رمز، سهمیه و فوروارد\n"
            . "🗄 <b>دیتابیس</b> — دیتابیس و کاربر MySQL و دادن دسترسی (سه قدم ساده)\n"
            . "📂 <b>فایل‌ها</b> — مرور پوشه‌ها، آپلود سورس از تلگرام، باز کردن zip، ساخت پوشه و حذف\n"
            . "🌐 <b>دامنه‌ها</b> — ادان‌دامنه و ساب‌دامنه، 🧭 رکوردهای DNS، 🔒 گواهی‌های SSL\n"
            . "📁 <b>FTP</b> — حساب‌های انتقال فایل\n"
            . "⏰ <b>کران</b> — کارهای زمان‌بندی‌شده و ایمیل خروجی‌شان\n"
            . "🐘 <b>PHP</b> — نسخه‌ی هر دامنه\n"
            . '💾 <b>بکاپ</b> — ساخت بکاپ کامل و دیدن بکاپ‌های موجود';

        $whm = "🖥 <b>سطح سرور (WHM)</b>\n"
            . "👥 حساب‌ها، 🆕 ساخت حساب، ⛔️ تعلیق/رفع تعلیق، 💀 حذف حساب\n"
            . "🔑 تغییر رمز حساب، 📦 تغییر پلن، 🔐 لینک ورود یک‌بارمصرف به cPanel کاربر\n"
            . '📈 بار سیستم و وضعیت سرویس‌ها، ♻️ ری‌استارت سرویس';

        return match ($context['state']) {
            'cpanel' => "✨ <b>قابلیت‌های این هاست (cPanel)</b>\n\n" . $account
                . "\n\n💡 کارهای سطح سرور (ساخت/تعلیق حساب، ری‌استارت سرویس) فقط با توکن WHM ممکن است؛ اگر دسترسی WHM دارید، یک سرور از نوع WHM اضافه کنید.",
            'whm' => "✨ <b>قابلیت‌های این سرور (WHM)</b>\n\n" . $whm
                . "\n\n💡 برای کارهای داخل یک حساب (ایمیل، دیتابیس، فایل و ...) اول «🎯 انتخاب کاربر مقصد» را بزنید.",
            default => "✨ <b>قابلیت‌ها</b>\n\n" . $whm . "\n\n" . $account
                . "\n\n🎯 کارهای سطح حساب روی <code>" . Format::escapeHtml($context['target']) . '</code> انجام می‌شود.',
        };
    }

    private static function welcome(): string
    {
        $context = self::context();

        if ($context['state'] === 'none') {
            return "🛠 <b>cPanel TeamKan</b>\n\n"
                . "این ربات از طریق <b>API رسمی cPanel/WHM</b> به هاست شما وصل می‌شود و کارهای مدیریتی را از تلگرام انجام می‌دهد.\n\n"
                . "<b>برای شروع، یک هاست اضافه کنید:</b>\n"
                . "«⚙️ افزودن سرور / هاست» را بزنید و اسم، نوع (cPanel یا WHM)، آدرس، نام کاربری و API Token را بدهید.\n\n"
                . "🔑 توکن را از این مسیرها بسازید:\n"
                . "• هاست اشتراکی: cPanel → Security → Manage API Tokens\n"
                . "• کل سرور: WHM → Development → Manage API Tokens\n\n"
                . 'بعد از افزودن هاست، بقیه‌ی دکمه‌ها خودشان ظاهر می‌شوند.';
        }

        $lines = ["🛠 <b>cPanel TeamKan</b>", ''];
        $lines[] = $context['is_whm']
            ? 'این سرور با توکن <b>WHM</b> وصل است، پس کارهای سطح سرور (حساب‌ها، پلن‌ها، سرویس‌ها) در دسترس است.'
            : 'این هاست با توکن <b>cPanel</b> وصل است، پس کارهای همان حساب (ایمیل، دیتابیس، فایل، دامنه و ...) در دسترس است.';

        if ($context['state'] === 'whm') {
            $lines[] = '';
            $lines[] = '🎯 برای کارهای داخل یک حساب، اول «انتخاب کاربر مقصد» را بزنید.';
        }

        $lines[] = '';
        $lines[] = '✨ برای دیدن فهرست کامل کارهایی که می‌شود کرد، «قابلیت‌ها» را بزنید.';
        $lines[] = '';
        $lines[] = '<b>دستورهای سریع:</b> /panel /summary /emails /db /files /domains /ftp /cron /dns /ssl /php /backup /whm /servers /admins /ping /log /cancel';

        return implode("\n", $lines);
    }
}
