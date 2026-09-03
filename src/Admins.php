<?php

declare(strict_types=1);

/**
 * ادمین‌های ربات.
 *
 * دو منبع دارد:
 *   1) متغیرهای محیطی ADMIN_ID / ADMIN_IDS — «مالک‌های ثابت»؛ از داخل ربات
 *      قابل حذف نیستند.
 *   2) data/admins.json — ادمین‌هایی که از داخل خود ربات اضافه می‌شوند.
 *
 * اگر هیچ ادمینی تعریف نشده باشد، ربات «تحویل‌نگرفته» است: نصب‌کننده یک کد
 * یک‌بارمصرف چاپ می‌کند و اولین کسی که آن کد را برای ربات بفرستد مالک می‌شود.
 * این‌طوری نصب فقط یک سؤال دارد (توکن ربات) و بقیه‌ی تنظیمات از داخل ربات
 * انجام می‌شود.
 */
final class Admins
{
    /**
     * @return array<int, int>
     */
    public static function all(): array
    {
        return array_values(array_unique(array_merge(self::fromEnv(), self::fromFile())));
    }

    public static function isAdmin(int $id): bool
    {
        return in_array($id, self::all(), true);
    }

    public static function isClaimed(): bool
    {
        return self::all() !== [];
    }

    /**
     * ادمین‌هایی که از داخل ربات اضافه شده‌اند (یعنی قابل حذف‌اند).
     *
     * @return array<int, int>
     */
    public static function fromFile(): array
    {
        $state = self::load();

        return array_values(array_unique(array_map('intval', $state['admins'])));
    }

    /**
     * @return array<int, int>
     */
    public static function fromEnv(): array
    {
        $raw = trim((string) (getenv('ADMIN_IDS') ?: '')) . ',' . trim((string) (getenv('ADMIN_ID') ?: ''));

        $ids = [];
        foreach (explode(',', $raw) as $part) {
            $part = trim($part);
            if ($part !== '' && ctype_digit($part)) {
                $ids[] = (int) $part;
            }
        }

        return array_values(array_unique($ids));
    }

    public static function add(int $id): void
    {
        $state = self::load();
        if (!in_array($id, array_map('intval', $state['admins']), true)) {
            $state['admins'][] = $id;
        }
        self::save($state);
    }

    public static function remove(int $id): void
    {
        $state = self::load();
        $state['admins'] = array_values(array_filter(
            array_map('intval', $state['admins']),
            static fn (int $existing): bool => $existing !== $id,
        ));
        self::save($state);
    }

    /**
     * کد تحویل‌گرفتن ربات؛ اگر نبود ساخته و ذخیره می‌شود.
     */
    public static function claimCode(): string
    {
        $state = self::load();
        if (trim((string) $state['claim_code']) === '') {
            $state['claim_code'] = self::generateCode();
            self::save($state);
        }

        return (string) $state['claim_code'];
    }

    public static function resetClaimCode(): string
    {
        $state = self::load();
        $state['claim_code'] = self::generateCode();
        self::save($state);

        return (string) $state['claim_code'];
    }

    /**
     * تلاش برای تحویل‌گرفتن ربات با کد. فقط وقتی کار می‌کند که هیچ ادمینی
     * تعریف نشده باشد، و بعد از موفقیت کد عوض می‌شود تا یک‌بارمصرف باشد.
     */
    public static function claim(int $id, string $code): bool
    {
        if (self::isClaimed()) {
            return false;
        }

        $expected = self::claimCode();
        $given = strtoupper(trim(str_replace([' ', '_'], ['', '-'], $code)));
        if ($expected === '' || !hash_equals($expected, $given)) {
            return false;
        }

        self::add($id);
        self::resetClaimCode();

        return true;
    }

    private static function generateCode(): string
    {
        // بدون کاراکترهای شبیه‌به‌هم (O/0، I/1) تا خواندن از روی ترمینال و
        // تایپ‌کردنش توی تلگرام ساده باشد.
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZ23456789';
        $max = strlen($alphabet) - 1;
        $code = '';
        for ($i = 0; $i < 8; $i++) {
            if ($i === 4) {
                $code .= '-';
            }
            $code .= $alphabet[random_int(0, $max)];
        }

        return $code;
    }

    /**
     * @return array{admins: array<int, mixed>, claim_code: string}
     */
    private static function load(): array
    {
        $path = self::path();
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (!is_array($decoded)) {
            $decoded = [];
        }

        return [
            'admins' => is_array($decoded['admins'] ?? null) ? $decoded['admins'] : [],
            'claim_code' => (string) ($decoded['claim_code'] ?? ''),
        ];
    }

    /**
     * @param array{admins: array<int, mixed>, claim_code: string} $state
     */
    private static function save(array $state): void
    {
        $path = self::path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        file_put_contents($path, json_encode($state, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        @chmod($path, 0600);
    }

    private static function path(): string
    {
        return (getenv('KAN_DATA_DIR') ?: dirname(__DIR__) . '/data') . '/admins.json';
    }
}
