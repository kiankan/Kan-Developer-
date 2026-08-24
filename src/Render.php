<?php

declare(strict_types=1);

/**
 * قالب‌بندی پاسخ‌های cPanel برای تلگرام. چون شکل دقیق فیلدهای هر endpoint
 * می‌تونه بین نسخه‌های مختلف cPanel کمی فرق کنه، هر تابع سعی می‌کنه چند نام
 * محتمل رو امتحان کنه و اگه چیزی رو نشناخت، به‌جای مخفی‌کردن اطلاعات، خروجی
 * خام رو (کوتاه‌شده) نشون می‌ده. برای دیدن خروجی کامل و بدون واسطه همیشه
 * دستور /raw هم هست.
 */
final class Render
{
    public static function connected(string $host, int $port, string $username, array $domainsData, ?string $warning = null): string
    {
        $main = self::pick($domainsData, ['main_domain']);
        $lines = [
            '✅ اتصال با موفقیت برقرار شد.',
            "🖥 هاست: {$host}:{$port}",
            "👤 یوزرنیم: {$username}",
        ];
        if ($main !== null) {
            $lines[] = "🌐 دامنه اصلی: {$main}";
        }
        if ($warning !== null) {
            $lines[] = '';
            $lines[] = "⚠️ دسترسی به اطلاعات دامنه محدود بود: {$warning}";
            $lines[] = 'این معمولاً یعنی یوزرنیم/توکن فقط به یک ویژگی خاص (مثلاً فقط Zone Edit/DNS) محدود شده. اتصال ذخیره شد؛ دستورهای مربوط به همون ویژگی باید کار کنن، ولی بقیه (مثل /info یا /email) ممکنه با خطای دسترسی مواجه بشن.';
        }
        $lines[] = '';
        $lines[] = 'حالا می‌تونید با /info، /domains، /email، /db، /ftp، /dns و ... هاست رو مدیریت کنید. برای راهنما: /help';

        return implode("\n", $lines);
    }

    public static function accountInfo(string $host, int $port, string $username, string $authType, array $quota, array $domainsData): string
    {
        $used = self::pick($quota, ['megabytes_used', 'used', '_used']);
        $limit = self::pick($quota, ['megabytes_limit', 'megabyte_limit', 'limit', '_limit']);
        $main = self::pick($domainsData, ['main_domain']);

        $lines = [
            "🖥 هاست: <b>{$host}:{$port}</b>",
            "👤 یوزرنیم: <b>{$username}</b>",
            '🔑 نوع احراز هویت: ' . ($authType === 'token' ? 'API Token' : 'رمز عبور'),
        ];
        if ($main !== null) {
            $lines[] = "🌐 دامنه اصلی: {$main}";
        }
        $lines[] = '';
        if ($used !== null) {
            $limitText = ($limit === null || (float) $limit <= 0)
                ? 'نامحدود'
                : Format::megabytes($limit);
            $lines[] = '💾 فضای دیسک: ' . Format::megabytes($used) . ' از ' . $limitText;
        } else {
            $lines[] = '💾 اطلاعات فضای دیسک قابل تشخیص نبود:';
            $lines[] = Format::prettyJson($quota, 800);
        }

        return implode("\n", $lines);
    }

    public static function domains(array $data): string
    {
        $lines = ['🌐 <b>دامنه‌های این هاست</b>', ''];

        $main = self::pick($data, ['main_domain']);
        if ($main !== null) {
            $lines[] = "🏠 اصلی: {$main}";
        }

        $sub = self::pickList($data, ['sub_domains', 'subdomains']);
        if ($sub !== []) {
            $lines[] = '';
            $lines[] = '📂 زیردامنه‌ها:';
            foreach ($sub as $item) {
                $lines[] = '  • ' . self::itemLabel($item, ['domain', 'subdomain']);
            }
        }

        $addon = self::pickList($data, ['addon_domains']);
        if ($addon !== []) {
            $lines[] = '';
            $lines[] = '➕ دامنه‌های addon:';
            foreach ($addon as $item) {
                $lines[] = '  • ' . self::itemLabel($item, ['domain']);
            }
        }

        $parked = self::pickList($data, ['parked_domains']);
        if ($parked !== []) {
            $lines[] = '';
            $lines[] = '🅿️ دامنه‌های parked:';
            foreach ($parked as $item) {
                $lines[] = '  • ' . self::itemLabel($item, ['domain']);
            }
        }

        if ($main === null && $sub === [] && $addon === [] && $parked === []) {
            $lines[] = 'قالب پاسخ شناخته نشد؛ خروجی خام:';
            $lines[] = Format::prettyJson($data);
        }

        return implode("\n", $lines);
    }

