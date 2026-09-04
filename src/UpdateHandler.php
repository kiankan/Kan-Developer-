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

    /** حداکثر حجمی که ربات می‌تواند برای کاربر بفرستد. */
    private const MAX_DOWNLOAD = 45 * 1024 * 1024;

    /**
     * فایلی که عملیات ساخته و باید بعد از پیام نتیجه فرستاده شود.
     *
     * @var array{name: string, content: string}|null
     */
    private static ?array $pendingFile = null;

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
        'email_bulk_add' => [
            'title' => '👥 ساخت گروهی ایمیل',
            'scope' => 'account',
            'back' => 'email',
            'steps' => [
                ['key' => 'domain', 'prompt' => 'روی کدام دامنه ساخته شوند؟', 'picker' => 'domains'],
                ['key' => 'list', 'prompt' => "فهرست را بفرستید، هر خط یک ایمیل:\n\n<code>info\nsales rmz-delkhah\nsupport</code>\n\nاگر جلوی نام رمز ننویسید، خودم یک رمز قوی می‌سازم و نشانتان می‌دهم."],
                ['key' => 'quota', 'prompt' => 'سهمیه‌ی هر صندوق:', 'optional' => true, 'default' => '0', 'choices' => self::QUOTA_CHOICES],
            ],
        ],
        'email_bulk_del' => [
            'title' => '🗑 حذف گروهی ایمیل',
            'scope' => 'account',
            'back' => 'email',
            'confirm' => "⚠️ همه‌ی صندوق‌های زیر و پیام‌هایشان برای همیشه حذف می‌شوند:\n<code>{list}</code>",
            'steps' => [
                ['key' => 'list', 'prompt' => "آدرس‌های کامل را بفرستید، هر خط یکی:\n\n<code>old@example.com\ntest@example.com</code>"],
            ],
        ],
        'mail_purge' => [
            'title' => '🧽 پاک‌سازی سطل زباله و اسپم',
            'scope' => 'account',
            'back' => 'email',
            'confirm' => '⚠️ نامه‌های داخل Trash و Spam صندوق <code>{email}</code> برای همیشه پاک می‌شوند (نامه‌های Inbox دست نمی‌خورند).',
            'steps' => [
                ['key' => 'email', 'prompt' => "کدام صندوق پاک‌سازی شود؟\n\n💡 وقتی صندوق پر شده و نامه‌ی جدید نمی‌گیرد، معمولاً همین Trash و Spam پرش کرده‌اند.", 'picker' => 'emails'],
            ],
        ],
        'responder_add' => [
            'title' => '🤖 تنظیم پاسخ خودکار',
            'scope' => 'account',
            'back' => 'email',
            'steps' => [
                ['key' => 'email', 'prompt' => 'برای کدام صندوق؟', 'picker' => 'emails'],
                ['key' => 'subject', 'prompt' => 'موضوع پاسخ خودکار (مثل <code>در مرخصی هستم</code>):'],
                ['key' => 'body', 'prompt' => 'متن پاسخ خودکار را بفرستید:'],
            ],
        ],
        'responder_del' => [
            'title' => '🗑 حذف پاسخ خودکار',
            'scope' => 'account',
            'back' => 'email',
            'confirm' => '⚠️ پاسخ خودکار <code>{email}</code> حذف می‌شود.',
            'steps' => [
                ['key' => 'email', 'prompt' => 'کدام پاسخ خودکار حذف شود؟', 'picker' => 'responders'],
            ],
        ],
        'email_bulk_passwd' => [
            'title' => '🔑 تغییر رمز گروهی ایمیل',
            'scope' => 'account',
            'back' => 'email',
            'steps' => [
                ['key' => 'list', 'prompt' => "هر خط یک ایمیل و رمز جدیدش:\n\n<code>info@example.com rmz-1\nsales@example.com rmz-2</code>\n\nاگر رمز ننویسید، خودم رمز قوی می‌سازم و نشانتان می‌دهم."],
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
        'addon_add' => [
            'title' => '🌍 افزودن دامنه‌ی جدید (ادان)',
            'scope' => 'account',
            'back' => 'dom',
            'steps' => [
                ['key' => 'domain', 'prompt' => "دامنه‌ی جدید را بفرستید، مثل <code>example.com</code>.\n\n💡 ادان‌دامنه یعنی یک سایت کاملاً جدا روی همین هاست، با پوشه‌ی خودش."],
                ['key' => 'dir', 'prompt' => 'پوشه‌ی سایت (خالی = <code>public_html/دامنه</code>):', 'optional' => true, 'default' => ''],
            ],
        ],
        'addon_del' => [
            'title' => '🗑 حذف دامنه‌ی ادان',
            'scope' => 'account',
            'back' => 'dom',
            'confirm' => '⚠️ دامنه‌ی <code>{domain}</code> از حساب حذف می‌شود (فایل‌های پوشه‌اش می‌مانند).',
            'steps' => [
                ['key' => 'domain', 'prompt' => 'کدام دامنه حذف شود؟', 'picker' => 'addon_domains'],
            ],
        ],
        'park_add' => [
            'title' => '🅿️ پارک کردن دامنه',
            'scope' => 'account',
            'back' => 'dom',
            'steps' => [
                ['key' => 'domain', 'prompt' => "دامنه‌ای که باید همان سایت اصلی را نشان دهد:\n\n💡 مثلاً <code>example.net</code> که می‌خواهید همان محتوای <code>example.com</code> را بدهد."],
            ],
        ],
        'dns_bulk' => [
            'title' => '🧭 افزودن رکورد روی چند دامنه',
            'scope' => 'account',
            'back' => 'dns',
            'confirm' => '⚠️ رکورد <b>{type}</b> با نام <code>{name}</code> روی همه‌ی دامنه‌های حساب اضافه می‌شود.',
            'steps' => [
                ['key' => 'type', 'prompt' => "نوع رکورد؟\n\n💡 معمول‌ترین کاربرد این کار، افزودن یک رکورد TXT تأییدیه روی همه‌ی دامنه‌هاست.", 'picker' => 'record_types'],
                ['key' => 'name', 'prompt' => 'نام رکورد (<code>@</code> برای خود دامنه):'],
                ['key' => 'value', 'prompt' => 'مقدار رکورد:'],
            ],
        ],
        'dns_fix' => [
            'title' => '🛠 اصلاح رکورد A دامنه',
            'scope' => 'account',
            'back' => 'dns',
            'confirm' => '⚠️ رکورد A برای <code>{name}</code> روی <code>{ip}</code> تنظیم می‌شود (رکورد قبلی جایگزین می‌شود).',
            'steps' => [
                ['key' => 'zone', 'prompt' => 'کدام دامنه؟', 'picker' => 'zones'],
                ['key' => 'name', 'prompt' => "کدام نام؟ (<code>@</code> برای خود دامنه، <code>www</code> برای نسخه‌ی www)"],
                ['key' => 'ip', 'prompt' => "آی‌پی مقصد را بفرستید.\n\n💡 آی‌پی هاست را در «دامنه‌ها → لیست دامنه‌ها» می‌بینید."],
            ],
        ],
        'mail_records' => [
            'title' => '📬 بررسی رکوردهای ایمیل دامنه',
            'scope' => 'account',
            'back' => 'email',
            'steps' => [
                ['key' => 'domain', 'prompt' => "کدام دامنه بررسی شود؟\n\nMX، SPF، DKIM و DMARC را از بیرون می‌خوانم و می‌گویم کدام کم است.", 'picker' => 'domains'],
            ],
        ],
        'watch_domains' => [
            'title' => '👁 دامنه‌های تحت‌نظر',
            'scope' => 'local',
            'back' => 'alerts',
            'steps' => [
                ['key' => 'list', 'prompt' => "دامنه‌هایی که باید بالا بودنشان چک شود را بفرستید (هر خط یکی).\n\nخالی بفرستید (<code>-</code>) تا خودکار دامنه‌ی اصلی و ادان‌ها بررسی شوند."],
            ],
        ],
        'backup_schedule' => [
            'title' => '🗓 بکاپ خودکار',
            'scope' => 'local',
            'back' => 'alerts',
            'steps' => [
                ['key' => 'every', 'prompt' => 'هر چند وقت یک‌بار بکاپ کامل گرفته شود؟', 'choices' => [
                    ['🗓 روزانه', 'daily'],
                    ['📅 هفتگی', 'weekly'],
                    ['⛔️ خاموش', 'off'],
                ]],
                ['key' => 'keep', 'prompt' => 'چند نسخه‌ی آخر نگه داشته شود؟ (بقیه خودکار پاک می‌شوند)', 'choices' => [
                    ['۲ نسخه', '2'],
                    ['۳ نسخه', '3'],
                    ['۵ نسخه', '5'],
                    ['همه (بدون پاک‌سازی)', '0'],
                ]],
            ],
        ],
        'guest_invite' => [
            'title' => '⏳ دسترسی موقت',
            'scope' => 'local',
            'back' => 'adm',
            'steps' => [
                ['key' => 'hours', 'prompt' => "دسترسی موقت چند ساعت اعتبار داشته باشد؟\n\n💡 کدی می‌سازم که بدهید به توسعه‌دهنده یا پشتیبان؛ بعد از این مدت خودش بی‌اثر می‌شود.", 'choices' => [
                    ['۲ ساعت', '2'],
                    ['۸ ساعت', '8'],
                    ['۲۴ ساعت', '24'],
                    ['۷۲ ساعت', '72'],
                ]],
            ],
        ],
        'whm_bulk' => [
            'title' => '👥 تعلیق/رفع تعلیق گروهی',
            'scope' => 'whm',
            'back' => 'whm',
            'confirm' => "⚠️ عملیات روی این حساب‌ها انجام می‌شود:\n<code>{users}</code>",
            'steps' => [
                ['key' => 'users', 'prompt' => "نام کاربری حساب‌ها را بفرستید، هر خط یکی:"],
                ['key' => 'state', 'prompt' => 'کدام کار؟', 'choices' => [
                    ['⛔️ تعلیق همه', '1'],
                    ['✅ رفع تعلیق همه', '0'],
                ]],
                ['key' => 'reason', 'prompt' => 'دلیل (اختیاری، فقط برای تعلیق):', 'optional' => true, 'default' => ''],
            ],
        ],
        'export_csv' => [
            'title' => '📤 خروجی CSV',
            'scope' => 'account',
            'back' => 'menu',
            'steps' => [
                ['key' => 'kind', 'prompt' => 'از چه چیزی خروجی می‌خواهید؟', 'choices' => [
                    ['✉️ ایمیل‌ها', 'emails'],
                    ['🗄 دیتابیس‌ها', 'databases'],
                    ['🌐 دامنه‌ها', 'domains'],
                    ['📁 حساب‌های FTP', 'ftp'],
                ]],
            ],
        ],
        'alert_threshold' => [
            'title' => '🔔 آستانه‌ی هشدارها',
            'scope' => 'local',
            'back' => 'alerts',
            'steps' => [
                ['key' => 'kind', 'prompt' => 'کدام هشدار؟', 'choices' => [
                    ['💽 دیسک', Settings::ALERT_DISK],
                    ['🔢 اینود', Settings::ALERT_INODE],
                    ['🔒 روزهای مانده تا انقضای SSL', Settings::ALERT_SSL_DAYS],
                ]],
                ['key' => 'value', 'prompt' => "عدد جدید را بفرستید.\n\nبرای دیسک و اینود درصد (مثل <code>85</code>) و برای SSL تعداد روز (مثل <code>14</code>). عدد <code>0</code> یعنی این هشدار خاموش باشد."],
            ],
        ],
        'search' => [
            'title' => '🔍 جست‌وجو',
            'scope' => 'account',
            'back' => 'menu',
            'steps' => [
                ['key' => 'term' , 'prompt' => "چه چیزی را می‌گردید؟\n\nبخشی از نام ایمیل، دیتابیس، دامنه، حساب FTP یا فایل را بفرستید."],
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
        'ftp_passwd' => [
            'title' => '🔑 تغییر رمز FTP',
            'scope' => 'account',
            'back' => 'ftp',
            'steps' => [
                ['key' => 'user', 'prompt' => 'کدام حساب FTP؟', 'picker' => 'ftp'],
                ['key' => 'password', 'prompt' => 'رمز جدید (یا <code>/gen</code>):', 'secret' => true],
            ],
        ],
        'ftp_quota' => [
            'title' => '📦 تغییر سهمیه FTP',
            'scope' => 'account',
            'back' => 'ftp',
            'steps' => [
                ['key' => 'user', 'prompt' => 'کدام حساب FTP؟', 'picker' => 'ftp'],
                ['key' => 'quota', 'prompt' => 'سهمیه‌ی جدید:', 'choices' => self::QUOTA_CHOICES],
            ],
        ],
        'ftp_kill' => [
            'title' => '✂️ قطع نشست FTP',
            'scope' => 'account',
            'back' => 'ftp',
            'confirm' => '⚠️ نشست باز <code>{session}</code> قطع می‌شود؛ اگر وسط آپلود باشد، ناقص می‌ماند.',
            'steps' => [
                ['key' => 'session', 'prompt' => 'کدام نشست قطع شود؟', 'picker' => 'ftp_sessions'],
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
        'site_check' => [
            'title' => '🩺 بررسی سلامت دامنه',
            'scope' => 'account',
            'back' => 'dom',
            'steps' => [
                ['key' => 'domain', 'prompt' => "کدام دامنه بررسی شود؟\n\nربات از بیرون سرور صدایش می‌زند و DNS، ریدایرکت، کد پاسخ، سرعت و گواهی SSL را می‌بیند.", 'picker' => 'domains'],
            ],
        ],
        'email_settings' => [
            'title' => '📱 تنظیمات IMAP/SMTP',
            'scope' => 'account',
            'back' => 'email',
            'steps' => [
                ['key' => 'email', 'prompt' => 'تنظیمات کدام صندوق را می‌خواهید؟', 'picker' => 'emails'],
            ],
        ],
        'force_https' => [
            'title' => '🔐 اجبار HTTPS',
            'scope' => 'account',
            'back' => 'ssl',
            'steps' => [
                ['key' => 'domain', 'prompt' => "برای کدام دامنه؟\n\nبا روشن‌کردنش، هر کسی سایت را با <code>http</code> باز کند خودکار به <code>https</code> منتقل می‌شود.", 'picker' => 'domains'],
                ['key' => 'state', 'prompt' => 'روشن یا خاموش؟', 'choices' => [
                    ['✅ روشن (همیشه HTTPS)', '1'],
                    ['⛔️ خاموش', '0'],
                ]],
            ],
        ],
        'cleanup_run' => [
            'title' => '🧹 پاک‌سازی',
            'scope' => 'account',
            'back' => 'files',
            'confirm' => '⚠️ محتوای <code>{path}</code> حذف می‌شود. این کار برگشت‌پذیر نیست.',
            'steps' => [
                ['key' => 'path', 'prompt' => 'کدام مورد پاک شود؟', 'picker' => 'cleanup'],
            ],
        ],
        'php_limits' => [
            'title' => '⚙️ محدودیت‌های PHP',
            'scope' => 'account',
            'back' => 'php',
            'steps' => [
                ['key' => 'vhost', 'prompt' => "کدام دامنه؟\n\n(حافظه، حجم مجاز آپلود، زمان اجرا و ... را نشان می‌دهد.)", 'picker' => 'vhosts'],
            ],
        ],
        'cron_edit' => [
            'title' => '✏️ ویرایش کران‌جاب',
            'scope' => 'account',
            'back' => 'cron',
            'steps' => [
                ['key' => 'key', 'prompt' => 'کدام کران ویرایش شود؟', 'picker' => 'cron'],
                ['key' => 'schedule', 'prompt' => 'زمان‌بندی جدید:', 'choices' => [
                    ['هر ۵ دقیقه', '*/5 * * * *'],
                    ['هر ۱۵ دقیقه', '*/15 * * * *'],
                    ['هر ساعت', '0 * * * *'],
                    ['هر ۶ ساعت', '0 */6 * * *'],
                    ['هر شب ساعت ۰۰:۰۰', '0 0 * * *'],
                ]],
                ['key' => 'command', 'prompt' => 'دستور جدید (همان دستور قبلی را هم می‌توانید دوباره بفرستید):'],
            ],
        ],
        'file_rename' => [
            'title' => '✏️ تغییر نام فایل/پوشه',
            'scope' => 'account',
            'back' => 'files',
            'steps' => [
                ['key' => 'path', 'prompt' => 'مسیر کامل فایل یا پوشه:'],
                ['key' => 'name', 'prompt' => 'نام جدید (فقط نام، بدون مسیر):'],
            ],
        ],
        'file_move' => [
            'title' => '📦 جابه‌جایی یا کپی',
            'scope' => 'account',
            'back' => 'files',
            'steps' => [
                ['key' => 'path', 'prompt' => 'مسیر کامل فایل یا پوشه‌ی مبدأ:'],
                ['key' => 'destination', 'prompt' => 'مسیر پوشه‌ی مقصد:', 'picker' => 'dirs'],
                ['key' => 'mode', 'prompt' => 'کدام کار؟', 'choices' => [
                    ['📦 جابه‌جایی (Move)', 'move'],
                    ['📄 کپی (Copy)', 'copy'],
                ]],
            ],
        ],
        'file_chmod' => [
            'title' => '🔐 تغییر سطح دسترسی',
            'scope' => 'account',
            'back' => 'files',
            'steps' => [
                ['key' => 'path', 'prompt' => 'مسیر کامل فایل یا پوشه:'],
                ['key' => 'permissions', 'prompt' => "سطح دسترسی؟\n\n۶۴۴ برای فایل‌ها و ۷۵۵ برای پوشه‌ها استاندارد است. ۷۷۷ ناامن است و اکثر هاست‌ها هم قبولش نمی‌کنند.", 'choices' => [
                    ['📄 644 (فایل)', '644'],
                    ['📁 755 (پوشه)', '755'],
                    ['🔒 600 (فقط خودم)', '600'],
                    ['🔓 775', '775'],
                ]],
            ],
        ],
        'file_compress' => [
            'title' => '🗜 فشرده‌سازی (zip)',
            'scope' => 'account',
            'back' => 'files',
            'steps' => [
                ['key' => 'path', 'prompt' => "مسیر پوشه یا فایلی که باید zip شود:\n\n💡 قبل از تغییرات مهم، یک zip از پوشه‌ی سایت بگیرید تا راه برگشت داشته باشید."],
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
        'file_download' => [
            'title' => '⬇️ دانلود فایل از هاست',
            'scope' => 'account',
            'back' => 'files',
            'steps' => [
                ['key' => 'path', 'prompt' => "مسیر کامل فایل را بفرستید، مثل:\n<code>/home/user/public_html/index.php</code>\n\n💡 از «🗂 مرور فایل‌ها» هم می‌توانید روی فایل بزنید و دکمه‌ی دانلود را ببینید."],
            ],
        ],
        'folder_download' => [
            'title' => '⬇️ دانلود پوشه (zip)',
            'scope' => 'account',
            'back' => 'files',
            'steps' => [
                ['key' => 'path', 'prompt' => "مسیر پوشه‌ای که می‌خواهید zip شود و برایتان بیاید:", 'picker' => 'dirs'],
            ],
        ],
        'file_edit' => [
            'title' => '📝 ویرایش فایل متنی',
            'scope' => 'account',
            'back' => 'files',
            'steps' => [
                ['key' => 'path', 'prompt' => "مسیر کامل فایل متنی (مثل <code>.htaccess</code> یا <code>wp-config.php</code>):"],
                ['key' => 'content', 'prompt' => "محتوای جدید فایل را بفرستید.\n\n⚠️ محتوای فعلی کامل جایگزین می‌شود — اگر «بکاپ قبل از تغییر» روشن باشد، اول یک نسخه‌ی zip گرفته می‌شود."],
            ],
        ],
        'file_create' => [
            'title' => '📄 ساخت فایل جدید',
            'scope' => 'account',
            'back' => 'files',
            'steps' => [
                ['key' => 'dir', 'prompt' => 'داخل کدام پوشه ساخته شود؟', 'picker' => 'dirs'],
                ['key' => 'name', 'prompt' => 'نام فایل (مثل <code>robots.txt</code>):'],
                ['key' => 'content', 'prompt' => 'محتوای فایل را بفرستید (یا <code>/skip</code> برای فایل خالی):', 'optional' => true, 'default' => ''],
            ],
        ],
        'file_find' => [
            'title' => '🔎 پیدا کردن فایل',
            'scope' => 'account',
            'back' => 'files',
            'steps' => [
                ['key' => 'dir', 'prompt' => 'از کدام پوشه شروع کنم؟', 'picker' => 'dirs'],
                ['key' => 'needle', 'prompt' => "بخشی از نام فایل را بفرستید، مثل <code>config</code> یا <code>.env</code>."],
            ],
        ],
        'file_bulk_delete' => [
            'title' => '🗑 حذف گروهی فایل',
            'scope' => 'account',
            'back' => 'files',
            'confirm' => "⚠️ همه‌ی مسیرهای زیر حذف می‌شوند:\n<code>{paths}</code>",
            'steps' => [
                ['key' => 'paths', 'prompt' => "مسیرهای کامل را بفرستید، هر خط یکی:\n\n<code>/home/user/public_html/old.php\n/home/user/public_html/tmp</code>"],
            ],
        ],
        'file_bulk_move' => [
            'title' => '📦 جابه‌جایی گروهی',
            'scope' => 'account',
            'back' => 'files',
            'steps' => [
                ['key' => 'paths', 'prompt' => 'مسیرهای مبدأ را بفرستید، هر خط یکی:'],
                ['key' => 'destination', 'prompt' => 'همه به کدام پوشه بروند؟', 'picker' => 'dirs'],
            ],
        ],
        'maintenance' => [
            'title' => '🛠 حالت تعمیر سایت',
            'scope' => 'account',
            'back' => 'dom',
            'steps' => [
                ['key' => 'docroot', 'prompt' => "پوشه‌ی سایت کدام است؟\n\n(حالت تعمیر با یک <code>.htaccess</code> انجام می‌شود؛ فایل قبلی کنار گذاشته می‌شود تا موقع خاموش‌کردن دقیقاً برگردد.)", 'picker' => 'dirs'],
                ['key' => 'state', 'prompt' => 'روشن یا خاموش؟', 'choices' => [
                    ['🛠 روشن (سایت در دست تعمیر)', '1'],
                    ['✅ خاموش (سایت عادی)', '0'],
                ]],
                ['key' => 'message', 'prompt' => 'متن صفحه‌ی تعمیر (اختیاری):', 'optional' => true, 'default' => ''],
            ],
        ],
        'ip_block' => [
            'title' => '⛔️ مسدودکردن آی‌پی',
            'scope' => 'account',
            'back' => 'dom',
            'steps' => [
                ['key' => 'docroot', 'prompt' => 'روی کدام پوشه‌ی سایت اعمال شود؟', 'picker' => 'dirs'],
                ['key' => 'ip', 'prompt' => "آی‌پی را بفرستید، مثل <code>203.0.113.10</code>.\n\n💡 برای یک بازه هم می‌شود <code>203.0.113</code> نوشت."],
                ['key' => 'state', 'prompt' => 'مسدود شود یا آزاد؟', 'choices' => [
                    ['⛔️ مسدود کن', '1'],
                    ['✅ آزاد کن', '0'],
                ]],
            ],
        ],
        'dir_protect' => [
            'title' => '🔐 رمز گذاشتن روی پوشه',
            'scope' => 'account',
            'back' => 'files',
            'steps' => [
                ['key' => 'dir', 'prompt' => "کدام پوشه با رمز محافظت شود؟\n\n(مثل پوشه‌ی مدیریت یا استیجینگ سایت.)", 'picker' => 'dirs'],
                ['key' => 'user', 'prompt' => 'نام کاربری برای ورود:'],
                ['key' => 'password', 'prompt' => 'رمز عبور (یا <code>/gen</code>):', 'secret' => true],
            ],
        ],
        'fav_add' => [
            'title' => '⭐ افزودن میان‌بر',
            'scope' => 'local',
            'back' => 'fav',
            'steps' => [
                ['key' => 'value', 'prompt' => "چه چیزی را میان‌بر کنم؟\n\n• یک دامنه (مثل <code>example.com</code>) → بررسی سلامتش\n• یک ایمیل → تنظیمات صندوق\n• یک مسیر (با / شروع شود) → باز کردن همان پوشه"],
            ],
        ],
        'fav_del' => [
            'title' => '🗑 حذف میان‌بر',
            'scope' => 'local',
            'back' => 'fav',
            'steps' => [
                ['key' => 'value', 'prompt' => 'کدام میان‌بر حذف شود؟', 'picker' => 'favorites'],
            ],
        ],
        'site_copy' => [
            'title' => '📑 کپی سایت به دامنه‌ی دیگر',
            'scope' => 'account',
            'back' => 'files',
            'confirm' => '⚠️ محتوای <code>{from}</code> در <code>{to}</code> کپی می‌شود؛ فایل‌های هم‌نام مقصد بازنویسی می‌شوند.',
            'steps' => [
                ['key' => 'from', 'prompt' => 'پوشه‌ی سایت مبدأ:', 'picker' => 'dirs'],
                ['key' => 'to', 'prompt' => 'پوشه‌ی سایت مقصد:', 'picker' => 'dirs'],
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
        'dns_template' => [
            'title' => '🧩 قالب آماده‌ی DNS',
            'scope' => 'account',
            'back' => 'dns',
            'confirm' => '⚠️ رکوردهای قالب انتخابی روی زون <code>{zone}</code> ساخته می‌شوند (رکوردهایی که از قبل هستند دست‌نخورده می‌مانند).',
            'steps' => [
                ['key' => 'zone', 'prompt' => 'روی کدام دامنه؟', 'picker' => 'zones'],
                ['key' => 'template', 'prompt' => "کدام قالب؟\n\nهر قالب چند رکورد استاندارد را با اولویت و مقدار درست می‌سازد تا لازم نباشد MX و SPF را دستی بنویسید.", 'picker' => 'dns_templates'],
            ],
        ],
        'dns_export' => [
            'title' => '📤 خروجی فایل زون',
            'scope' => 'account',
            'back' => 'dns',
            'steps' => [
                ['key' => 'zone', 'prompt' => "زون کدام دامنه گرفته شود؟\n\nخروجی یک فایل استاندارد است که هم برای نگهداری و هم برای وارد کردن روی سرور دیگر به کار می‌آید.", 'picker' => 'zones'],
            ],
        ],
        'file_transfer' => [
            'title' => '🔁 انتقال فایل به سرور دیگر',
            'scope' => 'account',
            'back' => 'files',
            'steps' => [
                ['key' => 'path', 'prompt' => "مسیر کامل فایل روی همین هاست:\n\n<code>/home/user/public_html/site.zip</code>\n\n💡 برای پوشه، اول از «🗜 فشرده‌سازی» یک zip بسازید."],
                ['key' => 'server', 'prompt' => 'به کدام سرور فرستاده شود؟', 'picker' => 'other_servers'],
                ['key' => 'dir', 'prompt' => 'در کدام پوشه‌ی سرور مقصد گذاشته شود؟', 'picker' => 'target_dirs'],
            ],
        ],
        'srv_compare' => [
            'title' => '⚖️ مقایسه‌ی دو هاست',
            'scope' => 'local',
            'back' => 'srv',
            'steps' => [
                ['key' => 'first', 'prompt' => 'هاست اول:', 'picker' => 'servers'],
                ['key' => 'second', 'prompt' => 'هاست دوم:', 'picker' => 'servers'],
            ],
        ],
        'php_test' => [
            'title' => '🧪 تست زنده‌ی PHP',
            'scope' => 'account',
            'back' => 'php',
            'steps' => [
                ['key' => 'domain', 'prompt' => "کدام دامنه تست شود؟\n\nیک فایل موقت در پوشه‌ی همان سایت گذاشته می‌شود، از بیرون صدا زده می‌شود و بعد پاک می‌شود؛ اینطوری معلوم می‌شود وب‌سرور واقعاً چه نسخه‌ای از PHP اجرا می‌کند.", 'picker' => 'domains'],
            ],
        ],
        'hotlink' => [
            'title' => '🖼 جلوگیری از هات‌لینک',
            'scope' => 'account',
            'back' => 'dom',
            'steps' => [
                ['key' => 'docroot', 'prompt' => "پوشه‌ی کدام سایت؟\n\nهات‌لینک یعنی سایت دیگری مستقیم به تصویر شما لینک بدهد و پهنای‌باند شما را خرج کند.", 'picker' => 'dirs'],
                ['key' => 'state', 'prompt' => 'روشن یا خاموش؟', 'choices' => [
                    ['🛡 روشن (فقط سایت خودم)', '1'],
                    ['✅ خاموش (بدون محدودیت)', '0'],
                ]],
                ['key' => 'allowed', 'prompt' => "دامنه‌هایی که اجازه دارند (اختیاری، با فاصله جدا کنید):\n\n<code>example.com cdn.example.com</code>\n\n💡 دامنه‌های خود حساب خودکار اضافه می‌شوند؛ اگر تصویرهایتان را در گوگل هم می‌خواهید، <code>google.com</code> را اضافه کنید.", 'optional' => true, 'default' => ''],
            ],
        ],
        'schedule_op' => [
            'title' => '⏳ زمان‌بندی یک کار',
            'scope' => 'account',
            'back' => 'alerts',
            'steps' => [
                ['key' => 'action', 'prompt' => 'چه کاری زمان‌بندی شود؟', 'picker' => 'queue_actions'],
                ['key' => 'delay', 'prompt' => "چه زمانی اجرا شود؟\n\nمی‌توانید عدد دقیقه هم بفرستید (مثل <code>90</code>).", 'choices' => [
                    ['⏱ ۱ ساعت دیگر', '60'],
                    ['⏱ ۳ ساعت دیگر', '180'],
                    ['🌙 ۶ ساعت دیگر', '360'],
                    ['📅 فردا همین ساعت', '1440'],
                ]],
                ['key' => 'docroot', 'prompt' => "فقط برای حالت تعمیر لازم است: پوشه‌ی سایت.\n\nبرای بقیه‌ی کارها <code>/skip</code> را بزنید.", 'optional' => true, 'default' => '', 'picker' => 'dirs'],
            ],
        ],
        'queue_del' => [
            'title' => '🗑 حذف کار زمان‌بندی‌شده',
            'scope' => 'local',
            'back' => 'alerts',
            'steps' => [
                ['key' => 'id', 'prompt' => 'کدام کار از صف برداشته شود؟', 'picker' => 'queue'],
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

        if (str_starts_with($data, 'fav:')) {
            $favorite = Favorites::all()[(int) substr($data, 4)] ?? null;
            if ($favorite === null) {
                self::sendMenu($api, $chatId, 'fav');

                return;
            }

            match ($favorite['type']) {
                Favorites::TYPE_PATH => self::browseFiles($api, $chatId, $favorite['value']),
                Favorites::TYPE_EMAIL => self::execute($api, $chatId, 'email_settings', ['email' => $favorite['value']]),
                default => self::execute($api, $chatId, 'site_check', ['domain' => $favorite['value']]),
            };

            return;
        }

        if (str_starts_with($data, 'h:')) {
            self::howto($api, $chatId, substr($data, 2));

            return;
        }

        // دکمه‌های مرورگر فایل
        if (str_starts_with($data, 'fb') || str_starts_with($data, 'ff:')
            || str_starts_with($data, 'fx:') || str_starts_with($data, 'fd:')
            || str_starts_with($data, 'fg:') || $data === 'fup') {
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
        if (!str_starts_with($text, '/')) {
            return true;
        }
        if (in_array($text, ['/skip', '/gen'], true)) {
            return true;
        }

        // یک دستور واقعی مثل /panel است: یک کلمه‌ی حرفی، بدون اسلش و نقطه‌ی
        // بعدی. مسیرهای فایل (/home/user/public_html) هم با / شروع می‌شوند و
        // نباید به‌جای دستور گرفته شوند، وگرنه وسط جریان‌های فایل رها می‌شویم.
        return preg_match('#^/[a-zA-Z][a-zA-Z0-9_]*(@[A-Za-z0-9_]+)?$#', $text) !== 1;
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
                [self::btn('👥 ساخت گروهی', 'f:email_bulk_add'), self::btn('🗑 حذف گروهی', 'f:email_bulk_del')],
                [self::btn('🔑 رمز گروهی', 'f:email_bulk_passwd'), self::btn('📬 بررسی رکوردهای ایمیل', 'f:mail_records')],
                [self::btn('🔑 تغییر رمز', 'f:email_passwd'), self::btn('📦 سهمیه', 'f:email_quota')],
                [self::btn('📮 فورواردها', 'a:forwarders'), self::btn('🤖 پاسخ خودکار', 'a:responders')],
                [self::btn('🧽 پاک‌سازی Trash و Spam', 'f:mail_purge'), self::btn('📱 تنظیمات IMAP/SMTP', 'f:email_settings')],
                [self::btn('📊 مصرف صندوق‌ها', 'a:maildisk')],
                [self::btn('⬅️ بازگشت', 'menu')],
            ]],
            'db' => [self::databaseMenuText(), [
                [self::btn('📋 دیتابیس‌ها و کاربران', 'a:dbs')],
                [self::btn('➕ دیتابیس جدید', 'f:db_add'), self::btn('👤 کاربر جدید', 'f:dbuser_add')],
                [self::btn('🔗 وصل‌کردن کاربر به دیتابیس', 'f:db_grant')],
                [self::btn('🔑 رمز کاربر', 'f:db_passwd'), self::btn('🚫 گرفتن دسترسی', 'f:db_revoke')],
                [self::btn('✏️ نام دیتابیس', 'f:db_rename'), self::btn('✏️ نام کاربر', 'f:dbuser_rename')],
                [self::btn('🛠 بررسی و تعمیر', 'f:db_repair'), self::btn('🌍 اتصال از راه دور', 'a:mysqlhosts')],
                [self::btn('ℹ️ اطلاعات اتصال', 'a:dbinfo'), self::btn('📊 حجم دیتابیس‌ها', 'a:dbsizes')],
                [self::btn('🗑 حذف دیتابیس', 'f:db_del'), self::btn('🗑 حذف کاربر', 'f:dbuser_del')],
                [self::btn('⬅️ بازگشت', 'menu')],
            ]],
            'dom' => ["🌐 <b>دامنه‌ها</b>\n\nدامنه‌ی اصلی حساب، دامنه‌های اضافه (ادان)، ساب‌دامنه‌ها، ریدایرکت‌ها و گواهی‌های SSL.", [
                [self::btn('📋 لیست دامنه‌ها', 'a:domains')],
                [self::btn('➕ ساب‌دامنه', 'f:sub_add'), self::btn('🗑 حذف ساب‌دامنه', 'f:sub_del')],
                [self::btn('🌍 دامنه‌ی جدید (ادان)', 'f:addon_add'), self::btn('🗑 حذف ادان', 'f:addon_del')],
                [self::btn('🅿️ پارک دامنه', 'f:park_add'), self::btn('📶 پهنای‌باند', 'a:bandwidth')],
                [self::btn('↪️ ریدایرکت‌ها', 'a:redirects'), self::btn('🧭 DNS', 'm:dns')],
                [self::btn('🩺 بررسی سلامت دامنه', 'f:site_check'), self::btn('🔒 SSL', 'm:ssl')],
                [self::btn('🛠 حالت تعمیر سایت', 'f:maintenance'), self::btn('⛔️ مسدودکردن آی‌پی', 'f:ip_block')],
                [self::btn('🖼 جلوگیری از هات‌لینک', 'f:hotlink')],
                [self::btn('⬅️ بازگشت', 'menu')],
            ]],
            'files' => ["📂 <b>فایل‌های هاست</b>\n\nسورس سایت را از همین‌جا آپلود کنید: فایل (یا zip) را بفرستید، و اگر آرشیو بود همان‌جا بازش کنید.\n"
                . '⚠️ سقف فایل در تلگرام ۲۰ مگابایت است؛ برای سورس‌های بزرگ‌تر آن را تکه یا zip کنید.', [
                [self::btn('🗂 مرور فایل‌ها', 'a:files')],
                [self::btn('⬆️ آپلود فایل', 'f:file_upload'), self::btn('🗜 باز کردن آرشیو', 'f:file_extract')],
                [self::btn('📁 پوشه‌ی جدید', 'f:file_mkdir'), self::btn('🗑 حذف فایل/پوشه', 'f:file_delete')],
                [self::btn('✏️ تغییر نام', 'f:file_rename'), self::btn('📦 جابه‌جایی/کپی', 'f:file_move')],
                [self::btn('🔐 سطح دسترسی', 'f:file_chmod'), self::btn('🗜 فشرده‌سازی', 'f:file_compress')],
                [self::btn('📊 بزرگ‌ترین فایل‌ها', 'a:bigfiles'), self::btn('🕒 تغییرات اخیر', 'a:recentfiles')],
                [self::btn('⬇️ دانلود فایل', 'f:file_download'), self::btn('⬇️ دانلود پوشه (zip)', 'f:folder_download')],
                [self::btn('📝 ویرایش فایل', 'f:file_edit'), self::btn('📄 فایل جدید', 'f:file_create')],
                [self::btn('🔎 پیدا کردن فایل', 'f:file_find'), self::btn('↩️ بازگردانی آخرین بکاپ', 'a:undo')],
                [self::btn('🗑 حذف گروهی', 'f:file_bulk_delete'), self::btn('📦 جابه‌جایی گروهی', 'f:file_bulk_move')],
                [self::btn('🔐 رمز روی پوشه', 'f:dir_protect'), self::btn('📊 مصرف پوشه‌ها', 'a:dirusage')],
                [self::btn('📑 کپی سایت به دامنه‌ی دیگر', 'f:site_copy'), self::btn('🔁 انتقال به سرور دیگر', 'f:file_transfer')],
                [self::btn('🧹 پاک‌سازی هوشمند', 'a:cleanup'), self::btn('🛡 گزارش امنیتی', 'a:security')],
                [self::btn('⬅️ بازگشت', 'menu')],
            ]],
            'ftp' => ["📁 <b>حساب‌های FTP</b>\n\nبرای وصل‌شدن با نرم‌افزارهایی مثل FileZilla.", [
                [self::btn('📋 لیست حساب‌ها', 'a:ftps')],
                [self::btn('➕ افزودن', 'f:ftp_add'), self::btn('🗑 حذف', 'f:ftp_del')],
                [self::btn('🔑 تغییر رمز', 'f:ftp_passwd'), self::btn('📦 سهمیه', 'f:ftp_quota')],
                [self::btn('🔌 نشست‌های باز', 'a:ftpsessions')],
                [self::btn('⬅️ بازگشت', 'menu')],
            ]],
            'cron' => ["⏰ <b>کران‌جاب‌ها</b>\n\nدستورهایی که سرور خودکار و سر وقت اجرا می‌کند (مثل کران وردپرس یا بکاپ روزانه).", [
                [self::btn('📋 لیست کران‌جاب‌ها', 'a:crons')],
                [self::btn('➕ افزودن', 'f:cron_add'), self::btn('✏️ ویرایش', 'f:cron_edit')],
                [self::btn('🗑 حذف', 'f:cron_del'), self::btn('📧 ایمیل خروجی کران', 'f:cron_email')],
                [self::btn('⬅️ بازگشت', 'menu')],
            ]],
            'dns' => ["🧭 <b>رکوردهای DNS</b>\n\nمشخص می‌کند دامنه به کجا اشاره کند (A برای آی‌پی، CNAME برای نام، MX برای ایمیل، TXT برای تأییدیه‌ها).", [
                [self::btn('📄 نمایش رکوردها', 'f:dns_list')],
                [self::btn('➕ رکورد جدید', 'f:dns_add'), self::btn('🗑 حذف رکورد', 'f:dns_del')],
                [self::btn('🛠 اصلاح رکورد A', 'f:dns_fix'), self::btn('🧭 رکورد گروهی', 'f:dns_bulk')],
                [self::btn('🧩 قالب آماده', 'f:dns_template'), self::btn('📤 خروجی فایل زون', 'f:dns_export')],
                [self::btn('⬅️ بازگشت', 'm:dom')],
            ]],
            'ssl' => ["🔒 <b>گواهی SSL</b>\n\nگواهی همان چیزی است که قفل سبز مرورگر و آدرس <code>https</code> را می‌سازد. روی اکثر هاست‌ها cPanel خودش رایگان صادر می‌کند (AutoSSL).", [
                [self::btn('📋 گواهی‌های نصب‌شده', 'a:ssl')],
                [self::btn('🔄 درخواست صدور/تمدید خودکار', 'a:autossl'), self::btn('🔐 اجبار HTTPS', 'f:force_https')],
                [self::btn('⬅️ بازگشت', 'm:dom')],
            ]],
            'php' => ["🐘 <b>نسخه‌ی PHP</b>\n\nنسخه‌ی PHP هر دامنه را جدا می‌شود عوض کرد (MultiPHP).", [
                [self::btn('📋 نسخه‌ی هر دامنه', 'a:php')],
                [self::btn('🔧 تغییر نسخه', 'f:php_set'), self::btn('⚙️ محدودیت‌ها', 'f:php_limits')],
                [self::btn('🧪 تست زنده‌ی PHP', 'f:php_test')],
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
                [self::btn('👥 تعلیق/رفع تعلیق گروهی', 'f:whm_bulk'), self::btn('📊 پرمصرف‌ترین حساب‌ها', 'a:whmtop')],
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
            'fav' => [self::favoritesMenuText(), array_merge(
                self::favoriteRows(),
                [
                    [self::btn('⭐ افزودن میان‌بر', 'f:fav_add'), self::btn('🗑 حذف میان‌بر', 'f:fav_del')],
                    [self::btn('⬅️ بازگشت', 'menu')],
                ],
            )],
            'alerts' => [self::alertsMenuText(), [
                [self::btn('💽 آستانه‌ی دیسک/اینود/SSL', 'f:alert_threshold')],
                [self::btn('🔴 هشدار داون‌شدن سایت', 'a:togglesite'), self::btn('🛡 بکاپ قبل از تغییر', 'a:togglesafe')],
                [self::btn('📊 گزارش دوره‌ای', 'a:reportevery'), self::btn('🗓 بکاپ خودکار', 'f:backup_schedule')],
                [self::btn('👁 دامنه‌های تحت‌نظر', 'f:watch_domains')],
                [self::btn('⏳ کارهای زمان‌بندی‌شده', 'a:queue')],
                [self::btn('⬅️ بازگشت', 'menu')],
            ]],
            'adm' => ["👮 <b>ادمین‌های ربات</b>\n\nهر کسی که اینجا باشد به همه‌ی امکانات ربات دسترسی دارد.", [
                [self::btn('📋 لیست ادمین‌ها', 'a:admins')],
                [self::btn('➕ افزودن ادمین', 'f:admin_add'), self::btn('🗑 حذف ادمین', 'f:admin_del')],
                [self::btn('⏳ دسترسی موقت', 'f:guest_invite'), self::btn('🚫 لغو دسترسی‌های موقت', 'a:revokeguests')],
                [self::btn('⬅️ بازگشت', 'menu')],
            ]],
            'srv' => ["🖥 <b>سرورها</b>\n\nهر تعداد هاست cPanel یا سرور WHM می‌توانید اضافه کنید و بینشان جابه‌جا شوید.", array_merge(
                [[self::btn('📋 لیست سرورها', 'a:servers'), self::btn('🔌 تست اتصال', 'a:ping')]],
                self::serverPickerRows(),
                [
                    [self::btn('➕ افزودن سرور', 'f:srv_add'), self::btn('🗑 حذف سرور', 'f:srv_del')],
                    [self::btn('⚖️ مقایسه‌ی دو هاست', 'f:srv_compare')],
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
        $rows[] = [self::btn('🧭 کارهای رایج', 'm:howto'), self::btn('📤 خروجی CSV', 'f:export_csv')];
        $rows[] = [self::btn('✨ قابلیت‌ها', 'a:features')];
        $rows[] = [self::btn('🔍 جست‌وجو', 'f:search'), self::btn('⭐ میان‌برها', 'm:fav')];
        $rows[] = [self::btn('🔔 هشدارها', 'm:alerts')];
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

    private static function favoritesMenuText(): string
    {
        $count = count(Favorites::all());

        return $count === 0
            ? "⭐ <b>میان‌برها</b>\n\nهنوز میان‌بری نساخته‌اید.\n\nدامنه، ایمیل یا پوشه‌ای که زیاد سراغش می‌روید را اینجا اضافه کنید تا با یک دکمه بازش کنید."
            : "⭐ <b>میان‌برها</b> ({$count})\n\nهر کدام را بزنید تا مستقیم برود سراغش.";
    }

    /**
     * @return array<int, array<int, array<string, string>>>
     */
    private static function favoriteRows(): array
    {
        $rows = [];
        foreach (Favorites::all() as $index => $favorite) {
            $icon = match ($favorite['type']) {
                Favorites::TYPE_EMAIL => '✉️',
                Favorites::TYPE_PATH => '📂',
                default => '🌐',
            };
            $rows[] = [self::btn($icon . ' ' . self::shorten($favorite['value'], 40), 'fav:' . $index)];
        }

        return $rows;
    }

    /**
     * متن منوی هشدارها با مقدارهای فعلی — تا کاربر ببیند الان چه چیزی روشن است.
     */
    private static function alertsMenuText(): string
    {
        $every = match (Settings::get(Settings::REPORT_EVERY, 'weekly')) {
            'daily' => 'روزانه',
            'off' => 'خاموش',
            default => 'هفتگی',
        };

        return "🔔 <b>هشدارها و گزارش خودکار</b>\n\n"
            . "ربات هر چند دقیقه وضعیت هاست را می‌سنجد و فقط وقتی چیزی مشکل دارد پیام می‌دهد.\n\n"
            . '💽 هشدار دیسک: ' . Settings::int(Settings::ALERT_DISK, 85) . "٪\n"
            . '🔢 هشدار اینود: ' . Settings::int(Settings::ALERT_INODE, 85) . "٪\n"
            . '🔒 هشدار انقضای SSL: ' . Settings::int(Settings::ALERT_SSL_DAYS, 14) . " روز مانده\n"
            . '🔴 هشدار داون‌شدن سایت: ' . (Settings::bool(Settings::ALERT_SITE, true) ? 'روشن' : 'خاموش') . "\n"
            . '🛡 بکاپ قبل از تغییر/حذف: ' . (Settings::bool(Settings::SAFE_DELETE, true) ? 'روشن' : 'خاموش') . "\n"
            . '📊 گزارش دوره‌ای: ' . $every;
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

        if ($action === 'togglesite' || $action === 'togglesafe') {
            $key = $action === 'togglesite' ? Settings::ALERT_SITE : Settings::SAFE_DELETE;
            Settings::toggle($key);
            self::sendMenu($api, $chatId, 'alerts');

            return;
        }

        if ($action === 'reportevery') {
            $next = match (Settings::get(Settings::REPORT_EVERY, 'weekly')) {
                'weekly' => 'daily',
                'daily' => 'off',
                default => 'weekly',
            };
            Settings::set(Settings::REPORT_EVERY, $next);
            self::sendMenu($api, $chatId, 'alerts');

            return;
        }

        if ($action === 'undo') {
            $backup = Settings::get(Settings::LAST_BACKUP);
            if ($backup === '') {
                self::respond($api, $chatId, 'چیزی برای بازگردانی نیست؛ آخرین عملیات نسخه‌ی پشتیبان نداشت.', self::backKeyboard('files'));

                return;
            }
            $api->sendChatAction($chatId);
            try {
                CpanelService::extractArchive(self::accountClient(), $backup, dirname($backup));
                AuditLog::record(self::$actor, (string) (ServerStore::activeId() ?? '-'), 'undo_restore', ['backup' => $backup]);
                $text = '↩️ نسخه‌ی پشتیبان برگردانده شد از <code>' . Format::escapeHtml($backup) . '</code>';
            } catch (Throwable $e) {
                $text = Render::error($e);
            }
            self::respond($api, $chatId, $text, self::backKeyboard('files'));

            return;
        }

        if ($action === 'revokeguests') {
            Admins::revokeInvites();
            self::respond($api, $chatId, '🚫 همه‌ی دسترسی‌های موقت لغو شدند.', self::backKeyboard('adm'));

            return;
        }

        if ($action === 'responders') {
            $api->sendChatAction($chatId);
            try {
                $text = Render::autoResponders(CpanelService::autoResponders(self::accountClient()));
                $keyboard = ['inline_keyboard' => [
                    [self::btn('➕ تنظیم پاسخ خودکار', 'f:responder_add'), self::btn('🗑 حذف', 'f:responder_del')],
                    [self::btn('⬅️ بازگشت', 'm:email')],
                ]];
            } catch (Throwable $e) {
                $text = Render::error($e);
                $keyboard = self::backKeyboard('email');
            }
            self::respond($api, $chatId, $text, $keyboard);

            return;
        }

        if ($action === 'ftpsessions') {
            $api->sendChatAction($chatId);
            try {
                $sessions = CpanelService::ftpSessions(self::accountClient());
                $text = Render::ftpSessions($sessions);
                $keyboard = ['inline_keyboard' => array_merge(
                    $sessions === [] ? [] : [[self::btn('✂️ قطع یک نشست', 'f:ftp_kill')]],
                    [[self::btn('⬅️ بازگشت', 'm:ftp'), self::btn('🏠 منوی اصلی', 'menu')]],
                )];
            } catch (Throwable $e) {
                $text = Render::error($e);
                $keyboard = self::backKeyboard('ftp');
            }
            self::respond($api, $chatId, $text, $keyboard);

            return;
        }

        if ($action === 'report') {
            self::sendReport($api, $chatId);

            return;
        }

        if ($action === 'cleanup') {
            $api->sendChatAction($chatId);
            try {
                $client = self::accountClient();
                $candidates = CpanelService::cleanupCandidates($client);
                $percent = null;
                foreach (CpanelService::resourceUsage($client) as $item) {
                    if (str_contains(strtolower($item['id']), 'disk')) {
                        $percent = $item['percent'];
                        break;
                    }
                }
                $text = Render::cleanup($candidates, $percent);
                $keyboard = ['inline_keyboard' => array_merge(
                    $candidates === [] ? [] : [[self::btn('🧹 پاک‌کردن یکی از موارد', 'f:cleanup_run')]],
                    [[self::btn('⬅️ بازگشت', 'm:files'), self::btn('🏠 منوی اصلی', 'menu')]],
                )];
            } catch (Throwable $e) {
                $text = Render::error($e);
                $keyboard = self::backKeyboard('files');
            }
            self::respond($api, $chatId, $text, $keyboard);

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
            foreach (Admins::guests() as $guest) {
                $hours = max(0, (int) ceil(($guest['expires'] - time()) / 3600));
                $lines[] = '• <code>' . $guest['id'] . "</code> — ⏳ دسترسی موقت، {$hours} ساعت دیگر تمام می‌شود";
            }
            $invite = Admins::invite();
            if ($invite !== null) {
                $lines[] = '';
                $lines[] = '⏳ کد دسترسی موقت فعال است: <code>' . Format::escapeHtml($invite['code']) . '</code>';
            }
            self::respond($api, $chatId, implode("\n", $lines), self::backKeyboard('adm'));

            return;
        }

        if ($action === 'queue') {
            $items = OpQueue::all();
            $keyboard = ['inline_keyboard' => array_merge(
                [[self::btn('➕ زمان‌بندی یک کار', 'f:schedule_op')]],
                $items === [] ? [] : [[self::btn('🗑 حذف از صف', 'f:queue_del')]],
                [[self::btn('⬅️ بازگشت', 'm:alerts'), self::btn('🏠 منوی اصلی', 'menu')]],
            )];
            self::respond($api, $chatId, Render::queue($items), $keyboard);

            return;
        }

        if ($action === 'log') {
            self::respond($api, $chatId, Render::auditLog(AuditLog::tail(12)), self::backKeyboard());

            return;
        }

        $api->sendChatAction($chatId);
        try {
            $text = match ($action) {
                'ping' => Render::ping(ServerStore::client()->ping()),
                'summary' => self::summaryWithHistory(),
                'emails' => self::emailsWithUsage(),
                'dbs' => Render::databases(
                    CpanelService::databases(self::accountClient()),
                    CpanelService::databaseUsers(self::accountClient()),
                ),
                'domains' => Render::domains(
                    CpanelService::domains(self::accountClient()),
                    CpanelService::domainsData(self::accountClient()),
                ),
                'acctfeatures' => Render::features(CpanelService::accountFeatures(self::accountClient())),
                'bandwidth' => Render::bandwidth(CpanelService::bandwidth(self::accountClient())),
                'dirusage' => Render::directoryUsage(CpanelService::directoryUsage(self::accountClient())),
                'security' => Render::security(CpanelService::securityScan(self::accountClient())),
                'maildisk' => Render::mailboxUsage(CpanelService::mailboxUsage(CpanelService::emails(self::accountClient()))),
                'dbsizes' => Render::databaseSizes(CpanelService::databaseSizes(CpanelService::databases(self::accountClient()))),
                'trend' => Render::usageTrend(UsageHistory::trend(), UsageHistory::days()),
                'dbinfo' => Render::mysqlInfo(
                    CpanelService::mysqlServerInfo(self::accountClient()),
                    CpanelService::databaseInfo(self::accountClient())['prefix'],
                ),
                'bigfiles' => Render::fileList(
                    '📊 <b>بزرگ‌ترین فایل‌های پوشه‌ی هوم</b>',
                    CpanelService::largestFiles(self::accountClient(), CpanelService::homeDir(self::accountClient())),
                ),
                'recentfiles' => Render::fileList(
                    '🕒 <b>تازه‌ترین تغییرات پوشه‌ی هوم</b>',
                    CpanelService::recentFiles(self::accountClient(), CpanelService::homeDir(self::accountClient())),
                ),
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
                'whmtop' => Render::whmTopAccounts(CpanelService::whmTopAccounts(self::whmClient())),
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

        self::respond($api, $chatId, $text, self::actionKeyboard($action));
    }

    /**
     * کیبورد پایین هر «کار»: دکمه‌های مرتبط با همان صفحه، به‌علاوه‌ی بازگشت.
     *
     * @return array{inline_keyboard: array<int, array<int, array<string, string>>>}
     */
    private static function actionKeyboard(string $action): array
    {
        $extra = match ($action) {
            'summary' => [
                [self::btn('🧩 امکانات فعال حساب', 'a:acctfeatures'), self::btn('📈 روند مصرف', 'a:trend')],
                [self::btn('📄 گزارش وضعیت (فایل)', 'a:report'), self::btn('📶 پهنای‌باند', 'a:bandwidth')],
            ],
            'acctfeatures' => [[self::btn('⬅️ خلاصه‌ی حساب', 'a:summary')]],
            'domains' => [[self::btn('🩺 بررسی سلامت دامنه', 'f:site_check')]],
            'bigfiles', 'recentfiles' => [[self::btn('🗂 مرور فایل‌ها', 'a:files')]],
            'php' => [[self::btn('⚙️ محدودیت‌های PHP', 'f:php_limits')]],
            'emails' => [[self::btn('📮 فورواردها', 'a:forwarders'), self::btn('📊 مصرف صندوق‌ها', 'a:maildisk')]],
            'maildisk' => [[self::btn('🧽 پاک‌سازی Trash و Spam', 'f:mail_purge'), self::btn('📦 سهمیه', 'f:email_quota')]],
            'dbsizes' => [[self::btn('📋 دیتابیس‌ها و کاربران', 'a:dbs'), self::btn('🛠 بررسی و تعمیر', 'f:db_repair')]],
            'security' => [
                [self::btn('🔐 سطح دسترسی', 'f:file_chmod'), self::btn('🗑 حذف فایل/پوشه', 'f:file_delete')],
                [self::btn('📦 جابه‌جایی/کپی', 'f:file_move'), self::btn('🔄 گشت دوباره', 'a:security')],
            ],
            default => [],
        };

        $back = self::backKeyboard(self::sectionOf($action));

        return ['inline_keyboard' => array_merge($extra, $back['inline_keyboard'])];
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
            $rows[] = [self::btn('⬇️ دانلود', 'fg:' . $index), self::btn('🗑 حذف', 'fd:' . $index)];
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

        if (str_starts_with($data, 'fg:')) {
            $entry = $entries[(int) substr($data, 3)] ?? null;
            if (!is_array($entry)) {
                self::browseFiles($api, $chatId, $dir, $page);

                return true;
            }
            State::clear($chatId);
            self::execute($api, $chatId, 'file_download', ['path' => (string) $entry['path']]);

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

    /**
     * فهرست ایمیل‌ها به‌همراه مجموع مصرف و هشدار صندوق‌های نزدیک به پر شدن.
     */
    private static function emailsWithUsage(): string
    {
        $emails = CpanelService::emails(self::accountClient());
        $usage = CpanelService::emailUsage($emails);

        $text = Render::emails($emails);
        if ($usage['count'] > 0) {
            $text .= "\n\n📦 مجموع مصرف: " . Format::bytes($usage['used']);
        }
        if ($usage['near_full'] !== []) {
            $text .= "\n⚠️ نزدیک پر شدن: " . Format::escapeHtml(implode('، ', $usage['near_full']));
        }

        return $text;
    }

    /**
     * گزارش وضعیت هاست به‌صورت یک فایل متنی — برای بایگانی یا فرستادن به
     * پشتیبانی هاست.
     */
    private static function sendReport(TelegramApi $api, int $chatId): void
    {
        $api->sendChatAction($chatId, 'upload_document');

        try {
            $client = self::accountClient();
            $summary = CpanelService::summary($client);
            $lines = [
                'گزارش وضعیت هاست — ' . Format::jalaliNow(),
                str_repeat('=', 48),
                '',
                Render::toPlain(Render::summary($summary)),
                '',
                str_repeat('-', 48),
                Render::toPlain(Render::domains($summary['domains'], CpanelService::domainsData($client))),
                '',
                str_repeat('-', 48),
                Render::toPlain(Render::emails(CpanelService::emails($client))),
                '',
                str_repeat('-', 48),
                Render::toPlain(Render::databases(CpanelService::databases($client), CpanelService::databaseUsers($client))),
                '',
                str_repeat('-', 48),
                Render::toPlain(Render::ssl(CpanelService::sslHosts($client))),
            ];

            $api->sendDocument(
                $chatId,
                'host-report-' . gmdate('Ymd-His') . '.txt',
                implode("\n", $lines),
                '📄 گزارش وضعیت هاست',
            );
            AuditLog::record(self::$actor, (string) (ServerStore::activeId() ?? '-'), 'report', []);
        } catch (Throwable $e) {
            self::respond($api, $chatId, Render::error($e), self::backKeyboard());
        }
    }

    /**
     * خلاصه‌ی حساب + ثبت یک عکس فوری از مصرف، تا «روند مصرف» فقط به تایمر
     * هشدارها وابسته نباشد.
     */
    private static function summaryWithHistory(): string
    {
        $summary = CpanelService::summary(self::accountClient());
        UsageHistory::record($summary['usage']);

        return Render::summary($summary);
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
            'dbs', 'dbinfo' => 'db',
            'cleanup', 'undo', 'dirusage', 'security' => 'files',
            'maildisk' => 'email',
            'dbsizes' => 'db',
            'queue' => 'alerts',
            'trend' => null,
            'togglesite', 'togglesafe', 'reportevery' => 'alerts',
            'domains', 'redirects' => 'dom',
            'ssl', 'autossl' => 'ssl',
            'mysqlhosts' => 'db',
            'files', 'bigfiles', 'recentfiles' => 'files',
            'acctfeatures' => null,
            'ftps', 'ftpsessions' => 'ftp',
            'responders' => 'email',
            'bandwidth' => 'dom',
            'crons' => 'cron',
            'php' => 'php',
            'backups' => 'backup',
            'whmaccts', 'whmstatus', 'whmpkgs', 'whmtop' => 'whm',
            'revokeguests' => 'adm',
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

            case 'responders':
                return $simple(array_column(CpanelService::autoResponders(self::accountClient()), 'email'));

            case 'ftp_sessions':
                $options = [];
                foreach (CpanelService::ftpSessions(self::accountClient()) as $session) {
                    if ($session['user'] === '') {
                        continue;
                    }
                    $options[] = [
                        'label' => $session['user'] . ($session['host'] !== '' ? ' — ' . $session['host'] : ''),
                        'value' => $session['user'] . '|' . $session['pid'],
                    ];
                }

                return $options;

            case 'addon_domains':
                return $simple(CpanelService::domains(self::accountClient())['addon_domains']);

            case 'favorites':
                $options = [];
                foreach (Favorites::all() as $favorite) {
                    $options[] = ['label' => $favorite['value'], 'value' => $favorite['value']];
                }

                return $options;

            case 'cleanup':
                $options = [];
                foreach (CpanelService::cleanupCandidates(self::accountClient()) as $candidate) {
                    $options[] = [
                        'label' => $candidate['label'] . ' — ' . Format::bytes($candidate['size']),
                        'value' => $candidate['path'],
                    ];
                }

                return $options;

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

            case 'dns_templates':
                $options = [];
                foreach (CpanelService::dnsTemplates((string) ($data['zone'] ?? '')) as $key => $template) {
                    $options[] = ['label' => $template['label'], 'value' => (string) $key];
                }

                return $options;

            case 'other_servers':
                $options = [];
                $active = ServerStore::activeId();
                foreach (ServerStore::all() as $id => $server) {
                    if ((string) $id === (string) $active) {
                        continue;
                    }
                    $options[] = ['label' => $server['label'] . ' (' . $id . ')', 'value' => (string) $id];
                }

                return $options;

            case 'target_dirs':
                $target = (string) ($data['server'] ?? '');
                if ($target === '') {
                    return [];
                }
                $client = ServerStore::client($target);
                $home = CpanelService::homeDir($client);
                $dirs = [$home, $home . '/public_html'];
                foreach (CpanelService::domains($client)['addon_domains'] as $addon) {
                    $dirs[] = $home . '/' . $addon;
                }

                return $simple(array_values(array_unique($dirs)));

            case 'queue_actions':
                $options = [];
                foreach (OpQueue::ACTIONS as $key => $label) {
                    $options[] = ['label' => $label, 'value' => (string) $key];
                }

                return $options;

            case 'queue':
                $options = [];
                foreach (OpQueue::all() as $item) {
                    $options[] = [
                        'label' => OpQueue::label($item) . ' — ' . gmdate('m-d H:i', $item['run_at']),
                        'value' => $item['id'],
                    ];
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
        // کلیدهای داخلی (فهرست دکمه‌ها، شماره‌ی صفحه، شناسه‌ی پیام) نه به
        // عملیات می‌رسند و نه در لاگ ثبت می‌شوند.
        unset($data['__options'], $data['__page'], $data['__msg']);
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

        if (self::$pendingFile !== null) {
            $file = self::$pendingFile;
            self::$pendingFile = null;
            try {
                $api->sendDocument($chatId, $file['name'], $file['content']);
            } catch (Throwable $e) {
                $api->sendHtml($chatId, Render::error($e));
            }
        }
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
    private static function runSiteCopy(array $data): string
    {
        $count = CpanelService::copySite(self::accountClient(), (string) $data['from'], (string) $data['to']);

        return '📑 ' . $count . ' مورد از <code>' . Format::escapeHtml((string) $data['from'])
            . '</code> به <code>' . Format::escapeHtml((string) $data['to']) . "</code> کپی شد.\n\n"
            . '💡 اگر سایت دیتابیس دارد، یادتان باشد تنظیمات اتصالش را در نسخه‌ی جدید عوض کنید.';
    }

    /** @param array<string, mixed> $data */
    private static function runMailRecords(array $data): string
    {
        return Render::mailRecords((string) $data['domain'], CpanelService::mailRecords((string) $data['domain']));
    }

    /** @param array<string, mixed> $data */
    private static function runWatchDomains(array $data): string
    {
        $raw = trim((string) $data['list']);
        Settings::set(Settings::WATCH_DOMAINS, $raw === '-' ? '' : $raw);

        return $raw === '-'
            ? '👁 برگشت به حالت خودکار: دامنه‌ی اصلی و ادان‌ها بررسی می‌شوند.'
            : '👁 از این به بعد این دامنه‌ها تحت‌نظرند:' . "\n<code>" . Format::escapeHtml($raw) . '</code>';
    }

    /** @param array<string, mixed> $data */
    private static function runBackupSchedule(array $data): string
    {
        Settings::set(Settings::BACKUP_EVERY, (string) $data['every']);
        Settings::set(Settings::BACKUP_KEEP, (string) $data['keep']);

        $every = match ((string) $data['every']) {
            'daily' => 'روزانه',
            'weekly' => 'هفتگی',
            default => 'خاموش',
        };
        $keep = (int) $data['keep'];

        return "🗓 بکاپ خودکار: <b>{$every}</b>"
            . ($keep > 0 ? "\n🧹 فقط {$keep} نسخه‌ی آخر نگه داشته می‌شود." : "\n🧹 پاک‌سازی خودکار خاموش است.");
    }

    /** @param array<string, mixed> $data */
    private static function runGuestInvite(array $data): string
    {
        $invite = Admins::createInvite((int) $data['hours']);
        $hours = (int) $data['hours'];

        return "⏳ <b>کد دسترسی موقت</b>\n\n<code>" . Format::escapeHtml($invite['code']) . "</code>\n\n"
            . "این کد {$hours} ساعت اعتبار دارد. هر کسی آن را برای ربات بفرستد، تا پایان همان مدت دسترسی دارد.\n\n"
            . '⚠️ دسترسی موقت هم همه‌ی امکانات را دارد؛ فقط به کسی بدهید که به او اعتماد دارید. برای لغو زودتر: «🚫 لغو دسترسی‌های موقت».';
    }

    /** @param array<string, mixed> $data */
    private static function runWhmBulk(array $data): string
    {
        $users = self::pathLines((string) $data['users']);
        $suspend = (string) ($data['state'] ?? '1') === '1';
        $results = CpanelService::whmBulkSuspend(self::whmClient(), $users, $suspend, (string) ($data['reason'] ?? ''));

        return Render::bulkResult($suspend ? '⛔️ <b>تعلیق گروهی</b>' : '✅ <b>رفع تعلیق گروهی</b>', $results);
    }

    /** @param array<string, mixed> $data */
    private static function runExportCsv(array $data): string
    {
        $client = self::accountClient();
        $kind = (string) $data['kind'];

        [$headers, $rows, $name] = match ($kind) {
            'databases' => [
                ['database', 'size_bytes', 'users'],
                array_map(
                    static fn (array $row): array => [$row['name'], (string) ($row['size'] ?? ''), implode(' ', $row['users'])],
                    CpanelService::databases($client),
                ),
                'databases',
            ],
            'domains' => [
                ['domain', 'type', 'document_root', 'ip'],
                array_map(
                    static fn (array $row): array => [$row['domain'], $row['type'], $row['docroot'], $row['ip']],
                    CpanelService::domainsData($client),
                ),
                'domains',
            ],
            'ftp' => [
                ['user', 'directory', 'used_bytes'],
                array_map(
                    static fn (array $row): array => [$row['user'], $row['dir'], (string) ($row['used'] ?? '')],
                    CpanelService::ftpAccounts($client),
                ),
                'ftp-accounts',
            ],
            default => [
                ['email', 'domain', 'used_bytes', 'quota'],
                array_map(
                    static fn (array $row): array => [
                        $row['email'],
                        $row['domain'],
                        (string) ($row['used'] ?? ''),
                        is_scalar($row['quota'] ?? null) ? (string) $row['quota'] : '',
                    ],
                    CpanelService::emails($client),
                ),
                'emails',
            ],
        };

        if ($rows === []) {
            return 'چیزی برای خروجی‌گرفتن پیدا نشد.';
        }

        self::$pendingFile = [
            'name' => $name . '-' . gmdate('Ymd') . '.csv',
            'content' => CpanelService::toCsv($headers, $rows),
        ];

        return '📤 خروجی CSV با ' . count($rows) . ' ردیف آماده شد.';
    }

    /** @param array<string, mixed> $data */
    private static function runEmailBulkPasswd(array $data): string
    {
        $client = self::accountClient();
        $results = [];

        foreach (preg_split('/\r\n|\r|\n/', (string) $data['list']) ?: [] as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: [];
            $email = trim((string) ($parts[0] ?? ''));
            if ($email === '') {
                continue;
            }
            $password = (string) ($parts[1] ?? '') ?: CpanelService::randomPassword();

            try {
                CpanelService::setEmailPassword($client, $email, $password);
                $results[] = ['email' => $email, 'password' => $password, 'ok' => true, 'error' => ''];
            } catch (Throwable $e) {
                $results[] = ['email' => $email, 'password' => '', 'ok' => false, 'error' => $e->getMessage()];
            }
        }

        return Render::bulkResult('🔑 <b>تغییر رمز گروهی</b>', $results);
    }

    /** @param array<string, mixed> $data */
    private static function runFileBulkDelete(array $data): string
    {
        $client = self::accountClient();
        $results = [];

        foreach (self::pathLines((string) $data['paths']) as $path) {
            try {
                if (Settings::bool(Settings::SAFE_DELETE, true)) {
                    self::backupBeforeChange($client, $path);
                }
                CpanelService::deletePath($client, $path);
                $results[] = ['email' => $path, 'ok' => true, 'error' => ''];
            } catch (Throwable $e) {
                $results[] = ['email' => $path, 'ok' => false, 'error' => $e->getMessage()];
            }
        }

        return Render::bulkResult('🗑 <b>حذف گروهی</b>', $results);
    }

    /** @param array<string, mixed> $data */
    private static function runFileBulkMove(array $data): string
    {
        $client = self::accountClient();
        $destination = (string) $data['destination'];
        $results = [];

        foreach (self::pathLines((string) $data['paths']) as $path) {
            try {
                CpanelService::movePath($client, $path, $destination);
                $results[] = ['email' => $path, 'ok' => true, 'error' => ''];
            } catch (Throwable $e) {
                $results[] = ['email' => $path, 'ok' => false, 'error' => $e->getMessage()];
            }
        }

        return Render::bulkResult('📦 <b>جابه‌جایی گروهی به ' . Format::escapeHtml($destination) . '</b>', $results);
    }

    /**
     * @return array<int, string>
     */
    private static function pathLines(string $raw): array
    {
        $out = [];
        foreach (preg_split('/\r\n|\r|\n/', $raw) ?: [] as $line) {
            $line = trim($line);
            if ($line !== '') {
                $out[] = $line;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $data */
    private static function runMaintenance(array $data): string
    {
        $enable = (string) ($data['state'] ?? '1') === '1';
        CpanelService::setMaintenance(
            self::accountClient(),
            (string) $data['docroot'],
            $enable,
            (string) ($data['message'] ?? ''),
        );

        return $enable
            ? "🛠 حالت تعمیر روشن شد؛ بازدیدکننده‌ها صفحه‌ی «در دست تعمیر» را می‌بینند.\n\n💡 موتورهای جست‌وجو هم کد ۵۰۳ می‌گیرند، یعنی سایت را حذف‌شده حساب نمی‌کنند."
            : '✅ حالت تعمیر خاموش شد و سایت به حالت عادی برگشت.';
    }

    /** @param array<string, mixed> $data */
    private static function runIpBlock(array $data): string
    {
        $block = (string) ($data['state'] ?? '1') === '1';
        $ips = CpanelService::blockIp(
            self::accountClient(),
            (string) $data['docroot'],
            (string) $data['ip'],
            $block,
        );

        $text = $block
            ? '⛔️ <code>' . Format::escapeHtml((string) $data['ip']) . '</code> مسدود شد.'
            : '✅ <code>' . Format::escapeHtml((string) $data['ip']) . '</code> آزاد شد.';

        return $text . "\n\n" . ($ips === []
            ? 'الان هیچ آی‌پی مسدودی روی این پوشه نیست.'
            : 'آی‌پی‌های مسدود: <code>' . Format::escapeHtml(implode('، ', $ips)) . '</code>');
    }

    /** @param array<string, mixed> $data */
    private static function runDirProtect(array $data): string
    {
        $client = self::accountClient();
        $dir = (string) $data['dir'];

        CpanelService::protectDirectory($client, $dir, basename($dir), true);
        CpanelService::addProtectedUser($client, $dir, (string) $data['user'], (string) $data['password']);

        return '🔐 پوشه‌ی <code>' . Format::escapeHtml($dir) . "</code> با رمز محافظت شد.\n\n"
            . 'از این به بعد مرورگر برای بازکردنش نام کاربری و رمز می‌خواهد.';
    }

    /** @param array<string, mixed> $data */
    private static function runFavAdd(array $data): string
    {
        $value = trim((string) $data['value']);
        Favorites::add(Favorites::guessType($value), $value);

        return '⭐ <code>' . Format::escapeHtml($value) . '</code> به میان‌برها اضافه شد.';
    }

    /** @param array<string, mixed> $data */
    private static function runFavDel(array $data): string
    {
        Favorites::remove((string) $data['value']);

        return '🗑 میان‌بر حذف شد.';
    }

    /**
     * دانلود یک فایل از هاست و فرستادنش در تلگرام.
     *
     * چون خروجی این عملیات «فایل» است نه متن، خودش پیام را می‌فرستد و به
     * موتور جریان یک خلاصه برمی‌گرداند.
     *
     * @param array<string, mixed> $data
     */
    private static function runFileDownload(array $data): string
    {
        return self::deliverFile((string) $data['path']);
    }

    /** @param array<string, mixed> $data */
    private static function runFolderDownload(array $data): string
    {
        $archive = CpanelService::compressPath(self::accountClient(), (string) $data['path']);

        return self::deliverFile($archive, 'پوشه فشرده شد و فایلش را فرستادم.');
    }

    /** @param array<string, mixed> $data */
    private static function runFileEdit(array $data): string
    {
        $client = self::accountClient();
        $path = (string) $data['path'];
        $backup = self::backupBeforeChange($client, $path);

        CpanelService::saveFile($client, $path, (string) $data['content']);

        return '✅ فایل <code>' . Format::escapeHtml($path) . '</code> ذخیره شد.' . $backup;
    }

    /** @param array<string, mixed> $data */
    private static function runFileCreate(array $data): string
    {
        $path = rtrim((string) $data['dir'], '/') . '/' . ltrim((string) $data['name'], '/');
        CpanelService::saveFile(self::accountClient(), $path, (string) ($data['content'] ?? ''));

        return '✅ فایل ساخته شد: <code>' . Format::escapeHtml($path) . '</code>';
    }

    /** @param array<string, mixed> $data */
    private static function runFileFind(array $data): string
    {
        $found = CpanelService::findFiles(self::accountClient(), (string) $data['dir'], (string) $data['needle']);

        return Render::fileList('🔎 <b>نتیجه‌ی جست‌وجوی فایل «' . Format::escapeHtml((string) $data['needle']) . '»</b>', $found);
    }

    /** @param array<string, mixed> $data */
    private static function runDnsBulk(array $data): string
    {
        $client = self::accountClient();
        $domains = CpanelService::domains($client);
        $zones = array_values(array_filter(array_merge([$domains['main_domain']], $domains['addon_domains'])));

        $ok = [];
        $failed = [];
        foreach ($zones as $zone) {
            try {
                CpanelService::addDnsRecord(
                    $client,
                    $zone,
                    (string) $data['name'],
                    (string) $data['type'],
                    preg_split('/\s+/', trim((string) $data['value'])) ?: [],
                );
                $ok[] = $zone;
            } catch (Throwable $e) {
                $failed[] = $zone . ' (' . mb_substr($e->getMessage(), 0, 60) . ')';
            }
        }

        $text = '🧭 رکورد <b>' . Format::escapeHtml(strtoupper((string) $data['type'])) . '</b> روی ' . count($ok) . " دامنه اضافه شد.";
        if ($ok !== []) {
            $text .= "\n\n✅ " . Format::escapeHtml(implode('، ', $ok));
        }
        if ($failed !== []) {
            $text .= "\n\n❌ " . Format::escapeHtml(implode('، ', $failed));
        }

        return $text;
    }

    /** @param array<string, mixed> $data */
    private static function runAlertThreshold(array $data): string
    {
        $value = trim((string) $data['value']);
        if (!ctype_digit($value)) {
            throw CpanelError::config('فقط عدد بفرستید (مثل <code>85</code>).');
        }
        Settings::set((string) $data['kind'], $value);

        return '✅ آستانه ذخیره شد: <code>' . Format::escapeHtml($value) . '</code>';
    }

    /**
     * اگر «بکاپ قبل از تغییر» روشن باشد، قبل از دست‌زدن به یک مسیر یک zip
     * می‌گیرد و مسیرش را نگه می‌دارد تا بشود برگرداند.
     */
    private static function backupBeforeChange(CpanelClient $client, string $path): string
    {
        if (!Settings::bool(Settings::SAFE_DELETE, true)) {
            return '';
        }

        try {
            $archive = CpanelService::compressPath($client, $path);
            Settings::set(Settings::LAST_BACKUP, $archive);

            return "\n\n🛡 نسخه‌ی پشتیبان: <code>" . Format::escapeHtml($archive) . '</code>';
        } catch (Throwable) {
            // اگر نشد بکاپ گرفت، خود عملیات نباید متوقف شود.
            return '';
        }
    }

    /** @param array<string, mixed> $data */
    private static function runDnsTemplate(array $data): string
    {
        $zone = (string) $data['zone'];
        $template = (string) $data['template'];
        $templates = CpanelService::dnsTemplates($zone);
        $label = (string) ($templates[$template]['label'] ?? $template);

        $result = CpanelService::applyDnsTemplate(self::accountClient(), $zone, $template);

        return Render::dnsTemplate($zone, $label, $result);
    }

    /** @param array<string, mixed> $data */
    private static function runDnsExport(array $data): string
    {
        $zone = (string) $data['zone'];
        $zoneData = CpanelService::dnsZone(self::accountClient(), $zone);
        if ($zoneData['records'] === []) {
            return '⚠️ رکوردی در زون <code>' . Format::escapeHtml($zone) . '</code> پیدا نشد.';
        }

        self::$pendingFile = [
            'name' => $zone . '-' . gmdate('Ymd') . '.zone.txt',
            'content' => CpanelService::zoneToBind($zone, $zoneData),
        ];

        return '📤 فایل زون <code>' . Format::escapeHtml($zone) . '</code> با '
            . count($zoneData['records']) . " رکورد آماده شد.\n\n"
            . '💡 همین فایل را نگه دارید؛ اگر روزی زون خراب شد، رکوردها را از رویش برمی‌گردانید.';
    }

    /** @param array<string, mixed> $data */
    private static function runFileTransfer(array $data): string
    {
        $targetId = (string) $data['server'];
        $target = ServerStore::get($targetId);
        if ($target === null) {
            throw CpanelError::config("سروری با شناسه‌ی «{$targetId}» پیدا نشد.");
        }

        $to = ServerStore::client($targetId);
        if ($to->isWhm() && $to->targetUser() === '') {
            throw CpanelError::config(
                'سرور مقصد از نوع WHM است و کاربر مقصدش مشخص نیست؛ اول آن سرور را فعال کنید و «🎯 انتخاب کاربر مقصد» را بزنید.'
            );
        }

        $result = CpanelService::transferFile(self::accountClient(), (string) $data['path'], $to, (string) $data['dir']);

        return '🔁 <code>' . Format::escapeHtml($result['name']) . '</code> — ' . Format::bytes((float) $result['size'])
            . "\nمنتقل شد به <b>" . Format::escapeHtml((string) $target['label']) . '</b>: <code>'
            . Format::escapeHtml($result['destination']) . '</code>';
    }

    /** @param array<string, mixed> $data */
    private static function runSrvCompare(array $data): string
    {
        $first = (string) $data['first'];
        $second = (string) $data['second'];
        if ($first === $second) {
            return '⚠️ دو سرور متفاوت انتخاب کنید.';
        }

        $side = static function (string $id): array {
            $server = ServerStore::get($id);
            $label = $server === null ? $id : (string) $server['label'] . ' (' . $id . ')';
            $blank = [
                'label' => $label,
                'host' => '',
                'user' => '',
                'main_domain' => '',
                'cpanel' => '',
                'php' => '',
                'usage' => [],
                'counts' => ['emails' => 0, 'databases' => 0, 'ftp' => 0, 'subdomains' => 0, 'addon' => 0],
                'error' => '',
            ];

            try {
                $client = ServerStore::client($id);
                if ($client->isWhm() && $client->targetUser() === '') {
                    // روی سرور WHM بدون کاربر مقصد، آمار سطح حساب معنا ندارد.
                    return array_merge($blank, [
                        'error' => 'این سرور از نوع WHM است و کاربر مقصدش مشخص نشده؛ اول آن را فعال کنید و «🎯 انتخاب کاربر مقصد» را بزنید.',
                    ]);
                }

                return CpanelService::snapshot($client, $label);
            } catch (Throwable $e) {
                return array_merge($blank, ['error' => $e->getMessage()]);
            }
        };

        return Render::serverCompare($side($first), $side($second));
    }

    /** @param array<string, mixed> $data */
    private static function runPhpTest(array $data): string
    {
        $client = self::accountClient();
        $domain = (string) $data['domain'];

        $docroot = '';
        foreach (CpanelService::domainsData($client) as $detail) {
            if (strcasecmp($detail['domain'], $domain) === 0 && $detail['docroot'] !== '') {
                $docroot = $detail['docroot'];
                break;
            }
        }
        if ($docroot === '') {
            throw CpanelError::config("پوشه‌ی سایت دامنه‌ی «{$domain}» پیدا نشد؛ از «🌐 دامنه‌ها» ببینید کدام پوشه به این دامنه وصل است.");
        }

        return Render::phpTest(CpanelService::phpLiveTest($client, $domain, $docroot));
    }

    /** @param array<string, mixed> $data */
    private static function runHotlink(array $data): string
    {
        $client = self::accountClient();
        $enable = (string) ($data['state'] ?? '1') === '1';
        $docroot = (string) $data['docroot'];

        $allowed = [(string) ($data['allowed'] ?? '')];
        if ($enable) {
            // دامنه‌های خود حساب همیشه اجازه دارند، وگرنه تصویرهای خود سایت هم بسته می‌شوند.
            $domains = CpanelService::domains($client);
            foreach (array_merge([$domains['main_domain']], $domains['addon_domains'], $domains['sub_domains']) as $domain) {
                $allowed[] = (string) $domain;
            }
        }

        CpanelService::setHotlinkProtection($client, $docroot, $enable, $allowed);

        if (!$enable) {
            return '✅ محدودیت هات‌لینک برداشته شد؛ همه دوباره می‌توانند مستقیم به فایل‌ها لینک بدهند.';
        }

        $hosts = CpanelService::hotlinkHosts($allowed);

        return "🖼 جلوگیری از هات‌لینک روشن شد.\n\n"
            . 'از این به بعد فقط ' . count($hosts) . " دامنه می‌توانند مستقیم به تصویر و ویدیوی این سایت لینک بدهند.\n\n"
            . '💡 اگر تصویری در جای دیگری (مثل شبکه‌های اجتماعی) نشان داده نشد، دامنه‌اش را به فهرست مجازها اضافه کنید.';
    }

    /** @param array<string, mixed> $data */
    private static function runScheduleOp(array $data): string
    {
        $action = (string) $data['action'];
        $minutes = (int) trim((string) $data['delay']);
        if ($minutes < 1) {
            throw CpanelError::config('زمان اجرا باید حداقل یک دقیقه‌ی دیگر باشد.');
        }
        if ($minutes > 43200) {
            throw CpanelError::config('حداکثر یک ماه (۴۳۲۰۰ دقیقه) جلوتر می‌شود زمان‌بندی کرد.');
        }

        $docroot = trim((string) ($data['docroot'] ?? ''));
        if (in_array($action, ['maintenance_on', 'maintenance_off'], true) && $docroot === '') {
            throw CpanelError::config('برای حالت تعمیر باید پوشه‌ی سایت را هم مشخص کنید.');
        }

        $runAt = time() + $minutes * 60;
        $params = $docroot === '' ? [] : ['docroot' => $docroot];
        OpQueue::add($action, $runAt, $params, (string) (ServerStore::activeId() ?? ''));

        return '⏳ ثبت شد: <b>' . Format::escapeHtml(OpQueue::ACTIONS[$action] ?? $action) . '</b>' . "\n"
            . '📅 ' . Format::escapeHtml(Format::timestampWithRemaining($runAt)) . "\n\n"
            . 'ℹ️ کار با همان تایمر هشدارها اجرا می‌شود و نتیجه‌اش همین‌جا اعلام می‌شود.';
    }

    /** @param array<string, mixed> $data */
    private static function runQueueDel(array $data): string
    {
        return OpQueue::remove((string) $data['id'])
            ? '🗑 کار از صف برداشته شد.'
            : '⚠️ کاری با این شناسه در صف نبود (شاید همین حالا اجرا شده باشد).';
    }

    /**
     * فایل را از هاست می‌گیرد و در تلگرام می‌فرستد.
     */
    private static function deliverFile(string $path, string $note = ''): string
    {
        $client = self::accountClient();
        $path = CpanelService::normalizePath($path);
        $contents = CpanelService::downloadFile($client, $path);
        $size = strlen($contents);

        if ($size === 0) {
            return '⚠️ فایل خالی بود یا خوانده نشد: <code>' . Format::escapeHtml($path) . '</code>';
        }
        if ($size > self::MAX_DOWNLOAD) {
            return '⚠️ حجم فایل ' . Format::bytes((float) $size) . ' است و تلگرام اجازه‌ی فرستادن بیشتر از '
                . Format::bytes(self::MAX_DOWNLOAD) . " را نمی‌دهد.\n\n💡 اول فشرده‌اش کنید یا تکه‌تکه بفرستید.";
        }

        self::$pendingFile = ['name' => basename($path), 'content' => $contents];

        return ($note !== '' ? $note . "\n\n" : '') . '⬇️ <code>' . Format::escapeHtml($path) . '</code> — '
            . Format::bytes((float) $size);
    }

    /** @param array<string, mixed> $data */
    private static function runMailPurge(array $data): string
    {
        $result = CpanelService::purgeMailFolders(self::accountClient(), (string) $data['email']);

        return $result['deleted'] === 0
            ? '✅ چیزی برای پاک‌کردن نبود؛ Trash و Spam این صندوق خالی‌اند.'
            : '🧽 ' . $result['deleted'] . ' نامه از ' . implode('، ', $result['folders']) . ' پاک شد.';
    }

    /** @param array<string, mixed> $data */
    private static function runResponderAdd(array $data): string
    {
        CpanelService::addAutoResponder(
            self::accountClient(),
            (string) $data['email'],
            (string) $data['subject'],
            (string) $data['body'],
        );

        return '🤖 پاسخ خودکار برای <code>' . Format::escapeHtml((string) $data['email']) . '</code> فعال شد.';
    }

    /** @param array<string, mixed> $data */
    private static function runResponderDel(array $data): string
    {
        CpanelService::deleteAutoResponder(self::accountClient(), (string) $data['email']);

        return '🗑 پاسخ خودکار حذف شد.';
    }

    /** @param array<string, mixed> $data */
    private static function runFtpPasswd(array $data): string
    {
        CpanelService::setFtpPassword(self::accountClient(), (string) $data['user'], (string) $data['password']);

        return '✅ رمز حساب FTP <code>' . Format::escapeHtml((string) $data['user']) . '</code> عوض شد.';
    }

    /** @param array<string, mixed> $data */
    private static function runFtpQuota(array $data): string
    {
        CpanelService::setFtpQuota(self::accountClient(), (string) $data['user'], $data['quota']);

        return '✅ سهمیه‌ی <code>' . Format::escapeHtml((string) $data['user']) . '</code> به‌روزرسانی شد.';
    }

    /** @param array<string, mixed> $data */
    private static function runFtpKill(array $data): string
    {
        // مقدار انتخاب‌شده به‌شکل «کاربر|pid» ذخیره شده است.
        [$login, $pid] = array_pad(explode('|', (string) $data['session'], 2), 2, '');
        CpanelService::killFtpSession(self::accountClient(), $login, $pid);

        return '✂️ نشست <code>' . Format::escapeHtml($login) . '</code> قطع شد.';
    }

    /** @param array<string, mixed> $data */
    private static function runAddonAdd(array $data): string
    {
        CpanelService::addAddonDomain(self::accountClient(), (string) $data['domain'], '', (string) ($data['dir'] ?? ''));

        return '✅ دامنه‌ی <code>' . Format::escapeHtml((string) $data['domain']) . "</code> اضافه شد.\n\n"
            . '💡 قدم بعد: سورس سایت را در پوشه‌اش آپلود کنید و برایش SSL بگیرید.';
    }

    /** @param array<string, mixed> $data */
    private static function runAddonDel(array $data): string
    {
        CpanelService::deleteAddonDomain(self::accountClient(), (string) $data['domain']);

        return '🗑 دامنه‌ی <code>' . Format::escapeHtml((string) $data['domain']) . '</code> از حساب حذف شد.';
    }

    /** @param array<string, mixed> $data */
    private static function runParkAdd(array $data): string
    {
        CpanelService::parkDomain(self::accountClient(), (string) $data['domain']);

        return '🅿️ دامنه‌ی <code>' . Format::escapeHtml((string) $data['domain']) . '</code> پارک شد و همان سایت اصلی را نشان می‌دهد.';
    }

    /** @param array<string, mixed> $data */
    private static function runDnsFix(array $data): string
    {
        CpanelService::setARecord(
            self::accountClient(),
            (string) $data['zone'],
            (string) $data['name'],
            (string) $data['ip'],
        );

        return '✅ رکورد A تنظیم شد روی <code>' . Format::escapeHtml((string) $data['ip']) . "</code>.\n\n"
            . '⏳ تا چند دقیقه (گاهی چند ساعت) طول می‌کشد تا همه‌جا اعمال شود.';
    }

    /** @param array<string, mixed> $data */
    private static function runSearch(array $data): string
    {
        $term = (string) $data['term'];

        return Render::search($term, CpanelService::search(self::accountClient(), $term));
    }

    /** @param array<string, mixed> $data */
    private static function runEmailBulkAdd(array $data): string
    {
        $lines = preg_split('/\r\n|\r|\n/', (string) $data['list']) ?: [];
        $results = CpanelService::bulkAddEmails(
            self::accountClient(),
            (string) $data['domain'],
            $lines,
            $data['quota'] ?? 0,
        );

        return Render::bulkResult('👥 <b>ساخت گروهی ایمیل</b>', $results);
    }

    /** @param array<string, mixed> $data */
    private static function runEmailBulkDel(array $data): string
    {
        $lines = preg_split('/\r\n|\r|\n/', (string) $data['list']) ?: [];
        $results = CpanelService::bulkDeleteEmails(self::accountClient(), $lines);

        return Render::bulkResult('🗑 <b>حذف گروهی ایمیل</b>', $results);
    }

    /** @param array<string, mixed> $data */
    private static function runForceHttps(array $data): string
    {
        $enable = (string) ($data['state'] ?? '1') === '1';
        CpanelService::forceHttps(self::accountClient(), (string) $data['domain'], $enable);

        return $enable
            ? '✅ از این به بعد <code>' . Format::escapeHtml((string) $data['domain']) . '</code> همیشه روی HTTPS باز می‌شود.'
            : '⛔️ اجبار HTTPS برای <code>' . Format::escapeHtml((string) $data['domain']) . '</code> خاموش شد.';
    }

    /** @param array<string, mixed> $data */
    private static function runCleanupRun(array $data): string
    {
        $count = CpanelService::purgePath(self::accountClient(), (string) $data['path']);

        return '🧹 ' . $count . ' مورد از <code>' . Format::escapeHtml((string) $data['path']) . '</code> پاک شد.';
    }

    /** @param array<string, mixed> $data */
    private static function runSiteCheck(array $data): string
    {
        $domain = SiteCheck::cleanDomain((string) $data['domain']);
        $info = CpanelService::accountInfo(self::accountClient());
        $serverIp = '';
        foreach (CpanelService::domainsData(self::accountClient()) as $detail) {
            if ($detail['ip'] !== '') {
                $serverIp = $detail['ip'];
                break;
            }
        }

        return Render::siteCheck(SiteCheck::run($domain, $serverIp !== '' ? $serverIp : ($info['ip'] ?? null)));
    }

    /** @param array<string, mixed> $data */
    private static function runEmailSettings(array $data): string
    {
        return Render::emailSettings(
            (string) $data['email'],
            CpanelService::emailClientSettings(self::accountClient(), (string) $data['email']),
        );
    }

    /** @param array<string, mixed> $data */
    private static function runPhpLimits(array $data): string
    {
        return Render::phpLimits(
            (string) $data['vhost'],
            CpanelService::phpLimits(self::accountClient(), (string) $data['vhost']),
        );
    }

    /** @param array<string, mixed> $data */
    private static function runCronEdit(array $data): string
    {
        $schedule = CpanelService::parseSchedule((string) $data['schedule']);
        if ($schedule === null) {
            throw CpanelError::config('زمان‌بندی باید ۵ فیلد کران باشد، مثل <code>*/5 * * * *</code>.');
        }
        CpanelService::editCron(self::accountClient(), (string) $data['key'], $schedule, (string) $data['command']);

        return '✅ کران‌جاب به‌روزرسانی شد: <code>' . Format::escapeHtml((string) $data['schedule']) . '</code>';
    }

    /** @param array<string, mixed> $data */
    private static function runFileRename(array $data): string
    {
        CpanelService::renamePath(self::accountClient(), (string) $data['path'], (string) $data['name']);

        return '✅ نام تغییر کرد به <code>' . Format::escapeHtml((string) $data['name']) . '</code>';
    }

    /** @param array<string, mixed> $data */
    private static function runFileMove(array $data): string
    {
        $copy = (string) ($data['mode'] ?? 'move') === 'copy';
        $client = self::accountClient();

        if ($copy) {
            CpanelService::copyPath($client, (string) $data['path'], (string) $data['destination']);
        } else {
            CpanelService::movePath($client, (string) $data['path'], (string) $data['destination']);
        }

        return ($copy ? '✅ کپی شد به ' : '✅ منتقل شد به ') . '<code>' . Format::escapeHtml((string) $data['destination']) . '</code>';
    }

    /** @param array<string, mixed> $data */
    private static function runFileChmod(array $data): string
    {
        CpanelService::chmodPath(self::accountClient(), (string) $data['path'], (string) $data['permissions']);

        return '✅ سطح دسترسی <code>' . Format::escapeHtml((string) $data['path']) . '</code> شد '
            . Format::escapeHtml((string) $data['permissions']) . '.';
    }

    /** @param array<string, mixed> $data */
    private static function runFileCompress(array $data): string
    {
        $archive = CpanelService::compressPath(self::accountClient(), (string) $data['path']);

        return '✅ فشرده شد: <code>' . Format::escapeHtml($archive) . '</code>';
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
            . "✉️ <b>ایمیل</b> — ساخت، حذف، رمز، سهمیه، فوروارد و مصرف هر صندوق\n"
            . "🗄 <b>دیتابیس</b> — دیتابیس و کاربر MySQL، دادن دسترسی (سه قدم ساده) و حجم هر دیتابیس\n"
            . "📂 <b>فایل‌ها</b> — مرور پوشه‌ها، آپلود و دانلود از تلگرام، باز کردن zip، گشت امنیتی و انتقال فایل بین دو هاست\n"
            . "🌐 <b>دامنه‌ها</b> — ادان‌دامنه و ساب‌دامنه، 🧭 DNS (با قالب‌های آماده و خروجی زون)، 🔒 SSL، 🖼 جلوگیری از هات‌لینک\n"
            . "📁 <b>FTP</b> — حساب‌های انتقال فایل\n"
            . "⏰ <b>کران</b> — کارهای زمان‌بندی‌شده و ایمیل خروجی‌شان\n"
            . "🐘 <b>PHP</b> — نسخه‌ی هر دامنه و تست زنده‌ی همان نسخه‌ای که وب‌سرور اجرا می‌کند\n"
            . '💾 <b>بکاپ</b> — ساخت بکاپ کامل، بکاپ خودکار و زمان‌بندی کارها برای بعد';

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
