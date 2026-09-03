<?php

declare(strict_types=1);

/**
 * فهرست سرورهای cPanel/WHM.
 *
 * دو منبع داریم که با هم ادغام می‌شن:
 *   1) متغیرهای محیطی (.env) — سرور «پیش‌فرض» که با اسکریپت نصب ساخته می‌شه؛
 *      از داخل ربات قابل حذف/ویرایش نیست تا با هر آپدیت دوباره برگرده.
 *   2) data/servers.json — سرورهایی که از داخل ربات اضافه می‌شن، به‌علاوه‌ی
 *      سرور فعال و کاربر مقصدِ هر سرور WHM.
 *
 * فایل روی دیسک شامل توکن‌هاست، پس با 0600 نوشته می‌شه و پوشه‌ی data با 0700.
 */
final class ServerStore
{
    public const ENV_CPANEL_ID = 'env-cpanel';
    public const ENV_WHM_ID = 'env-whm';

    /**
     * @return array<string, array<string, mixed>>
     */
    public static function all(): array
    {
        $servers = [];

        $envCpanel = self::fromEnv('CPANEL', CpanelClient::TYPE_CPANEL, self::ENV_CPANEL_ID, 2083);
        if ($envCpanel !== null) {
            $servers[self::ENV_CPANEL_ID] = $envCpanel;
        }
        $envWhm = self::fromEnv('WHM', CpanelClient::TYPE_WHM, self::ENV_WHM_ID, 2087);
        if ($envWhm !== null) {
            $servers[self::ENV_WHM_ID] = $envWhm;
        }

        $state = self::load();
        foreach ($state['servers'] as $id => $server) {
            $server['id'] = $id;
            $server['source'] = 'file';
            $servers[$id] = $server;
        }

        // کاربر مقصد (فقط برای سرورهای WHM) جدا از خود سرور ذخیره می‌شه تا
        // بشه برای سرور تعریف‌شده در .env هم تنظیمش کرد.
        foreach ($servers as $id => $server) {
            $asUser = self::str($state['as_user'][$id] ?? '');
            if ($asUser !== '') {
                $servers[$id]['as_user'] = $asUser;
            }
        }

        return $servers;
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function get(string $id): ?array
    {
        return self::all()[$id] ?? null;
    }

    public static function count(): int
    {
        return count(self::all());
    }

    /**
     * @param array<string, mixed> $server
     *
     * @return string شناسه‌ی سرور تازه‌ساخته‌شده
     */
    public static function add(array $server): string
    {
        $state = self::load();
        $id = self::uniqueId(self::str($server['label'] ?? '') ?: self::str($server['host'] ?? 'server'), $state['servers']);

        $type = ($server['type'] ?? CpanelClient::TYPE_CPANEL) === CpanelClient::TYPE_WHM
            ? CpanelClient::TYPE_WHM
            : CpanelClient::TYPE_CPANEL;
        $address = self::str($server['host'] ?? '');

        $state['servers'][$id] = [
            'label' => self::str($server['label'] ?? '') ?: Address::host($address) ?: $id,
            'type' => $type,
            'host' => Address::host($address),
            'port' => Address::resolvePort($address, $server['port'] ?? null, $type),
            'user' => self::str($server['user'] ?? ''),
            'token' => self::str($server['token'] ?? ''),
            'ssl_verify' => (bool) ($server['ssl_verify'] ?? true),
        ];
        if ($state['active'] === null) {
            $state['active'] = $id;
        }
        self::save($state);

        return $id;
    }

    public static function delete(string $id): void
    {
        $state = self::load();
        unset($state['servers'][$id], $state['as_user'][$id]);
        if ($state['active'] === $id) {
            $state['active'] = null;
        }
        self::save($state);
    }

    public static function activeId(): ?string
    {
        $servers = self::all();
        if ($servers === []) {
            return null;
        }
        $state = self::load();
        $active = $state['active'];
        if ($active !== null && isset($servers[$active])) {
            return $active;
        }

        return array_key_first($servers);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function active(): ?array
    {
        $id = self::activeId();

        return $id === null ? null : self::get($id);
    }

    public static function setActive(string $id): void
    {
        if (self::get($id) === null) {
            throw CpanelError::config("سروری با شناسه‌ی «{$id}» تعریف نشده است.");
        }
        $state = self::load();
        $state['active'] = $id;
        self::save($state);
    }

    /**
     * کاربر مقصد برای سرورهای WHM (حساب‌هایی که مدیریت می‌کنیم).
     */
    public static function setAsUser(string $id, ?string $user): void
    {
        $state = self::load();
        if ($user === null || trim($user) === '') {
            unset($state['as_user'][$id]);
        } else {
            $state['as_user'][$id] = trim($user);
        }
        self::save($state);
    }

    /**
     * @param array<string, mixed>|null $override سرور دلخواه (برای تست اتصال قبل از ذخیره)
     */
    public static function client(?string $id = null, ?array $override = null): CpanelClient
    {
        if ($override !== null) {
            return new CpanelClient($override);
        }

        $server = $id === null ? self::active() : self::get($id);
        if ($server === null && $id !== null) {
            $known = implode('، ', array_keys(self::all())) ?: '(هیچ سروری تعریف نشده)';
            throw CpanelError::config("سروری با شناسه‌ی «{$id}» پیدا نشد. شناسه‌های موجود: {$known}");
        }
        if ($server === null) {
            throw CpanelError::config(
                'هیچ سرور cPanel/WHM تنظیم نشده است. یا مقادیر CPANEL_* را در فایل .env پر کنید، یا از منوی «سرورها» داخل ربات یکی اضافه کنید.'
            );
        }

        return new CpanelClient($server);
    }

    /**
     * @return array{servers: array<string, array<string, mixed>>, active: string|null, as_user: array<string, string>}
     */
    private static function load(): array
    {
        $path = self::path();
        $decoded = is_file($path) ? json_decode((string) file_get_contents($path), true) : null;
        if (!is_array($decoded)) {
            $decoded = [];
        }

        return [
            'servers' => is_array($decoded['servers'] ?? null) ? $decoded['servers'] : [],
            'active' => self::str($decoded['active'] ?? '') ?: null,
            'as_user' => is_array($decoded['as_user'] ?? null) ? $decoded['as_user'] : [],
        ];
    }

    /**
     * @param array{servers: array<string, array<string, mixed>>, active: string|null, as_user: array<string, string>} $state
     */
    private static function save(array $state): void
    {
        $path = self::path();
        $dir = dirname($path);
        if (!is_dir($dir)) {
            @mkdir($dir, 0700, true);
        }
        @chmod($dir, 0700);
        file_put_contents($path, json_encode($state, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT));
        @chmod($path, 0600);
    }

    /**
     * @return array<string, mixed>|null
     */
    private static function fromEnv(string $prefix, string $type, string $id, int $defaultPort): ?array
    {
        $address = self::env($prefix . '_HOST');
        $user = self::env($prefix . '_USER');
        $token = self::env($prefix . '_TOKEN');
        if ($address === '' || $user === '' || $token === '') {
            return null;
        }

        $verify = self::env($prefix . '_SSL_VERIFY');

        return [
            'id' => $id,
            'source' => 'env',
            'label' => self::env($prefix . '_LABEL') ?: Address::host($address),
            'type' => $type,
            'host' => Address::host($address),
            'port' => Address::resolvePort($address, self::env($prefix . '_PORT'), $type),
            'user' => $user,
            'token' => $token,
            'ssl_verify' => !in_array(strtolower($verify), ['0', 'false', 'no', 'off'], true),
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $existing
     */
    private static function uniqueId(string $seed, array $existing): string
    {
        $slug = strtolower((string) preg_replace('/[^a-z0-9]+/i', '-', $seed));
        $slug = trim($slug, '-');
        if ($slug === '' || str_starts_with($slug, 'env-')) {
            $slug = 'srv';
        }
        $slug = substr($slug, 0, 24);

        $candidate = $slug;
        $n = 2;
        while (isset($existing[$candidate]) || in_array($candidate, [self::ENV_CPANEL_ID, self::ENV_WHM_ID], true)) {
            $candidate = "$slug-$n";
            $n++;
        }

        return $candidate;
    }

    /**
     * فقط بخش هاستِ آدرس (پورت و مسیر جدا می‌شوند).
     */
    public static function normalizeHost(string $host): string
    {
        return Address::host($host);
    }

    private static function env(string $key): string
    {
        $value = getenv($key);

        return $value === false ? '' : trim((string) $value);
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    private static function path(): string
    {
        return Paths::data('servers.json');
    }
}
