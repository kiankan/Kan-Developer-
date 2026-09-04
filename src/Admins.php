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
        if (in_array($id, self::all(), true)) {
            return true;
        }

        return self::hasTemporaryAccess($id);
    }

    /**
     * دسترسی موقت: کدی که چند ساعت اعتبار دارد و برای دادن به یک توسعه‌دهنده
     * یا پشتیبان ساخته می‌شود. بعد از انقضا خودش بی‌اثر می‌شود.
     *
     * @return array{code: string, expires: int}
     */
    public static function createInvite(int $hours): array
    {
        $state = self::load();
        $invite = ['code' => self::generateCode(), 'expires' => time() + max(1, $hours) * 3600];
        $state['invite'] = $invite;
        $state['guests'] = self::activeGuests($state);
        self::save($state);

        return $invite;
    }

    public static function revokeInvites(): void
    {
        $state = self::load();
        unset($state['invite']);
        $state['guests'] = [];
        self::save($state);
    }

    /**
     * @return array{code: string, expires: int}|null
     */
    public static function invite(): ?array
    {
        $state = self::load();
        $invite = $state['invite'] ?? null;
        if (!is_array($invite) || (int) ($invite['expires'] ?? 0) <= time()) {
            return null;
        }

        return ['code' => (string) $invite['code'], 'expires' => (int) $invite['expires']];
    }

    /**
     * @return array<int, array{id: int, expires: int}>
     */
    public static function guests(): array
    {
        return self::activeGuests(self::load());
    }

    /**
     * تلاش برای استفاده از کد دسترسی موقت.
     */
    public static function redeemInvite(int $id, string $code): bool
    {
        $invite = self::invite();
        if ($invite === null) {
            return false;
        }

        $given = strtoupper(trim(str_replace([' ', '_'], ['', '-'], $code)));
        if (!hash_equals($invite['code'], $given)) {
            return false;
        }

        $state = self::load();
        $guests = self::activeGuests($state);
        $guests[] = ['id' => $id, 'expires' => $invite['expires']];
        $state['guests'] = $guests;
        self::save($state);

        return true;
    }

    private static function hasTemporaryAccess(int $id): bool
    {
        foreach (self::guests() as $guest) {
            if ($guest['id'] === $id) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $state
     *
     * @return array<int, array{id: int, expires: int}>
     */
    private static function activeGuests(array $state): array
    {
        $out = [];
        foreach (is_array($state['guests'] ?? null) ? $state['guests'] : [] as $guest) {
            if (!is_array($guest) || (int) ($guest['expires'] ?? 0) <= time()) {
                continue;
            }
            $out[] = ['id' => (int) $guest['id'], 'expires' => (int) $guest['expires']];
        }

        return $out;
    }

    public static function isClaimed(): bool
    {
        return self::all() !== [];
    }

    /**
     * ادمین‌های دائمی + مهمان‌های موقت — برای نمایش در فهرست.
     *
     * @return array<int, int>
     */
    public static function everyone(): array
    {
        $ids = self::all();
        foreach (self::guests() as $guest) {
            $ids[] = $guest['id'];
        }

        return array_values(array_unique($ids));
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
     * @return array{admins: array<int, mixed>, claim_code: string, invite: array<string, mixed>|null, guests: array<int, mixed>}
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
            'invite' => is_array($decoded['invite'] ?? null) ? $decoded['invite'] : null,
            'guests' => is_array($decoded['guests'] ?? null) ? $decoded['guests'] : [],
        ];
    }

    /**
     * @param array<string, mixed> $state
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
        return Paths::data('admins.json');
    }
}
