<?php

declare(strict_types=1);

/**
 * ذخیره‌ی متن‌ها/قالب‌های قابل‌ویرایش ربات روی دیسک، تا ادمین بتونه بدون
 * تغییر کد و دیپلوی دوباره، از توی خود تلگرام عوضشون کنه.
 */
final class TextStore
{
    public const KEY_WELCOME = 'welcome';
    public const KEY_GIFT_HEADER = 'gift_header';
    public const KEY_GIFT_SECTION = 'gift_section';
    public const KEY_GIFT_FOOTER = 'gift_footer';
    public const KEY_STARS_RATE = 'stars_rate_usd';

    private const DEFAULTS = [
        self::KEY_WELCOME => "سلام! نیازی به دستور نیست — کافیه هر وقت خواستید یکی از این‌ها رو بفرستید تا همون لحظه جواب بگیرید:\n\n"
            . "🎯 یوزرنیم، لینک fragment.com، لینک والت (مثل Tonkeeper) یا اسلاگ/لینک گیفت NFT (t.me/nft/... یا fragment.com/gift/...) → وضعیت مزایده/فروش و زمان باقی‌مانده\n"
            . "🔗 هش تراکنش TON → موفق بوده یا نه\n"
            . "👛 آدرس ولت TON، Bitcoin، Ethereum یا Tron → موجودی به ارز اصلی، تومان و دلار\n"
            . "💱 مقدار + اسم ارز (مثل «1 ton» یا «5 btc») → قیمت لحظه‌ای به تومان و دلار\n\n"
            . "دستورهای صریح هم هست اگه بخواید: /auction /tx /wallet /check\n"
            . "/status برای همه بازه، /admin فقط برای ادمین.",
        self::KEY_GIFT_HEADER => '🎁 Gift: <b>{name}</b>',
        self::KEY_GIFT_SECTION => "📋 <b>{label}: {value}</b>\n<blockquote>💸 {toman} toman\n💵 {usd} dollar\n🌀 {ton} ton\n⭐️ {stars} stars</blockquote>",
        self::KEY_GIFT_FOOTER => "🎁 {source}\n\n📆 {date}",
        self::KEY_STARS_RATE => '0.015',
    ];

    /** @var array<string, string> */
    public const LABELS = [
        self::KEY_WELCOME => 'پیام خوش‌آمد / راهنما',
        self::KEY_GIFT_HEADER => 'سربرگ پیام گیفت',
        self::KEY_GIFT_SECTION => 'بخش قیمت هر ویژگی گیفت',
        self::KEY_GIFT_FOOTER => 'پابرگ پیام گیفت',
        self::KEY_STARS_RATE => 'نرخ هر استارز به دلار (عدد، مثلاً 0.015)',
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