    public static function emailList(array $pops, ?string $domain): string
    {
        $title = $domain !== null ? "📧 <b>ایمیل‌های {$domain}</b>" : '📧 <b>ایمیل‌های این هاست</b>';
        if ($pops === []) {
            return "$title\n\nهیچ ایمیلی پیدا نشد.";
        }

        $lines = [$title, ''];
        foreach ($pops as $item) {
            $lines[] = '  • ' . self::itemLabel($item, ['email', 'login', 'user']);
        }

        return implode("\n", $lines);
    }

    public static function dbOverview(array $databases, array $users): string
    {
        $lines = ['🗄 <b>دیتابیس‌های MySQL</b>', ''];
        if ($databases === []) {
            $lines[] = 'دیتابیسی پیدا نشد.';
        } else {
            foreach ($databases as $item) {
                $lines[] = '  • ' . self::itemLabel($item, ['database', 'name']);
            }
        }

        $lines[] = '';
        $lines[] = '👤 <b>یوزرهای MySQL</b>';
        if ($users === []) {
            $lines[] = 'یوزری پیدا نشد.';
        } else {
            foreach ($users as $item) {
                $lines[] = '  • ' . self::itemLabel($item, ['user', 'name']);
            }
        }

        return implode("\n", $lines);
    }

    public static function ftpList(array $accounts): string
    {
        if ($accounts === []) {
            return '📁 <b>اکانت‌های FTP</b>' . "\n\nاکانت FTP‌ای پیدا نشد.";
        }

        $lines = ['📁 <b>اکانت‌های FTP</b>', ''];
        foreach ($accounts as $item) {
            $user = self::itemLabel($item, ['user', 'login']);
            $extra = [];
            $quota = is_array($item) ? self::pick($item, ['quota', '_quota']) : null;
            $diskUsed = is_array($item) ? self::pick($item, ['diskused', 'disk_used']) : null;
            if ($quota !== null) {
                $extra[] = 'حجم: ' . ((float) $quota > 0 ? Format::megabytes($quota) : 'نامحدود');
            }
            if ($diskUsed !== null) {
                $extra[] = 'استفاده‌شده: ' . Format::megabytes($diskUsed);
            }
            $lines[] = '  • ' . $user . ($extra !== [] ? ' (' . implode('، ', $extra) . ')' : '');
        }

        return implode("\n", $lines);
    }

    public static function dnsZone(array $records, string $domain): string
    {
        if ($records === []) {
            return "🧭 <b>رکوردهای DNS {$domain}</b>\n\nرکوردی پیدا نشد.";
        }

        $lines = ["🧭 <b>رکوردهای DNS {$domain}</b>", ''];
        foreach ($records as $record) {
            if (!is_array($record)) {
                $lines[] = '  • ' . (string) $record;

                continue;
            }
            $line = self::pick($record, ['line', 'Line']);
            $name = self::pick($record, ['name', 'dname']);
            $type = self::pick($record, ['type', 'Type']);
            $ttl = self::pick($record, ['ttl']);
            $value = self::pick($record, ['address', 'cname', 'txtdata', 'exchange', 'nsdname', 'data']);

            $prefix = $line !== null ? "[$line] " : '';
            $lines[] = "  • {$prefix}{$name} {$type} " . ($ttl !== null ? "(TTL {$ttl}) " : '') . '→ ' . ($value ?? '?');
        }

        return implode("\n", $lines);
    }

    public static function raw(array $data): string
    {
        return "📦 خروجی خام:\n<pre>" . htmlspecialchars(Format::prettyJson($data), ENT_QUOTES) . '</pre>';
    }

    private static function pick(array $data, array $keys): mixed
    {
        foreach ($keys as $key) {
            if (array_key_exists($key, $data) && $data[$key] !== null && $data[$key] !== '') {
                return $data[$key];
            }
        }

        return null;
    }

    /**
     * @return array<int, mixed>
     */
    private static function pickList(array $data, array $keys): array
    {
        foreach ($keys as $key) {
            if (isset($data[$key]) && is_array($data[$key])) {
                return $data[$key];
            }
        }

        return [];
    }

    private static function itemLabel(mixed $item, array $keys): string
    {
        if (is_string($item)) {
            return $item;
        }
        if (is_array($item)) {
            $picked = self::pick($item, $keys);
            if ($picked !== null) {
                return (string) $picked;
            }

            return implode(', ', array_map(
                static fn ($k, $v) => is_scalar($v) ? "$k=$v" : $k,
                array_keys($item),
                $item,
            ));
        }

        return (string) $item;
    }
}
