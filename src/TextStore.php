<?php

declare(strict_types=1);

/**
 * ذخیره‌ی متن‌های قابل‌ویرایش ربات روی دیسک، تا ادمین بتونه بدون تغییر کد و
 * دیپلوی دوباره، از توی خود تلگرام عوضشون کنه.
 */
final class TextStore
{
    public const KEY_WELCOME = 'welcome';

    private const DEFAULTS = [
        self::KEY_WELCOME => "سلام! این ربات برای مدیریت هاست اشتراکی cPanel شماست.\n\n"
            . "1️⃣ اول با /connect هاست، یوزرنیم و رمز یا API Token‌تون رو به ربات بدید (فقط همین چت نگه‌شون می‌داره، رمزنگاری‌شده روی دیسک).\n"
            . "2️⃣ بعدش می‌تونید مدیریتش کنید:\n\n"
            . "🖥 /info — اطلاعات اکانت و فضای دیسک\n"
            . "🌐 /domains — لیست دامنه/زیردامنه/addon/parked\n"
            . "➕ /subdomain add <نام> <دامنه> — ساخت زیردامنه\n"
            . "➖ /subdomain del <زیردامنه‌کامل> — حذف زیردامنه\n"
            . "📧 /email list [دامنه] — لیست ایمیل‌ها\n"
            . "📧 /email add <ایمیل> <رمز> [حجم‌مگابایت] — ساخت ایمیل\n"
            . "📧 /email passwd <ایمیل> <رمزجدید> — تغییر رمز ایمیل\n"
            . "📧 /email del <ایمیل> — حذف ایمیل\n"
            . "🗄 /db list — لیست دیتابیس‌ها و یوزرها\n"
            . "🗄 /db create <نام> — ساخت دیتابیس\n"
            . "🗄 /db delete <نام> — حذف دیتابیس\n"
            . "🗄 /db user add <یوزر> <رمز> — ساخت یوزر دیتابیس\n"
            . "🗄 /db user del <یوزر> — حذف یوزر دیتابیس\n"
            . "🗄 /db grant <یوزر> <دیتابیس> [دسترسی=ALL PRIVILEGES] — دسترسی یوزر به دیتابیس\n"
            . "📁 /ftp list — لیست اکانت‌های FTP\n"
            . "📁 /ftp add <یوزر> <رمز> [حجم‌مگابایت=0 یعنی نامحدود] — ساخت FTP\n"
            . "📁 /ftp del <یوزر> — حذف FTP\n"
            . "🧭 /dns list <دامنه> — رکوردهای DNS\n"
            . "🧭 /dns add <دامنه> <نام> <نوع> <مقدار> [ttl=14400] — افزودن رکورد\n"
            . "🧭 /dns del <دامنه> <شماره‌خط> — حذف رکورد\n"
            . "🔑 /password — تغییر رمز اکانت cPanel (با ویزارد قدم‌به‌قدم)\n"
            . "🗂 /files list [مسیر] — لیست فایل‌ها/پوشه‌ها\n"
            . "🗂 /files view <مسیر> — نمایش محتوای فایل متنی\n"
            . "🗂 /files write <مسیر> — بازنویسی محتوای فایل (محتوا رو پیام بعدی می‌فرستید)\n"
            . "🗂 /files mkdir <مسیر-والد> <نام> — ساخت پوشه\n"
            . "🗂 /files rename <قبلی> <جدید> / /files copy <مبدأ> <مقصد> / /files del <مسیر>\n"
            . "💻 /ssh <دستور> — اجرای واقعی دستور شل روی سرور (فقط با اتصال «رمز عبور»، اگه SSH فعال باشه)\n"
            . "💻 /sshport <پورت> — تنظیم پورت SSH در صورت غیراستاندارد بودن (پیش‌فرض 22)\n"
            . "🧪 /raw <ماژول> <تابع> [کلید=مقدار ...] — فراخوانی مستقیم UAPI برای موارد پیشرفته/نایاب\n\n"
            . "🔌 /disconnect — قطع اتصال و پاک‌کردن اطلاعات هاست از ربات\n"
            . "❌ /cancel — لغو هر ویزارد نیمه‌کاره\n"
            . '📶 /status برای همه بازه، /admin فقط برای ادمین.',
    ];

    /** @var array<string, string> */
    public const LABELS = [
        self::KEY_WELCOME => 'پیام خوش‌آمد / راهنما',
    ];

    public static function get(string $key): string
    {
        $data = self::load();

        return $data[$key] ?? self::DEFAULTS[$key] ?? '';
    }

    public static function set(string $key, string $value): void
    {
        $data = self::load();
        $data[$key] = $value;
        self::save($data);
    }

    public static function reset(string $key): void
    {
        $data = self::load();
        unset($data[$key]);
        self::save($data);
    }

    /**
     * @return array<string, string>
     */
    private static function load(): array
    {
        $path = self::path();
        if (!is_file($path)) {
            return [];
        }
        $data = json_decode((string) file_get_contents($path), true);

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<string, string> $data
     */
    private static function save(array $data): void
    {
        $path = self::path();
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    }

    private static function path(): string
    {
        return dirname(__DIR__) . '/data/texts.json';
    }
}
