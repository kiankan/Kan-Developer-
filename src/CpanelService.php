<?php

declare(strict_types=1);

/**
 * لایه‌ی «کارهای واقعی» روی API — خروجی هر متد یک آرایه‌ی تمیز و قابل‌رندره،
 * تا هم ربات تلگرام و هم CLI دقیقاً از یک منطق استفاده کنن.
 *
 * پاسخ‌های cPanel بین نسخه‌ها یک‌دست نیستن (گاهی لیست، گاهی دیکشنری، گاهی
 * زیر یک کلید دیگه)، برای همین همه‌جا از normalizeList/pick استفاده می‌کنیم.
 */
final class CpanelService
{
    // ---------------------------------------------------------------- حساب

    /**
     * @return array<string, mixed>
     */
    public static function summary(CpanelClient $client): array
    {
        $usage = self::resourceUsage($client);
        $domains = self::softCall(fn () => self::domains($client), []);
        $emails = self::softCall(fn () => self::emails($client), []);
        $databases = self::softCall(fn () => self::databases($client), []);
        $ftp = self::softCall(fn () => self::ftpAccounts($client), []);

        return [
            'server' => $client->label(),
            'user' => $client->targetUser(),
            'info' => self::softCall(fn () => self::accountInfo($client), []),
            'usage' => $usage,
            'domains' => $domains,
            'counts' => [
                'emails' => count($emails),
                'databases' => count($databases),
                'ftp' => count($ftp),
                'subdomains' => count($domains['sub_domains'] ?? []),
                'addon' => count($domains['addon_domains'] ?? []),
            ],
        ];
    }

    /**
     * اطلاعات کلی حساب: هاست‌نیم سرور، پلن، آخرین ورود و آی‌پی‌اش، پوشه‌ی هوم،
     * نسخه‌ی cPanel و وضعیت حساب.
     *
     * StatsBar تقریباً همه‌ی این‌ها را یک‌جا می‌دهد؛ هر چه نداشت خالی می‌ماند.
     *
     * @return array<string, string>
     */
    public static function accountInfo(CpanelClient $client): array
    {
        $wanted = 'hostname|machine|plan|lastlogin|lastlogin_ip|homedir|cpanelversion|theme|ftpaccounts|emailaccounts|sqldatabases|addondomains|subdomains|parkeddomains|diskusage|bandwidthusage';

        $stats = self::softCall(fn () => $client->uapi('StatsBar', 'get_stats', ['display' => $wanted]), []);

        $info = [];
        foreach (self::normalizeList($stats) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $id = (string) ($row['name'] ?? ($row['id'] ?? ''));
            if ($id === '') {
                continue;
            }
            $value = $row['value'] ?? ($row['count'] ?? '');
            $info[$id] = is_scalar($value) ? trim((string) $value) : '';
            if (isset($row['_max']) || isset($row['max'])) {
                $info[$id . '_max'] = trim((string) ($row['_max'] ?? $row['max']));
            }
            if (isset($row['units'])) {
                $info[$id . '_units'] = trim((string) $row['units']);
            }
        }

        $variables = self::softCall(fn () => $client->uapi('Variables', 'get_user_information'), []);
        if (is_array($variables)) {
            foreach (['home', 'user', 'owner', 'plan', 'suspended'] as $key) {
                if (isset($variables[$key]) && is_scalar($variables[$key]) && trim((string) $variables[$key]) !== '') {
                    $info[$key] = trim((string) $variables[$key]);
                }
            }
        }

        return $info;
    }

    /**
     * قابلیت‌هایی که هاست برای این حساب فعال کرده — یعنی چه کارهایی اصلاً
     * روی این پلن مجاز است.
     *
     * @return array{enabled: array<int, string>, disabled: array<int, string>}
     */
    public static function accountFeatures(CpanelClient $client): array
    {
        $raw = self::softCall(fn () => $client->uapi('Features', 'list_features'), []);

        $enabled = [];
        $disabled = [];
        foreach (self::normalizeList($raw) as $row) {
            if (is_string($row)) {
                $enabled[] = $row;
                continue;
            }
            if (!is_array($row)) {
                continue;
            }
            $name = (string) ($row['id'] ?? ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            if ((bool) ($row['enabled'] ?? true)) {
                $enabled[] = $name;
            } else {
                $disabled[] = $name;
            }
        }
        sort($enabled);
        sort($disabled);

        return ['enabled' => $enabled, 'disabled' => $disabled];
    }

    /**
     * @return array<int, array{id: string, label: string, usage: ?float, maximum: ?float, percent: ?float, unit: string}>
     */
    public static function resourceUsage(CpanelClient $client): array
    {
        $raw = self::softCall(fn () => $client->uapi('ResourceUsage', 'get_usages'), null);
        $items = [];

        foreach (self::normalizeList($raw) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $maximum = self::numberOrNull($row['maximum'] ?? null);
            $usage = self::numberOrNull($row['usage'] ?? null);
            $percent = self::numberOrNull($row['percent_used'] ?? ($row['percent'] ?? null));
            if ($percent === null && $usage !== null && $maximum !== null && $maximum > 0) {
                $percent = $usage / $maximum * 100;
            }
            $items[] = [
                'id' => (string) ($row['id'] ?? ''),
                'label' => (string) ($row['description'] ?? ($row['id'] ?? '')),
                'usage' => $usage,
                'maximum' => $maximum,
                'percent' => $percent,
                'unit' => (string) ($row['formatter'] ?? ''),
            ];
        }

        return $items;
    }

    // --------------------------------------------------------------- ایمیل

    /**
     * @return array<int, array{email: string, domain: string, used: ?float, quota: mixed, percent: ?float}>
     */
    public static function emails(CpanelClient $client): array
    {
        $raw = self::softCall(fn () => $client->uapi('Email', 'list_pops_with_disk'), null);
        if ($raw === null) {
            $raw = $client->uapi('Email', 'list_pops');
        }

        $out = [];
        foreach (self::normalizeList($raw) as $row) {
            if (is_string($row)) {
                $out[] = ['email' => $row, 'domain' => self::domainPart($row), 'used' => null, 'quota' => null, 'percent' => null];
                continue;
            }
            if (!is_array($row)) {
                continue;
            }
            $email = (string) ($row['email'] ?? ($row['user'] ?? ''));
            $out[] = [
                'email' => $email,
                'domain' => (string) ($row['domain'] ?? self::domainPart($email)),
                // مقادیر _diskused/_diskquota بایت هستن، نسخه‌های بدون آندرلاین مگابایت.
                'used' => self::usedBytes($row),
                'quota' => self::quotaBytes($row),
                'percent' => self::numberOrNull($row['diskusedpercent'] ?? null),
            ];
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['email'], $b['email']));

        return $out;
    }

    /**
     * مجموع مصرف صندوق‌های ایمیل.
     *
     * @param array<int, array{email: string, domain: string, used: ?float, quota: mixed, percent: ?float}> $emails
     *
     * @return array{count: int, used: float, near_full: array<int, string>}
     */
    public static function emailUsage(array $emails): array
    {
        $used = 0.0;
        $nearFull = [];
        foreach ($emails as $email) {
            $used += (float) ($email['used'] ?? 0);
            if (($email['percent'] ?? null) !== null && (float) $email['percent'] >= 80) {
                $nearFull[] = $email['email'];
            }
        }

        return ['count' => count($emails), 'used' => $used, 'near_full' => $nearFull];
    }

    /**
     * تنظیمات IMAP/SMTP یک صندوق — همان چیزی که برای Outlook یا موبایل لازم است.
     *
     * @return array<string, string>
     */
    public static function emailClientSettings(CpanelClient $client, string $email): array
    {
        $raw = $client->uapi('Email', 'get_client_settings', ['account' => $email, 'service' => 'both']);

        $flat = [];
        $walk = static function (mixed $value, string $prefix) use (&$walk, &$flat): void {
            if (!is_array($value)) {
                if (is_scalar($value) && trim((string) $value) !== '') {
                    $flat[$prefix] = (string) $value;
                }

                return;
            }
            foreach ($value as $key => $item) {
                $walk($item, $prefix === '' ? (string) $key : $prefix . '.' . $key);
            }
        };
        $walk($raw, '');

        return $flat;
    }

    public static function addEmail(CpanelClient $client, string $email, string $password, mixed $quotaMb = 0): void
    {
        [$local, $domain] = self::splitEmail($email);
        $client->uapi('Email', 'add_pop', [
            'email' => $local,
            'domain' => $domain,
            'password' => $password,
            'quota' => self::quotaValue($quotaMb),
        ]);
    }

    /**
     * ساخت چند صندوق ایمیل با هم.
     *
     * ورودی هر خط: «نام» یا «نام رمز». رمز نداده‌شده خودکار ساخته می‌شود و در
     * خروجی برگردانده می‌شود تا کاربر ببیندش.
     *
     * @param array<int, string> $lines
     *
     * @return array<int, array{email: string, password: string, ok: bool, error: string}>
     */
    public static function bulkAddEmails(CpanelClient $client, string $domain, array $lines, mixed $quotaMb = 0): array
    {
        $results = [];

        foreach ($lines as $line) {
            $parts = preg_split('/\s+/', trim($line)) ?: [];
            $name = trim((string) ($parts[0] ?? ''));
            if ($name === '') {
                continue;
            }
            $name = str_contains($name, '@') ? explode('@', $name)[0] : $name;
            $password = (string) ($parts[1] ?? '');
            if ($password === '') {
                $password = self::randomPassword();
            }
            $email = $name . '@' . $domain;

            try {
                self::addEmail($client, $email, $password, $quotaMb);
                $results[] = ['email' => $email, 'password' => $password, 'ok' => true, 'error' => ''];
            } catch (Throwable $e) {
                $results[] = ['email' => $email, 'password' => '', 'ok' => false, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * @param array<int, string> $addresses
     *
     * @return array<int, array{email: string, ok: bool, error: string}>
     */
    public static function bulkDeleteEmails(CpanelClient $client, array $addresses): array
    {
        $results = [];

        foreach ($addresses as $address) {
            $address = trim($address);
            if ($address === '') {
                continue;
            }
            try {
                self::deleteEmail($client, $address);
                $results[] = ['email' => $address, 'ok' => true, 'error' => ''];
            } catch (Throwable $e) {
                $results[] = ['email' => $address, 'ok' => false, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * رمز تصادفی امن — همان چیزی که ربات با /gen می‌سازد.
     */
    public static function randomPassword(int $length = 16): string
    {
        $alphabet = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz23456789!@#%^*_+-=';
        $max = strlen($alphabet) - 1;
        $password = '';
        for ($i = 0; $i < $length; $i++) {
            $password .= $alphabet[random_int(0, $max)];
        }

        return $password;
    }

    public static function deleteEmail(CpanelClient $client, string $email): void
    {
        [$local, $domain] = self::splitEmail($email);
        $client->uapi('Email', 'delete_pop', ['email' => $local, 'domain' => $domain]);
    }

    public static function setEmailPassword(CpanelClient $client, string $email, string $password): void
    {
        [$local, $domain] = self::splitEmail($email);
        $client->uapi('Email', 'passwd_pop', ['email' => $local, 'domain' => $domain, 'password' => $password]);
    }

    public static function setEmailQuota(CpanelClient $client, string $email, mixed $quotaMb): void
    {
        [$local, $domain] = self::splitEmail($email);
        $client->uapi('Email', 'edit_pop_quota', [
            'email' => $local,
            'domain' => $domain,
            'quota' => self::quotaValue($quotaMb),
        ]);
    }

    /**
     * پاک‌سازی سطل زباله و اسپم یک صندوق ایمیل.
     *
     * سی‌پنل تابع API برای این کار ندارد، ولی نامه‌ها فایل‌اند: هر صندوق زیر
     * ~/mail/<domain>/<user>/ با ساختار Maildir نگه داشته می‌شود، پس همان
     * فایل‌ها را پاک می‌کنیم.
     *
     * @return array{deleted: int, folders: array<int, string>}
     */
    public static function purgeMailFolders(CpanelClient $client, string $email, bool $spamToo = true): array
    {
        [$local, $domain] = self::splitEmail($email);
        $home = self::homeDir($client);
        $base = "{$home}/mail/{$domain}/{$local}";

        $folders = ['.Trash/cur', '.Trash/new'];
        if ($spamToo) {
            $folders = array_merge($folders, ['.spam/cur', '.spam/new', '.Junk/cur', '.Junk/new']);
        }

        $deleted = 0;
        $cleared = [];
        foreach ($folders as $folder) {
            $path = "{$base}/{$folder}";
            $count = (int) self::softCall(fn () => self::purgePath($client, $path), 0);
            if ($count > 0) {
                $deleted += $count;
                $cleared[] = $folder;
            }
        }

        return ['deleted' => $deleted, 'folders' => $cleared];
    }

    /**
     * پاسخ‌های خودکار ایمیل.
     *
     * @return array<int, array{email: string, subject: string, from: string}>
     */
    public static function autoResponders(CpanelClient $client): array
    {
        $raw = self::uapiOrApi2($client, ['Email', 'list_auto_responders'], ['Email', 'listautoresponders']);

        $out = [];
        foreach (self::normalizeList($raw) as $row) {
            if (is_string($row)) {
                $out[] = ['email' => $row, 'subject' => '', 'from' => ''];
                continue;
            }
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'email' => (string) ($row['email'] ?? ($row['domain'] ?? '')),
                'subject' => (string) ($row['subject'] ?? ''),
                'from' => (string) ($row['from'] ?? ''),
            ];
        }

        return $out;
    }

    public static function addAutoResponder(
        CpanelClient $client,
        string $email,
        string $subject,
        string $body,
        string $from = '',
    ): void {
        $client->uapi('Email', 'add_auto_responder', [
            'email' => $email,
            'from' => $from !== '' ? $from : $email,
            'subject' => $subject,
            'body' => $body,
            'is_html' => 0,
            'interval' => 4,
            'start' => 0,
            'stop' => 0,
        ]);
    }

    public static function deleteAutoResponder(CpanelClient $client, string $email): void
    {
        self::uapiOrApi2(
            $client,
            ['Email', 'delete_auto_responder', ['email' => $email]],
            ['Email', 'delautoresponder', ['email' => $email]],
        );
    }

    // ------------------------------------------------------------ دیتابیس

    /**
     * @return array<int, array{name: string, size: ?float, users: array<int, string>}>
     */
    public static function databases(CpanelClient $client): array
    {
        $raw = $client->uapi('Mysql', 'list_databases');
        $out = [];
        foreach (self::normalizeList($raw) as $row) {
            if (is_string($row)) {
                $out[] = ['name' => $row, 'size' => null, 'users' => []];
                continue;
            }
            if (!is_array($row)) {
                continue;
            }
            $users = [];
            foreach (self::normalizeList($row['users'] ?? []) as $user) {
                $users[] = is_array($user) ? (string) ($user['user'] ?? '') : (string) $user;
            }
            $out[] = [
                'name' => (string) ($row['database'] ?? ($row['name'] ?? '')),
                'size' => self::numberOrNull($row['disk_usage'] ?? null),
                'users' => array_values(array_filter($users)),
            ];
        }

        return $out;
    }

    /**
     * @return array<int, string>
     */
    public static function databaseUsers(CpanelClient $client): array
    {
        $raw = self::softCall(fn () => $client->uapi('Mysql', 'list_users'), []);
        $out = [];
        foreach (self::normalizeList($raw) as $row) {
            $out[] = is_array($row) ? (string) ($row['user'] ?? '') : (string) $row;
        }

        return array_values(array_filter($out));
    }

    public static function createDatabase(CpanelClient $client, string $name): void
    {
        $client->uapi('Mysql', 'create_database', ['name' => $name]);
    }

    public static function deleteDatabase(CpanelClient $client, string $name): void
    {
        $client->uapi('Mysql', 'delete_database', ['name' => $name]);
    }

    public static function createDatabaseUser(CpanelClient $client, string $name, string $password): void
    {
        $client->uapi('Mysql', 'create_user', ['name' => $name, 'password' => $password]);
    }

    public static function deleteDatabaseUser(CpanelClient $client, string $name): void
    {
        $client->uapi('Mysql', 'delete_user', ['name' => $name]);
    }

    /**
     * محدودیت‌های MySQL این حساب — مهم‌ترینش پیشوند اجباری نام‌هاست.
     *
     * @return array{prefix: string, max_length: ?int}
     */
    public static function databaseInfo(CpanelClient $client): array
    {
        $raw = self::softCall(fn () => $client->uapi('Mysql', 'get_restrictions'), null);
        $data = is_array($raw) ? $raw : [];

        $prefix = (string) ($data['prefix'] ?? '');
        if ($prefix === '' && ($data['prefixing_enabled'] ?? false)) {
            $prefix = $client->targetUser() . '_';
        }

        return [
            'prefix' => $prefix,
            'max_length' => isset($data['max_database_name_length']) ? (int) $data['max_database_name_length'] : null,
        ];
    }

    /**
     * اطلاعاتی که برای وصل‌کردن یک برنامه به دیتابیس لازم است (میزبان، پورت،
     * نسخه) — همان چیزهایی که در wp-config.php یا .env می‌نویسید.
     *
     * @return array<string, string>
     */
    public static function mysqlServerInfo(CpanelClient $client): array
    {
        $raw = self::softCall(fn () => $client->uapi('Mysql', 'get_server_information'), []);
        $data = is_array($raw) ? $raw : [];

        return [
            'host' => (string) ($data['host'] ?? 'localhost'),
            'port' => (string) ($data['port'] ?? '3306'),
            'version' => (string) ($data['version'] ?? ($data['mysql_version'] ?? '')),
            'is_remote' => !empty($data['is_remote']) ? '1' : '',
        ];
    }

    public static function setDatabaseUserPassword(CpanelClient $client, string $user, string $password): void
    {
        $client->uapi('Mysql', 'set_password', ['user' => $user, 'password' => $password]);
    }

    public static function renameDatabase(CpanelClient $client, string $oldName, string $newName): void
    {
        $client->uapi('Mysql', 'rename_database', ['oldname' => $oldName, 'newname' => $newName]);
    }

    public static function renameDatabaseUser(CpanelClient $client, string $oldName, string $newName): void
    {
        $client->uapi('Mysql', 'rename_user', ['oldname' => $oldName, 'newname' => $newName]);
    }

    /**
     * بررسی و تعمیر جدول‌های خراب — همان Check/Repair پنل.
     *
     * @return string خلاصه‌ی نتیجه‌ای که سرور برگردانده
     */
    public static function repairDatabase(CpanelClient $client, string $name, bool $repair = true): string
    {
        $raw = $client->uapi('Mysql', $repair ? 'repair_database' : 'check_database', ['name' => $name]);

        if (is_array($raw)) {
            foreach (['message', 'status', 'result'] as $key) {
                if (isset($raw[$key]) && is_scalar($raw[$key])) {
                    return (string) $raw[$key];
                }
            }

            return trim(implode(' | ', array_map(
                static fn (mixed $value): string => is_scalar($value) ? (string) $value : '',
                self::normalizeList($raw),
            )));
        }

        return is_scalar($raw) ? (string) $raw : '';
    }

    public static function revokeDatabaseUser(CpanelClient $client, string $user, string $database): void
    {
        $client->uapi('Mysql', 'revoke_privileges_on_database', ['user' => $user, 'database' => $database]);
    }

    /**
     * میزبان‌هایی که اجازه‌ی اتصال از راه دور به MySQL دارند.
     *
     * @return array<int, string>
     */
    public static function remoteMysqlHosts(CpanelClient $client): array
    {
        $raw = self::softCall(
            fn () => self::uapiOrApi2($client, ['Mysql', 'list_hosts'], ['MysqlFE', 'listhosts']),
            [],
        );

        $out = [];
        foreach (self::normalizeList($raw) as $row) {
            $host = is_array($row) ? (string) ($row['host'] ?? ($row['name'] ?? '')) : (string) $row;
            if (trim($host) !== '') {
                $out[] = trim($host);
            }
        }

        return $out;
    }

    public static function addRemoteMysqlHost(CpanelClient $client, string $host): void
    {
        self::uapiOrApi2($client, ['Mysql', 'add_host', ['host' => $host]], ['MysqlFE', 'addhost', ['host' => $host]]);
    }

    public static function deleteRemoteMysqlHost(CpanelClient $client, string $host): void
    {
        self::uapiOrApi2($client, ['Mysql', 'delete_host', ['host' => $host]], ['MysqlFE', 'delhost', ['host' => $host]]);
    }

    public static function grantDatabaseUser(
        CpanelClient $client,
        string $user,
        string $database,
        string $privileges = 'ALL PRIVILEGES',
    ): void {
        $client->uapi('Mysql', 'set_privileges_on_database', [
            'user' => $user,
            'database' => $database,
            'privileges' => $privileges,
        ]);
    }

    // -------------------------------------------------------------- دامنه

    /**
     * @return array{main_domain: string, addon_domains: array<int, string>, sub_domains: array<int, string>, parked_domains: array<int, string>}
     */
    public static function domains(CpanelClient $client): array
    {
        $raw = $client->uapi('DomainInfo', 'list_domains');
        $data = is_array($raw) ? $raw : [];

        return [
            'main_domain' => (string) ($data['main_domain'] ?? ''),
            'addon_domains' => self::stringList($data['addon_domains'] ?? []),
            'sub_domains' => self::stringList($data['sub_domains'] ?? []),
            'parked_domains' => self::stringList($data['parked_domains'] ?? []),
        ];
    }

    /**
     * جزئیات هر دامنه: پوشه‌ی ریشه و آی‌پی — برای وقتی که سایت بالا نمی‌آید و
     * باید بدانیم فایل‌ها کجا باید باشند.
     *
     * @return array<int, array{domain: string, docroot: string, ip: string, type: string}>
     */
    public static function domainsData(CpanelClient $client): array
    {
        $raw = self::softCall(fn () => $client->uapi('DomainInfo', 'domains_data', ['format' => 'hash']), null);
        if ($raw === null) {
            return [];
        }

        // domains_data گاهی برای دامنه‌ی اصلی یک آبجکت تکی می‌دهد و گاهی لیست؛
        // normalizeList روی آبجکت تکی مقادیرش را باز می‌کند، پس جدا می‌سنجیم.
        $rowsOf = static function (mixed $value): array {
            if (!is_array($value)) {
                return [];
            }
            if (isset($value['domain']) || isset($value['servername'])) {
                return [$value];
            }

            $out = [];
            foreach ($value as $item) {
                if (is_array($item)) {
                    $out[] = $item;
                }
            }

            return $out;
        };

        $rows = [];
        if (is_array($raw)) {
            foreach (['main_domain', 'addon_domains', 'sub_domains', 'parked_domains'] as $key) {
                foreach ($rowsOf($raw[$key] ?? null) as $row) {
                    $rows[] = $row + ['__type' => $key];
                }
            }
            if ($rows === []) {
                $rows = $rowsOf($raw);
            }
        }

        $out = [];
        foreach ($rows as $row) {
            $domain = (string) ($row['domain'] ?? ($row['servername'] ?? ''));
            if ($domain === '') {
                continue;
            }
            $out[] = [
                'domain' => $domain,
                'docroot' => (string) ($row['documentroot'] ?? ($row['docroot'] ?? '')),
                'ip' => (string) ($row['ip'] ?? ($row['ipv4'] ?? '')),
                'type' => match ((string) ($row['__type'] ?? ($row['type'] ?? ''))) {
                    'main_domain', 'main' => 'اصلی',
                    'addon_domains', 'addon' => 'ادان',
                    'sub_domains', 'sub' => 'ساب‌دامنه',
                    'parked_domains', 'parked' => 'پارک‌شده',
                    default => '',
                },
            ];
        }

        return $out;
    }

    public static function addSubdomain(CpanelClient $client, string $subdomain, string $rootDomain, string $dir = ''): void
    {
        $params = ['domain' => $subdomain, 'rootdomain' => $rootDomain];
        if (trim($dir) !== '') {
            $params['dir'] = $dir;
        }
        // SubDomain هم روی بعضی نسخه‌ها فقط API2 دارد.
        self::uapiOrApi2($client, ['SubDomain', 'addsubdomain', $params], ['SubDomain', 'addsubdomain', $params]);
    }

    /**
     * دامنه‌ی اضافه (ادان) — یعنی یک سایت کاملاً جدا روی همین حساب.
     */
    public static function addAddonDomain(CpanelClient $client, string $domain, string $subdomain = '', string $dir = ''): void
    {
        $subdomain = $subdomain !== '' ? $subdomain : explode('.', $domain)[0];
        $params = [
            'newdomain' => $domain,
            'subdomain' => $subdomain,
            'dir' => $dir !== '' ? $dir : 'public_html/' . $domain,
        ];

        self::uapiOrApi2(
            $client,
            ['AddonDomain', 'addaddondomain', $params],
            ['AddonDomain', 'addaddondomain', $params],
        );
    }

    public static function deleteAddonDomain(CpanelClient $client, string $domain, string $subdomain = ''): void
    {
        $params = ['domain' => $domain, 'subdomain' => $subdomain !== '' ? $subdomain : explode('.', $domain)[0]];

        self::uapiOrApi2(
            $client,
            ['AddonDomain', 'deladdondomain', $params],
            ['AddonDomain', 'deladdondomain', $params],
        );
    }

    /**
     * دامنه‌ی پارک‌شده — یعنی دامنه‌ای که همان سایت اصلی را نشان می‌دهد.
     */
    public static function parkDomain(CpanelClient $client, string $domain): void
    {
        self::uapiOrApi2($client, ['Park', 'park', ['domain' => $domain]], ['Park', 'park', ['domain' => $domain]]);
    }

    public static function unparkDomain(CpanelClient $client, string $domain): void
    {
        self::uapiOrApi2($client, ['Park', 'unpark', ['domain' => $domain]], ['Park', 'unpark', ['domain' => $domain]]);
    }

    public static function deleteSubdomain(CpanelClient $client, string $fullSubdomain): void
    {
        $params = ['domain' => $fullSubdomain];
        self::uapiOrApi2($client, ['SubDomain', 'delsubdomain', $params], ['SubDomain', 'delsubdomain', $params]);
    }

    // ----------------------------------------------------------------- FTP

    /**
     * @return array<int, array{user: string, dir: string, used: ?float, quota: mixed}>
     */
    public static function ftpAccounts(CpanelClient $client): array
    {
        $raw = self::softCall(fn () => $client->uapi('Ftp', 'list_ftp_with_disk'), null);
        if ($raw === null) {
            $raw = $client->uapi('Ftp', 'list_ftp');
        }

        $out = [];
        foreach (self::normalizeList($raw) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'user' => (string) ($row['user'] ?? ''),
                'dir' => (string) ($row['dir'] ?? ($row['homedir'] ?? '')),
                'used' => self::usedBytes($row),
                'quota' => self::quotaBytes($row),
            ];
        }

        return $out;
    }

    public static function addFtp(CpanelClient $client, string $user, string $password, string $dir = '', mixed $quotaMb = 0): void
    {
        $params = ['user' => $user, 'pass' => $password, 'quota' => self::quotaValue($quotaMb)];
        if (trim($dir) !== '') {
            $params['homedir'] = $dir;
        }
        $client->uapi('Ftp', 'add_ftp', $params);
    }

    public static function setFtpPassword(CpanelClient $client, string $user, string $password): void
    {
        $client->uapi('Ftp', 'passwd', ['user' => $user, 'pass' => $password]);
    }

    public static function setFtpQuota(CpanelClient $client, string $user, mixed $quotaMb): void
    {
        $client->uapi('Ftp', 'set_quota', ['user' => $user, 'quota' => self::quotaValue($quotaMb)]);
    }

    /**
     * نشست‌های FTP که همین حالا باز هستند.
     *
     * @return array<int, array{user: string, host: string, file: string, pid: string, status: string}>
     */
    public static function ftpSessions(CpanelClient $client): array
    {
        $raw = self::softCall(fn () => $client->uapi('Ftp', 'list_sessions'), []);

        $out = [];
        foreach (self::normalizeList($raw) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'user' => (string) ($row['user'] ?? ($row['login'] ?? '')),
                'host' => (string) ($row['host'] ?? ''),
                'file' => (string) ($row['file'] ?? ''),
                'pid' => (string) ($row['pid'] ?? ''),
                'status' => (string) ($row['status'] ?? ($row['cmdline'] ?? '')),
            ];
        }

        return $out;
    }

    public static function killFtpSession(CpanelClient $client, string $login, string $pid): void
    {
        $client->uapi('Ftp', 'kill_session', ['login' => $login, 'pid' => $pid]);
    }

    public static function deleteFtp(CpanelClient $client, string $user, bool $destroyFiles = false): void
    {
        $client->uapi('Ftp', 'delete_ftp', ['user' => $user, 'destroy' => $destroyFiles ? 1 : 0]);
    }

    // ---------------------------------------------------------------- کران

    /**
     * کران‌جاب‌ها.
     *
     * روی خیلی از نسخه‌های cPanel اصلاً ماژول UAPI برای Cron وجود ندارد
     * (خطای «Failed to load module Cron») و کران فقط از API2 در دسترس است؛
     * پس هر دو را امتحان می‌کنیم.
     *
     * @return array<int, array{key: string, schedule: string, command: string}>
     */
    public static function cronJobs(CpanelClient $client): array
    {
        $raw = self::uapiOrApi2($client, ['Cron', 'list_lines'], ['Cron', 'listcron']);
        if (is_array($raw) && isset($raw['lines'])) {
            $raw = $raw['lines'];
        }

        $out = [];
        foreach (self::normalizeList($raw) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $command = (string) ($row['command'] ?? '');
            if ($command === '') {
                continue;
            }
            $schedule = trim(implode(' ', [
                (string) ($row['minute'] ?? '*'),
                (string) ($row['hour'] ?? '*'),
                (string) ($row['day'] ?? '*'),
                (string) ($row['month'] ?? '*'),
                (string) ($row['weekday'] ?? '*'),
            ]));
            $out[] = [
                'key' => (string) ($row['linekey'] ?? ($row['line'] ?? ($row['count'] ?? ''))),
                'schedule' => $schedule,
                'command' => $command,
            ];
        }

        return $out;
    }

    /**
     * @param array{minute: string, hour: string, day: string, month: string, weekday: string} $schedule
     */
    public static function addCron(CpanelClient $client, array $schedule, string $command): void
    {
        $params = [
            'command' => $command,
            'minute' => $schedule['minute'],
            'hour' => $schedule['hour'],
            'day' => $schedule['day'],
            'month' => $schedule['month'],
            'weekday' => $schedule['weekday'],
        ];

        self::uapiOrApi2($client, ['Cron', 'add_line', $params], ['Cron', 'add_line', $params]);
    }

    /**
     * ویرایش یک کران موجود (زمان‌بندی و/یا دستور).
     *
     * @param array{minute: string, hour: string, day: string, month: string, weekday: string} $schedule
     */
    public static function editCron(CpanelClient $client, string $lineKey, array $schedule, string $command): void
    {
        $params = [
            'linekey' => $lineKey,
            'command' => $command,
            'minute' => $schedule['minute'],
            'hour' => $schedule['hour'],
            'day' => $schedule['day'],
            'month' => $schedule['month'],
            'weekday' => $schedule['weekday'],
        ];

        self::uapiOrApi2($client, ['Cron', 'edit_line', $params], ['Cron', 'edit_line', $params]);
    }

    public static function deleteCron(CpanelClient $client, string $lineKey): void
    {
        try {
            self::uapiOrApi2($client, ['Cron', 'remove_line', ['linekey' => $lineKey]], ['Cron', 'remove_line', ['linekey' => $lineKey]]);
        } catch (CpanelError $e) {
            if ($e->kind !== CpanelError::KIND_API || !ctype_digit($lineKey)) {
                throw $e;
            }
            // نسخه‌های قدیمی به‌جای linekey شماره‌ی خط می‌گیرند.
            self::uapiOrApi2($client, ['Cron', 'remove_line', ['line' => (int) $lineKey]], ['Cron', 'remove_line', ['line' => (int) $lineKey]]);
        }
    }

    /**
     * فرمت استاندارد کران (۵ فیلد) رو می‌سنجه و تفکیک می‌کنه.
     *
     * @return array{minute: string, hour: string, day: string, month: string, weekday: string}|null
     */
    public static function parseSchedule(string $schedule): ?array
    {
        $parts = preg_split('/\s+/', trim($schedule)) ?: [];
        if (count($parts) !== 5) {
            return null;
        }
        foreach ($parts as $part) {
            if (!preg_match('#^[0-9*/,\-]+$#', $part)) {
                return null;
            }
        }

        return [
            'minute' => $parts[0],
            'hour' => $parts[1],
            'day' => $parts[2],
            'month' => $parts[3],
            'weekday' => $parts[4],
        ];
    }

    // ----------------------------------------------------------------- DNS

    /**
     * رکوردهای یک زون.
     *
     * روی نسخه‌های جدید از UAPI DNS::parse_zone (که فیلدهایش base64 است)
     * خوانده می‌شود، و روی نسخه‌هایی که آن ماژول را ندارند از API2
     * ZoneEdit::fetchzone_records.
     *
     * @return array{serial: ?string, records: array<int, array{line: int, name: string, ttl: ?int, type: string, data: array<int, string>}>, api2: bool}
     */
    public static function dnsZone(CpanelClient $client, string $zone): array
    {
        try {
            return self::parseUapiZone($client->uapi('DNS', 'parse_zone', ['zone' => $zone]));
        } catch (CpanelError $e) {
            if (!self::isModuleMissing($e)) {
                throw $e;
            }
        }

        return self::parseApi2Zone($client->api2('ZoneEdit', 'fetchzone_records', ['domain' => $zone]));
    }

    /**
     * @return array{serial: ?string, records: array<int, array{line: int, name: string, ttl: ?int, type: string, data: array<int, string>}>, api2: bool}
     */
    private static function parseUapiZone(mixed $raw): array
    {
        $records = [];
        $serial = null;
        foreach (self::normalizeList($raw) as $row) {
            if (!is_array($row) || ($row['type'] ?? '') !== 'record') {
                continue;
            }
            $data = [];
            foreach (self::normalizeList($row['data_b64'] ?? []) as $chunk) {
                $data[] = (string) base64_decode((string) $chunk, true);
            }
            $recordType = (string) ($row['record_type'] ?? '');
            if ($recordType === 'SOA' && isset($data[2])) {
                $serial = $data[2];
            }
            $records[] = [
                'line' => (int) ($row['line_index'] ?? 0),
                'name' => (string) base64_decode((string) ($row['dname_b64'] ?? ''), true),
                'ttl' => isset($row['ttl']) ? (int) $row['ttl'] : null,
                'type' => $recordType,
                'data' => $data,
            ];
        }

        return ['serial' => $serial, 'records' => $records, 'api2' => false];
    }

    /**
     * ZoneEdit مقدار هر رکورد را در کلید مخصوص خودش می‌گذارد (address برای A،
     * cname برای CNAME، txtdata برای TXT و ...).
     *
     * @return array{serial: ?string, records: array<int, array{line: int, name: string, ttl: ?int, type: string, data: array<int, string>}>, api2: bool}
     */
    private static function parseApi2Zone(mixed $raw): array
    {
        $records = [];
        foreach (self::normalizeList($raw) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $type = strtoupper((string) ($row['type'] ?? ''));
            if ($type === '') {
                continue;
            }

            $data = [];
            foreach (['preference', 'exchange', 'address', 'cname', 'txtdata', 'nsdname', 'ptrdname', 'record'] as $key) {
                if (isset($row[$key]) && trim((string) $row[$key]) !== '') {
                    $data[] = (string) $row[$key];
                }
            }

            $records[] = [
                'line' => (int) ($row['Line'] ?? ($row['line'] ?? 0)),
                'name' => (string) ($row['name'] ?? ''),
                'ttl' => isset($row['ttl']) ? (int) $row['ttl'] : null,
                'type' => $type,
                'data' => $data,
            ];
        }

        return ['serial' => null, 'records' => $records, 'api2' => true];
    }

    /**
     * @param array<int, string> $data
     */
    public static function addDnsRecord(
        CpanelClient $client,
        string $zone,
        string $name,
        string $type,
        array $data,
        int $ttl = 14400,
    ): void {
        $zoneData = self::dnsZone($client, $zone);
        $type = strtoupper($type);

        if ($zoneData['api2']) {
            $client->api2('ZoneEdit', 'add_zone_record', self::api2RecordParams($zone, $name, $type, $data, $ttl));

            return;
        }

        if ($zoneData['serial'] === null) {
            throw CpanelError::api("سریال SOA برای زون «{$zone}» پیدا نشد؛ رکورد اضافه نشد.");
        }

        $client->uapi('DNS', 'mass_edit_zone', [
            'zone' => $zone,
            'serial' => $zoneData['serial'],
            'add' => json_encode([
                'dname' => $name,
                'ttl' => $ttl,
                'record_type' => $type,
                'data' => array_values($data),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE),
        ]);
    }

    /**
     * @param array<int, string> $data
     *
     * @return array<string, mixed>
     */
    private static function api2RecordParams(string $zone, string $name, string $type, array $data, int $ttl): array
    {
        $params = ['domain' => $zone, 'name' => $name, 'type' => $type, 'ttl' => $ttl];
        $value = implode(' ', $data);

        switch ($type) {
            case 'MX':
                // «10 mail.example.com.» → اولویت و میزبان جدا
                $params['preference'] = $data[0] ?? '0';
                $params['exchange'] = $data[1] ?? '';
                break;
            case 'CNAME':
                $params['cname'] = $value;
                break;
            case 'TXT':
                $params['txtdata'] = $value;
                break;
            case 'NS':
                $params['nsdname'] = $value;
                break;
            case 'CAA':
                // «0 issue letsencrypt.org» → پرچم، برچسب و مقدار
                $params['flag'] = $data[0] ?? '0';
                $params['tag'] = $data[1] ?? 'issue';
                $params['value'] = $data[2] ?? '';
                break;
            case 'SRV':
                // «10 5 443 target.example.com.»
                $params['priority'] = $data[0] ?? '0';
                $params['weight'] = $data[1] ?? '0';
                $params['port'] = $data[2] ?? '';
                $params['target'] = $data[3] ?? '';
                break;
            default:
                $params['address'] = $value;
        }

        return $params;
    }

    public static function deleteDnsRecord(CpanelClient $client, string $zone, int $lineIndex): void
    {
        $zoneData = self::dnsZone($client, $zone);

        if ($zoneData['api2']) {
            $client->api2('ZoneEdit', 'remove_zone_record', ['domain' => $zone, 'line' => $lineIndex]);

            return;
        }

        if ($zoneData['serial'] === null) {
            throw CpanelError::api("سریال SOA برای زون «{$zone}» پیدا نشد؛ رکورد حذف نشد.");
        }

        $client->uapi('DNS', 'mass_edit_zone', [
            'zone' => $zone,
            'serial' => $zoneData['serial'],
            'remove' => $lineIndex,
        ]);
    }

    /**
     * جست‌وجوی سراسری: یک عبارت را در ایمیل‌ها، دیتابیس‌ها، کاربران، دامنه‌ها،
     * حساب‌های FTP و فایل‌های هوم/public_html می‌گردد.
     *
     * @return array<string, array<int, string>>
     */
    public static function search(CpanelClient $client, string $term): array
    {
        $needle = mb_strtolower(trim($term));
        if ($needle === '') {
            return [];
        }

        $match = static function (array $values) use ($needle): array {
            $found = [];
            foreach ($values as $value) {
                if (str_contains(mb_strtolower((string) $value), $needle)) {
                    $found[] = (string) $value;
                }
            }

            return array_values(array_slice(array_unique($found), 0, 15));
        };

        $domains = self::softCall(fn () => self::domains($client), ['main_domain' => '', 'addon_domains' => [], 'sub_domains' => [], 'parked_domains' => []]);
        $home = self::homeDir($client);

        $files = [];
        foreach ([$home, $home . '/public_html'] as $dir) {
            foreach (self::softCall(fn () => self::listFiles($client, $dir), []) as $entry) {
                $files[] = $entry['path'];
            }
        }

        $results = [
            'ایمیل' => $match(array_column(self::softCall(fn () => self::emails($client), []), 'email')),
            'دیتابیس' => $match(array_column(self::softCall(fn () => self::databases($client), []), 'name')),
            'کاربر دیتابیس' => $match(self::softCall(fn () => self::databaseUsers($client), [])),
            'دامنه' => $match(array_merge(
                [$domains['main_domain']],
                $domains['addon_domains'],
                $domains['sub_domains'],
                $domains['parked_domains'],
            )),
            'FTP' => $match(array_column(self::softCall(fn () => self::ftpAccounts($client), []), 'user')),
            'فایل' => $match($files),
        ];

        return array_filter($results, static fn (array $rows): bool => $rows !== []);
    }

    /**
     * مصرف پهنای‌باند به تفکیک دامنه (اگر هاست این API را داشته باشد).
     *
     * @return array<int, array{domain: string, bytes: float}>
     */
    public static function bandwidth(CpanelClient $client): array
    {
        $raw = self::softCall(
            fn () => $client->uapi('Bandwidth', 'query', ['grouping' => 'domain', 'interval' => 'daily', 'timezone' => 'UTC']),
            null,
        );
        if (!is_array($raw)) {
            return [];
        }

        $rows = is_array($raw['domains'] ?? null) ? $raw['domains'] : $raw;
        $out = [];
        foreach ($rows as $key => $row) {
            $domain = is_array($row) ? (string) ($row['domain'] ?? $key) : (string) $key;
            $bytes = is_array($row) ? ($row['bytes'] ?? ($row['total'] ?? 0)) : $row;
            if (!is_numeric($bytes)) {
                continue;
            }
            $out[] = ['domain' => $domain, 'bytes' => (float) $bytes];
        }

        usort($out, static fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

        return $out;
    }

    /**
     * جایگزین‌کردن رکورد A یک نام با آی‌پی جدید — برای وقتی دامنه به سرور
     * اشتباه اشاره می‌کند.
     */
    public static function setARecord(CpanelClient $client, string $zone, string $name, string $ip, int $ttl = 14400): void
    {
        $zoneData = self::dnsZone($client, $zone);
        $target = rtrim($name, '.');
        $targetFull = rtrim($target === '@' ? $zone : $target, '.');
        if (!str_contains($targetFull, '.')) {
            $targetFull .= '.' . $zone;
        }

        foreach ($zoneData['records'] as $record) {
            if ($record['type'] !== 'A') {
                continue;
            }
            $recordName = rtrim($record['name'], '.');
            if ($recordName === $targetFull) {
                self::deleteDnsRecord($client, $zone, $record['line']);
                break;
            }
        }

        self::addDnsRecord($client, $zone, $target, 'A', [$ip], $ttl);
    }

    // ----------------------------------------------------------- ریدایرکت

    /**
     * @return array<int, array{source: string, destination: string, type: string}>
     */
    public static function redirects(CpanelClient $client): array
    {
        $raw = self::uapiOrApi2($client, ['Mime', 'list_redirects'], ['Mime', 'listredirects']);

        $out = [];
        foreach (self::normalizeList($raw) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'source' => (string) ($row['sourceurl'] ?? ($row['source' ] ?? ($row['domain'] ?? ''))),
                'destination' => (string) ($row['destination'] ?? ($row['targeturl'] ?? '')),
                'type' => (string) ($row['type'] ?? ''),
            ];
        }

        return $out;
    }

    public static function addRedirect(
        CpanelClient $client,
        string $domain,
        string $destination,
        string $type = 'permanent',
        string $path = '/',
    ): void {
        $client->uapi('Mime', 'add_redirect', [
            'domain' => $domain,
            'redirect' => $destination,
            'type' => $type === 'temporary' ? 'temporary' : 'permanent',
            'redirect_wildcard' => 0,
            'redirect_www' => 0,
            'src' => $path,
        ]);
    }

    public static function deleteRedirect(CpanelClient $client, string $source): void
    {
        $client->uapi('Mime', 'delete_redirect', ['sourceurl' => $source]);
    }

    // ----------------------------------------------------------------- SSL

    /**
     * @return array<int, array{domain: string, issuer: string, expires: mixed, domains: array<int, string>}>
     */
    public static function sslHosts(CpanelClient $client): array
    {
        $raw = $client->uapi('SSL', 'installed_hosts');
        $out = [];
        foreach (self::normalizeList($raw) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $certificate = is_array($row['certificate'] ?? null) ? $row['certificate'] : [];
            $issuer = $certificate['issuer'] ?? [];
            $out[] = [
                'domain' => (string) ($row['servername'] ?? ($row['domain'] ?? '')),
                'issuer' => is_array($issuer)
                    ? (string) ($issuer['commonName'] ?? ($issuer['organizationName'] ?? ''))
                    : (string) $issuer,
                'expires' => $certificate['not_after'] ?? null,
                'domains' => self::stringList($certificate['domains'] ?? []),
            ];
        }

        return $out;
    }

    /**
     * روشن/خاموش کردن «همیشه HTTPS» برای یک دامنه.
     */
    public static function forceHttps(CpanelClient $client, string $domain, bool $enable): void
    {
        $client->uapi('SSL', 'toggle_ssl_redirect_for_domains', [
            'domains' => $domain,
            'state' => $enable ? 1 : 0,
        ]);
    }

    /**
     * درخواست صدور/تمدید خودکار گواهی برای دامنه‌های حساب.
     */
    public static function runAutoSsl(CpanelClient $client): void
    {
        $client->uapi('SSL', 'start_autossl_check');
    }

    // ----------------------------------------------------------------- PHP

    /**
     * @return array<int, array{vhost: string, version: string}>
     */
    public static function phpVersions(CpanelClient $client): array
    {
        $raw = $client->uapi('LangPHP', 'php_get_vhost_versions');
        $out = [];
        foreach (self::normalizeList($raw) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'vhost' => (string) ($row['vhost'] ?? ''),
                'version' => (string) ($row['version'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * @return array<int, string>
     */
    public static function installedPhpVersions(CpanelClient $client): array
    {
        $raw = self::softCall(fn () => $client->uapi('LangPHP', 'php_get_installed_versions'), []);
        if (is_array($raw) && isset($raw['versions'])) {
            $raw = $raw['versions'];
        }

        return self::stringList($raw);
    }

    /**
     * محدودیت‌های PHP یک دامنه — همان چیزهایی که موقع آپلود فایل بزرگ یا
     * تایم‌اوت گرفتن سایت باید دید.
     *
     * @return array<string, string>
     */
    public static function phpLimits(CpanelClient $client, string $vhost): array
    {
        // نام پارامتر روی خود cPanel «vhost» است، نه «path» — با path خطای
        // «Provide the vhost parameter» می‌دهد.
        $raw = $client->uapi('LangPHP', 'php_ini_get_user_basic_directives', [
            'type' => 'vhost',
            'vhost' => $vhost,
        ]);

        $out = [];
        foreach (self::normalizeList($raw) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $key = (string) ($row['key'] ?? ($row['name'] ?? ''));
            if ($key === '') {
                continue;
            }
            $value = $row['value'] ?? '';
            $out[$key] = is_scalar($value) ? (string) $value : '';
        }

        return $out;
    }

    /**
     * مثل phpLimits، ولی اگر خواندن نشد به‌جای خطا خالی برمی‌گرداند — برای
     * وقتی که فقط می‌خواهیم مقدار تازه‌ی تنظیم‌شده را نشان بدهیم.
     *
     * @return array<string, string>
     */
    public static function softLimits(CpanelClient $client, string $vhost): array
    {
        $limits = self::softCall(fn () => self::phpLimits($client, $vhost), []);

        return is_array($limits) ? $limits : [];
    }

    public static function setPhpVersion(CpanelClient $client, string $vhost, string $version): void
    {
        $client->uapi('LangPHP', 'php_set_vhost_lang_version', ['vhost' => $vhost, 'version' => $version]);
    }

    // ----------------------------------------------------------- فایل‌ها

    /**
     * فهرست فایل‌ها و پوشه‌های یک مسیر.
     *
     * @return array<int, array{name: string, path: string, dir: bool, size: ?float, mtime: ?int}>
     */
    public static function listFiles(CpanelClient $client, string $dir): array
    {
        $dir = self::normalizePath($dir);
        $raw = self::uapiOrApi2(
            $client,
            ['Fileman', 'list_files', ['dir' => $dir, 'include_mime' => 0]],
            ['Fileman', 'listfiles', ['dir' => $dir, 'types' => 'dir|file']],
        );

        $out = [];
        foreach (self::normalizeList($raw) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = (string) ($row['file'] ?? ($row['filename'] ?? ($row['name'] ?? '')));
            if ($name === '' || $name === '.' || $name === '..') {
                continue;
            }
            $type = strtolower((string) ($row['type'] ?? ''));
            $permissions = $row['permissions'] ?? ($row['mode'] ?? ($row['rawmode'] ?? null));
            $out[] = [
                'name' => $name,
                'path' => rtrim($dir, '/') . '/' . $name,
                'dir' => $type === 'dir' || $type === 'directory' || (bool) ($row['isdir'] ?? false),
                'size' => self::numberOrNull($row['size'] ?? ($row['humansize'] ?? null)),
                'mtime' => isset($row['mtime']) ? (int) $row['mtime'] : null,
                'permissions' => $permissions !== null ? self::formatPermissions((string) $permissions) : '',
            ];
        }

        usort($out, static function (array $a, array $b): int {
            if ($a['dir'] !== $b['dir']) {
                return $a['dir'] ? -1 : 1;
            }

            return strcasecmp($a['name'], $b['name']);
        });

        return $out;
    }

    /**
     * فرستادن یک فایل به هاست (همان چیزی که در تلگرام آپلود شده).
     */
    public static function uploadFile(CpanelClient $client, string $dir, string $name, string $contents): void
    {
        $client->uploadFile(
            'Fileman',
            'upload_files',
            ['dir' => self::normalizePath($dir), 'overwrite' => 1],
            'file-1',
            $name,
            $contents,
        );
    }

    /**
     * باز کردن آرشیو (zip/tar.gz) روی خود هاست — قدم آخر «سورس را بگذار روی هاست».
     */
    public static function extractArchive(CpanelClient $client, string $archivePath, string $destination = ''): void
    {
        $archivePath = self::normalizePath($archivePath);
        $destination = $destination === '' ? dirname($archivePath) : self::normalizePath($destination);

        $client->api2('Fileman', 'fileop', [
            'op' => 'extract',
            'sourcefiles' => $archivePath,
            'destfiles' => $destination,
            'doubledecode' => 0,
        ]);
    }

    /**
     * تغییر نام، کپی و جابه‌جایی — هر سه با همان fileop سی‌پنل.
     */
    public static function renamePath(CpanelClient $client, string $path, string $newName): void
    {
        $path = self::normalizePath($path);
        $client->api2('Fileman', 'fileop', [
            'op' => 'rename',
            'sourcefiles' => $path,
            'destfiles' => trim($newName),
            'doubledecode' => 0,
        ]);
    }

    public static function copyPath(CpanelClient $client, string $path, string $destination): void
    {
        $client->api2('Fileman', 'fileop', [
            'op' => 'copy',
            'sourcefiles' => self::normalizePath($path),
            'destfiles' => self::normalizePath($destination),
            'doubledecode' => 0,
        ]);
    }

    public static function movePath(CpanelClient $client, string $path, string $destination): void
    {
        $client->api2('Fileman', 'fileop', [
            'op' => 'move',
            'sourcefiles' => self::normalizePath($path),
            'destfiles' => self::normalizePath($destination),
            'doubledecode' => 0,
        ]);
    }

    /**
     * تغییر سطح دسترسی فایل/پوشه (مثل 644 برای فایل و 755 برای پوشه).
     */
    public static function chmodPath(CpanelClient $client, string $path, string $permissions): void
    {
        $client->api2('Fileman', 'fileop', [
            'op' => 'chmod',
            'sourcefiles' => self::normalizePath($path),
            'metadata' => ltrim(trim($permissions), '0') ?: '644',
            'doubledecode' => 0,
        ]);
    }

    /**
     * فشرده‌کردن یک پوشه یا فایل به zip — مفید برای بکاپ گرفتن قبل از تغییر.
     */
    public static function compressPath(CpanelClient $client, string $path, string $archiveName = ''): string
    {
        $path = self::normalizePath($path);
        $archive = trim($archiveName) !== ''
            ? self::normalizePath($archiveName)
            : $path . '-' . gmdate('Ymd-His') . '.zip';

        $client->api2('Fileman', 'fileop', [
            'op' => 'compress',
            'sourcefiles' => $path,
            'destfiles' => $archive,
            'metadata' => 'zip',
            'doubledecode' => 0,
        ]);

        return $archive;
    }

    public static function deletePath(CpanelClient $client, string $path): void
    {
        $client->api2('Fileman', 'fileop', [
            'op' => 'unlink',
            'sourcefiles' => self::normalizePath($path),
            'doubledecode' => 0,
        ]);
    }

    public static function makeDirectory(CpanelClient $client, string $dir, string $name): void
    {
        $client->api2('Fileman', 'mkdir', ['path' => self::normalizePath($dir), 'name' => $name]);
    }

    /**
     * گرفتن محتوای یک فایل برای فرستادن در تلگرام.
     *
     * اول از خود API (که برای فایل متنی مطمئن‌تر است) و اگر نشد یا فایل
     * باینری بود، از مسیر دانلود خام.
     */
    public static function downloadFile(CpanelClient $client, string $path): string
    {
        $path = self::normalizePath($path);

        if (!self::isBinaryName($path)) {
            $content = self::softCall(fn () => self::readFile($client, $path), null);
            if (is_string($content) && $content !== '') {
                return $content;
            }
        }

        return $client->downloadPath($path);
    }

    public static function isBinaryName(string $name): bool
    {
        return (bool) preg_match(
            '/\.(zip|tar|gz|tgz|bz2|rar|7z|jpe?g|png|gif|webp|ico|bmp|pdf|docx?|xlsx?|pptx?|mp[34]|avi|mkv|mov|woff2?|ttf|eot|so|bin|sqlite3?|db)$/i',
            trim($name),
        );
    }

    /**
     * نوشتن محتوای یک فایل (ویرایش یا ساخت فایل تازه).
     */
    public static function saveFile(CpanelClient $client, string $path, string $content): void
    {
        $path = self::normalizePath($path);

        $client->uapi('Fileman', 'save_file_content', [
            'dir' => dirname($path),
            'file' => basename($path),
            'content' => $content,
            'fallback' => 0,
            'from_charset' => 'utf-8',
            'to_charset' => 'utf-8',
        ]);
    }

    /**
     * جست‌وجوی فایل بر اساس نام، تا دو سطح پایین‌تر از مسیر داده‌شده.
     *
     * @return array<int, array{name: string, path: string, dir: bool, size: ?float, mtime: ?int, permissions: string}>
     */
    public static function findFiles(CpanelClient $client, string $dir, string $needle, int $limit = 25): array
    {
        $needle = mb_strtolower(trim($needle));
        if ($needle === '') {
            return [];
        }

        $found = [];
        $queue = [[self::normalizePath($dir), 0]];

        while ($queue !== [] && count($found) < $limit) {
            [$current, $depth] = array_shift($queue);

            foreach (self::softCall(fn () => self::listFiles($client, $current), []) as $entry) {
                if (str_contains(mb_strtolower($entry['name']), $needle)) {
                    $found[] = $entry;
                    if (count($found) >= $limit) {
                        break;
                    }
                }
                // پوشه‌های سنگین و بی‌ربط را نمی‌گردیم تا تعداد درخواست‌ها منفجر نشود.
                if ($entry['dir'] && $depth < 2 && !in_array($entry['name'], ['mail', 'node_modules', '.git', 'tmp', 'cache'], true)) {
                    $queue[] = [$entry['path'], $depth + 1];
                }
            }
        }

        return $found;
    }

    public static function readFile(CpanelClient $client, string $path): string
    {
        $raw = $client->uapi('Fileman', 'get_file_content', [
            'dir' => dirname(self::normalizePath($path)),
            'file' => basename($path),
        ]);

        if (is_array($raw)) {
            return (string) ($raw['content'] ?? '');
        }

        return (string) $raw;
    }

    /**
     * پوشه‌ی هوم حساب — نقطه‌ی شروع مرور فایل‌ها.
     */
    public static function homeDir(CpanelClient $client): string
    {
        $info = self::softCall(fn () => $client->uapi('Variables', 'get_user_information'), null);
        if (is_array($info) && trim((string) ($info['home'] ?? '')) !== '') {
            return self::normalizePath((string) $info['home']);
        }

        $user = $client->targetUser();

        return $user !== '' ? "/home/{$user}" : '/home';
    }

    /**
     * سی‌پنل سطح دسترسی را گاهی 0644، گاهی 644 و گاهی عدد اکتال خام می‌دهد.
     */
    public static function formatPermissions(string $raw): string
    {
        $clean = trim($raw);
        if ($clean === '') {
            return '';
        }
        if (ctype_digit($clean)) {
            $clean = substr($clean, -4);

            return ltrim($clean, '0') ?: '0';
        }

        return $clean;
    }

    /**
     * جاهایی که معمولاً فضای هاست را می‌خورند: کش، لاگ، بکاپ‌های قدیمی و سطل
     * زباله‌ی ایمیل. هر کدام را جدا اندازه می‌گیریم تا کاربر بداند دقیقاً چه
     * چیزی فضا گرفته، نه فقط اینکه «فضا پر است».
     *
     * @return array<int, array{path: string, label: string, size: float, files: int}>
     */
    public static function cleanupCandidates(CpanelClient $client): array
    {
        $home = self::homeDir($client);

        $spots = [
            ['path' => $home . '/tmp', 'label' => 'فایل‌های موقت'],
            ['path' => $home . '/logs', 'label' => 'لاگ‌های قدیمی'],
            ['path' => $home . '/.trash', 'label' => 'سطل زباله‌ی فایل‌منیجر'],
            ['path' => $home . '/.cpanel/caches', 'label' => 'کش خود cPanel'],
            ['path' => $home . '/public_html/wp-content/cache', 'label' => 'کش وردپرس'],
            ['path' => $home . '/public_html/cache', 'label' => 'کش سایت'],
            ['path' => $home . '/public_html/wp-content/uploads/backup', 'label' => 'بکاپ افزونه‌ها'],
        ];

        $out = [];
        foreach ($spots as $spot) {
            $files = self::softCall(fn () => self::listFiles($client, $spot['path']), []);
            if ($files === []) {
                continue;
            }
            $size = 0.0;
            foreach ($files as $file) {
                $size += (float) ($file['size'] ?? 0);
            }
            $out[] = ['path' => $spot['path'], 'label' => $spot['label'], 'size' => $size, 'files' => count($files)];
        }

        // بکاپ‌های کامل داخل هوم هم معمولاً بزرگ‌ترین فایل‌های حسابند.
        foreach (self::softCall(fn () => self::listFiles($client, $home), []) as $file) {
            if ($file['dir'] || !preg_match('/^backup.*\.(tar\.gz|tar|tgz|zip)$/i', $file['name'])) {
                continue;
            }
            $out[] = [
                'path' => $file['path'],
                'label' => 'فایل بکاپ ' . $file['name'],
                'size' => (float) ($file['size'] ?? 0),
                'files' => 1,
            ];
        }

        usort($out, static fn (array $a, array $b): int => $b['size'] <=> $a['size']);

        return $out;
    }

    /**
     * محتوای یک پوشه را پاک می‌کند (خود پوشه می‌ماند) یا یک فایل را حذف می‌کند.
     *
     * @return int تعداد چیزهایی که حذف شدند
     */
    public static function purgePath(CpanelClient $client, string $path): int
    {
        $path = self::normalizePath($path);
        $files = self::softCall(fn () => self::listFiles($client, $path), null);

        if ($files === null) {
            // فایل تکی بود، نه پوشه.
            self::deletePath($client, $path);

            return 1;
        }

        if ($files === []) {
            return 0;
        }

        $client->api2('Fileman', 'fileop', [
            'op' => 'unlink',
            'sourcefiles' => implode(',', array_map(static fn (array $file): string => $file['path'], $files)),
            'doubledecode' => 0,
        ]);

        return count($files);
    }

    /**
     * حالت «تعمیر سایت»: صفحه‌ی موقت به بازدیدکننده نشان داده می‌شود.
     *
     * سی‌پنل برای این کار API ندارد؛ کار را با همان چیزی می‌کنیم که خود
     * وب‌سرور می‌فهمد — یک .htaccess که همه را به maintenance.html می‌فرستد.
     * فایل قبلی کنار گذاشته می‌شود تا خاموش‌کردنش دقیقاً برش گرداند.
     */
    public static function setMaintenance(CpanelClient $client, string $docroot, bool $enable, string $message = ''): void
    {
        $docroot = self::normalizePath($docroot);
        $htaccess = $docroot . '/.htaccess';
        $backup = $docroot . '/.htaccess.teamkan';

        if (!$enable) {
            $saved = self::softCall(fn () => self::readFile($client, $backup), null);
            if (is_string($saved)) {
                self::saveFile($client, $htaccess, $saved);
                self::softCall(fn () => self::deletePath($client, $backup), null);
            } else {
                // چیزی برای برگرداندن نبود: فقط بلوک خودمان را حذف می‌کنیم.
                $current = (string) self::softCall(fn () => self::readFile($client, $htaccess), '');
                self::saveFile($client, $htaccess, self::stripBlock($current, 'MAINTENANCE'));
            }

            return;
        }

        $current = (string) self::softCall(fn () => self::readFile($client, $htaccess), '');
        if ($current !== '') {
            self::saveFile($client, $backup, $current);
        }

        $body = $message !== '' ? $message : 'سایت موقتاً در دست تعمیر است. لطفاً کمی بعد دوباره سر بزنید.';
        self::saveFile($client, $docroot . '/maintenance.html', self::maintenancePage($body));

        $rules = implode("\n", [
            '# TEAMKAN MAINTENANCE START',
            'RewriteEngine On',
            'RewriteCond %{REQUEST_URI} !^/maintenance\.html$',
            'RewriteCond %{REQUEST_FILENAME} !\.(css|js|png|jpg|jpeg|gif|svg|woff2?)$',
            'RewriteRule ^(.*)$ /maintenance.html [R=503,L]',
            'ErrorDocument 503 /maintenance.html',
            '# TEAMKAN MAINTENANCE END',
            '',
        ]);

        self::saveFile($client, $htaccess, $rules . self::stripBlock($current, 'MAINTENANCE'));
    }

    public static function isMaintenanceOn(CpanelClient $client, string $docroot): bool
    {
        $current = (string) self::softCall(fn () => self::readFile($client, self::normalizePath($docroot) . '/.htaccess'), '');

        return str_contains($current, 'TEAMKAN MAINTENANCE START');
    }

    /**
     * مسدودکردن آی‌پی روی سطح وب‌سرور (بلوک نشان‌دار در .htaccess).
     *
     * @return array<int, string> فهرست آی‌پی‌های مسدود بعد از تغییر
     */
    public static function blockIp(CpanelClient $client, string $docroot, string $ip, bool $block = true): array
    {
        $htaccess = self::normalizePath($docroot) . '/.htaccess';
        $current = (string) self::softCall(fn () => self::readFile($client, $htaccess), '');

        $ips = self::blockedIps($current);
        $ip = trim($ip);
        if ($block) {
            if ($ip !== '' && !in_array($ip, $ips, true)) {
                $ips[] = $ip;
            }
        } else {
            $ips = array_values(array_filter($ips, static fn (string $existing): bool => $existing !== $ip));
        }

        $lines = [];
        if ($ips !== []) {
            $lines[] = '# TEAMKAN BLOCKLIST START';
            $lines[] = '<RequireAll>';
            $lines[] = '    Require all granted';
            foreach ($ips as $entry) {
                $lines[] = '    Require not ip ' . $entry;
            }
            $lines[] = '</RequireAll>';
            $lines[] = '# TEAMKAN BLOCKLIST END';
            $lines[] = '';
        }
        $rules = implode("\n", $lines);

        self::saveFile($client, $htaccess, $rules . self::stripBlock($current, 'BLOCKLIST'));

        return $ips;
    }

    /**
     * @return array<int, string>
     */
    public static function blockedIps(string $htaccess): array
    {
        preg_match_all('/Require not ip ([^\s]+)/', $htaccess, $matches);

        return array_values(array_unique($matches[1] ?? []));
    }

    /**
     * بلوک نشان‌دار خودمان را از فایل برمی‌دارد تا دوباره‌نویسی تمیز باشد.
     */
    public static function stripBlock(string $content, string $name): string
    {
        $pattern = '/# TEAMKAN ' . preg_quote($name, '/') . ' START.*?# TEAMKAN ' . preg_quote($name, '/') . " END\n?/s";

        return (string) preg_replace($pattern, '', $content);
    }

    private static function maintenancePage(string $message): string
    {
        $safe = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');

        return implode("\n", [
            '<!doctype html>',
            '<html lang="fa" dir="rtl">',
            '<meta charset="utf-8">',
            '<meta name="viewport" content="width=device-width, initial-scale=1">',
            '<title>در دست تعمیر</title>',
            '<style>body{font-family:system-ui,Tahoma,sans-serif;display:flex;min-height:100vh;'
                . 'align-items:center;justify-content:center;margin:0;background:#f6f6f8;color:#222;'
                . 'text-align:center;padding:2rem}div{max-width:32rem;line-height:2}h1{font-size:1.4rem}</style>',
            '<div><h1>🛠 در دست تعمیر</h1><p>' . $safe . '</p></div>',
            '',
        ]);
    }

    /**
     * محافظت با رمز روی یک پوشه (همان Directory Privacy پنل).
     */
    public static function protectDirectory(CpanelClient $client, string $dir, string $label, bool $enable): void
    {
        $client->uapi('DirectoryPrivacy', 'configure_directory_protection', [
            'dir' => self::normalizePath($dir),
            'label' => $label !== '' ? $label : basename($dir),
            'enabled' => $enable ? 1 : 0,
        ]);
    }

    public static function addProtectedUser(CpanelClient $client, string $dir, string $user, string $password): void
    {
        $client->uapi('DirectoryPrivacy', 'add_user', [
            'dir' => self::normalizePath($dir),
            'user' => $user,
            'password' => $password,
        ]);
    }

    /**
     * مصرف هر پوشه‌ی سطح‌اول هوم — «چه چیزی فضا گرفته» به تفکیک پوشه.
     *
     * @return array<int, array{name: string, path: string, size: float, files: int}>
     */
    public static function directoryUsage(CpanelClient $client): array
    {
        $home = self::homeDir($client);
        $out = [];

        foreach (self::softCall(fn () => self::listFiles($client, $home), []) as $entry) {
            if (!$entry['dir']) {
                continue;
            }
            $children = self::softCall(fn () => self::listFiles($client, $entry['path']), []);
            $size = 0.0;
            foreach ($children as $child) {
                $size += (float) ($child['size'] ?? 0);
            }
            $out[] = ['name' => $entry['name'], 'path' => $entry['path'], 'size' => $size, 'files' => count($children)];
        }

        usort($out, static fn (array $a, array $b): int => $b['size'] <=> $a['size']);

        return array_slice($out, 0, 12);
    }

    /**
     * کپی محتوای یک سایت به پوشه‌ی سایت دیگر (مثلاً راه‌اندازی نسخه‌ی
     * استیجینگ یا انتقال سایت به دامنه‌ی جدید).
     *
     * @return int تعداد چیزهایی که کپی شد
     */
    public static function copySite(CpanelClient $client, string $from, string $to): int
    {
        $from = self::normalizePath($from);
        $to = self::normalizePath($to);
        if ($from === $to) {
            throw CpanelError::config('مبدأ و مقصد یکی است.');
        }

        $entries = self::listFiles($client, $from);
        if ($entries === []) {
            throw CpanelError::config("پوشه‌ی «{$from}» خالی است یا خوانده نشد.");
        }

        $client->api2('Fileman', 'fileop', [
            'op' => 'copy',
            'sourcefiles' => implode(',', array_map(static fn (array $entry): string => $entry['path'], $entries)),
            'destfiles' => $to,
            'doubledecode' => 0,
        ]);

        return count($entries);
    }

    /**
     * رکوردهای ایمیلی و ضروری یک دامنه، از دید دنیای بیرون.
     *
     * @return array{mx: array<int, string>, spf: string, dmarc: string, dkim: bool, problems: array<int, string>}
     */
    public static function mailRecords(string $domain): array
    {
        $domain = SiteCheck::cleanDomain($domain);

        $mx = [];
        foreach (@dns_get_record($domain, DNS_MX) ?: [] as $record) {
            if (isset($record['target'])) {
                $mx[] = (string) $record['pri'] . ' ' . (string) $record['target'];
            }
        }

        $spf = '';
        foreach (@dns_get_record($domain, DNS_TXT) ?: [] as $record) {
            $txt = (string) ($record['txt'] ?? '');
            if (str_starts_with(strtolower($txt), 'v=spf1')) {
                $spf = $txt;
            }
        }

        $dmarc = '';
        foreach (@dns_get_record('_dmarc.' . $domain, DNS_TXT) ?: [] as $record) {
            $txt = (string) ($record['txt'] ?? '');
            if (str_starts_with(strtolower($txt), 'v=dmarc1')) {
                $dmarc = $txt;
            }
        }

        $dkim = false;
        foreach (['default._domainkey.', 'x._domainkey.'] as $selector) {
            if ((@dns_get_record($selector . $domain, DNS_TXT) ?: []) !== []) {
                $dkim = true;
                break;
            }
        }

        $problems = [];
        if ($mx === []) {
            $problems[] = 'رکورد MX ندارد — این دامنه اصلاً ایمیل دریافت نمی‌کند.';
        }
        if ($spf === '') {
            $problems[] = 'رکورد SPF ندارد — ایمیل‌های ارسالی‌اش راحت‌تر اسپم می‌شوند.';
        } elseif (str_contains($spf, '+all')) {
            $problems[] = 'SPF با <code>+all</code> تنظیم شده که یعنی هر کسی می‌تواند به‌جای شما ایمیل بفرستد.';
        }
        if (!$dkim) {
            $problems[] = 'رکورد DKIM پیدا نشد — از cPanel بخش Email Deliverability می‌شود ساختش.';
        }
        if ($dmarc === '') {
            $problems[] = 'رکورد DMARC ندارد — توصیه می‌شود حداقل <code>v=DMARC1; p=none</code> بگذارید.';
        }

        return ['mx' => $mx, 'spf' => $spf, 'dmarc' => $dmarc, 'dkim' => $dkim, 'problems' => $problems];
    }

    /**
     * پاک‌سازی بکاپ‌های قدیمی و نگه‌داشتن فقط چند نسخه‌ی آخر.
     *
     * @return array{kept: int, deleted: array<int, string>}
     */
    public static function pruneBackups(CpanelClient $client, int $keep): array
    {
        $backups = self::listBackups($client);
        usort($backups, static fn (array $a, array $b): int => (int) (($b['time'] ?? 0) <=> ($a['time'] ?? 0)));

        $home = self::homeDir($client);
        $deleted = [];
        foreach (array_slice($backups, max(0, $keep)) as $backup) {
            $path = $home . '/' . $backup['name'];
            try {
                self::deletePath($client, $path);
                $deleted[] = $backup['name'];
            } catch (Throwable) {
                // اگر یکی پاک نشد، بقیه را ادامه می‌دهیم.
            }
        }

        return ['kept' => min(count($backups), max(0, $keep)), 'deleted' => $deleted];
    }

    /**
     * پرمصرف‌ترین حساب‌های سرور (فقط WHM).
     *
     * @return array<int, array{user: string, domain: string, disk: string, suspended: bool}>
     */
    public static function whmTopAccounts(CpanelClient $client, int $limit = 10): array
    {
        $accounts = self::whmAccounts($client);

        $rows = [];
        foreach ($accounts as $account) {
            $rows[] = [
                'user' => $account['user'],
                'domain' => $account['domain'],
                'disk' => (string) ($account['disk_used'] ?? ''),
                'suspended' => $account['suspended'],
                'bytes' => self::parseSize((string) ($account['disk_used'] ?? '')),
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['bytes'] <=> $a['bytes']);

        return array_map(
            static fn (array $row): array => [
                'user' => $row['user'],
                'domain' => $row['domain'],
                'disk' => $row['disk'],
                'suspended' => $row['suspended'],
            ],
            array_slice($rows, 0, $limit),
        );
    }

    /**
     * «1.5G» یا «250M» را به بایت تبدیل می‌کند (WHM اندازه‌ها را این‌طور می‌دهد).
     */
    public static function parseSize(string $value): float
    {
        $value = trim(strtoupper($value));
        if ($value === '' || $value === 'UNLIMITED') {
            return 0.0;
        }
        if (!preg_match('/^([\d.]+)\s*([KMGT])?/', $value, $matches)) {
            return 0.0;
        }

        $number = (float) $matches[1];

        return $number * match ($matches[2] ?? '') {
            'K' => 1024,
            'M' => 1024 ** 2,
            'G' => 1024 ** 3,
            'T' => 1024 ** 4,
            default => 1,
        };
    }

    /**
     * تعلیق یا رفع تعلیق چند حساب با هم (فقط WHM).
     *
     * @param array<int, string> $users
     *
     * @return array<int, array{email: string, ok: bool, error: string}>
     */
    public static function whmBulkSuspend(CpanelClient $client, array $users, bool $suspend, string $reason = ''): array
    {
        $results = [];
        foreach ($users as $user) {
            $user = trim($user);
            if ($user === '') {
                continue;
            }
            try {
                if ($suspend) {
                    self::whmSuspend($client, $user, $reason);
                } else {
                    self::whmUnsuspend($client, $user);
                }
                $results[] = ['email' => $user, 'ok' => true, 'error' => ''];
            } catch (Throwable $e) {
                $results[] = ['email' => $user, 'ok' => false, 'error' => $e->getMessage()];
            }
        }

        return $results;
    }

    /**
     * ساخت CSV از یک فهرست — برای بایگانی یا باز کردن در اکسل.
     *
     * @param array<int, string>              $headers
     * @param array<int, array<int, string>>  $rows
     */
    public static function toCsv(array $headers, array $rows): string
    {
        $handle = fopen('php://temp', 'r+');
        if ($handle === false) {
            return '';
        }

        // BOM تا اکسل فارسی را درست نشان بدهد.
        fwrite($handle, "\xEF\xBB\xBF");
        fputcsv($handle, $headers);
        foreach ($rows as $row) {
            fputcsv($handle, $row);
        }
        rewind($handle);
        $csv = (string) stream_get_contents($handle);
        fclose($handle);

        return $csv;
    }

    /**
     * بزرگ‌ترین فایل‌های یک پوشه — برای وقتی فضای هاست پر شده و باید فهمید
     * چه چیزی جا گرفته است.
     *
     * @return array<int, array{name: string, path: string, dir: bool, size: ?float, mtime: ?int, permissions: string}>
     */
    public static function largestFiles(CpanelClient $client, string $dir, int $limit = 10): array
    {
        $files = array_values(array_filter(self::listFiles($client, $dir), static fn (array $entry): bool => !$entry['dir']));
        usort($files, static fn (array $a, array $b): int => (int) (($b['size'] ?? 0) <=> ($a['size'] ?? 0)));

        return array_slice($files, 0, $limit);
    }

    /**
     * فایل‌هایی که تازه عوض شده‌اند — برای وقتی سایت ناگهان خراب شده.
     *
     * @return array<int, array{name: string, path: string, dir: bool, size: ?float, mtime: ?int, permissions: string}>
     */
    public static function recentFiles(CpanelClient $client, string $dir, int $limit = 10): array
    {
        $files = self::listFiles($client, $dir);
        usort($files, static fn (array $a, array $b): int => (int) (($b['mtime'] ?? 0) <=> ($a['mtime'] ?? 0)));

        return array_slice($files, 0, $limit);
    }

    public static function isArchive(string $name): bool
    {
        return (bool) preg_match('/\.(zip|tar|tgz|tar\.gz|tar\.bz2|gz|bz2|rar|7z)$/i', trim($name));
    }

    /**
     * مسیرها همیشه مطلق و بدون اسلش اضافه.
     */
    public static function normalizePath(string $path): string
    {
        $path = trim($path);
        $path = (string) preg_replace('#/+#', '/', $path);
        $path = rtrim($path, '/');

        return $path === '' ? '/' : $path;
    }

    // ------------------------------------------------------ فورواردر ایمیل

    /**
     * @return array<int, array{from: string, to: string}>
     */
    public static function emailForwarders(CpanelClient $client, string $domain = ''): array
    {
        $params = $domain !== '' ? ['domain' => $domain] : [];
        $raw = self::uapiOrApi2($client, ['Email', 'list_forwarders', $params], ['Email', 'listforwards', $params]);

        $out = [];
        foreach (self::normalizeList($raw) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'from' => (string) ($row['dest'] ?? ($row['forward'] ?? '')),
                'to' => (string) ($row['forward'] ?? ($row['dest'] ?? '')),
            ];
        }

        // UAPI کلیدها را برعکس API2 می‌دهد؛ با نگاه‌کردن به «@» درستش می‌کنیم.
        foreach ($out as $index => $row) {
            if (!str_contains($row['from'], '@') && str_contains($row['to'], '@')) {
                $out[$index] = ['from' => $row['to'], 'to' => $row['from']];
            }
        }

        return $out;
    }

    public static function addForwarder(CpanelClient $client, string $address, string $destination): void
    {
        [$local, $domain] = self::splitEmail($address);
        self::uapiOrApi2(
            $client,
            ['Email', 'add_forwarder', ['domain' => $domain, 'email' => $local, 'fwdopt' => 'fwd', 'fwdemail' => $destination]],
            ['Email', 'addforward', ['domain' => $domain, 'email' => $local, 'fwdopt' => 'fwd', 'fwdemail' => $destination]],
        );
    }

    public static function deleteForwarder(CpanelClient $client, string $address, string $destination): void
    {
        self::uapiOrApi2(
            $client,
            ['Email', 'delete_forwarder', ['address' => $address, 'forwarder' => $destination]],
            ['Email', 'delforward', ['emaildest' => $destination, 'email' => $address]],
        );
    }

    /**
     * ایمیلی که خروجی کران‌ها به آن فرستاده می‌شود.
     */
    public static function cronEmail(CpanelClient $client): string
    {
        $raw = self::softCall(fn () => self::uapiOrApi2($client, ['Cron', 'get_email'], ['Cron', 'get_email']), null);
        if (is_array($raw)) {
            return (string) ($raw['email'] ?? ($raw[0]['email'] ?? ''));
        }

        return (string) $raw;
    }

    public static function setCronEmail(CpanelClient $client, string $email): void
    {
        self::uapiOrApi2($client, ['Cron', 'set_email', ['email' => $email]], ['Cron', 'set_email', ['email' => $email]]);
    }

    // --------------------------------------------------------------- بکاپ

    /**
     * بکاپ‌هایی که روی حساب موجودند.
     *
     * @return array<int, array{name: string, size: ?float, time: ?int}>
     */
    public static function listBackups(CpanelClient $client): array
    {
        $raw = self::softCall(fn () => $client->uapi('Backup', 'list_backups'), null);

        // نسخه‌هایی که این تابع را ندارند: خود پوشه‌ی هوم را نگاه می‌کنیم؛
        // بکاپ کامل همان‌جا و با نام backup-*.tar.gz ساخته می‌شود.
        if ($raw === null || self::normalizeList($raw) === []) {
            $files = self::softCall(fn () => self::listFiles($client, self::homeDir($client)), []);
            $out = [];
            foreach ($files as $file) {
                if ($file['dir'] || !preg_match('/^backup.*\.(tar\.gz|tar|tgz|zip)$/i', $file['name'])) {
                    continue;
                }
                $out[] = ['name' => $file['name'], 'size' => $file['size'], 'time' => $file['mtime']];
            }

            return $out;
        }

        $out = [];
        foreach (self::normalizeList($raw) as $row) {
            if (is_string($row)) {
                $out[] = ['name' => $row, 'size' => null, 'time' => null];
                continue;
            }
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'name' => (string) ($row['file'] ?? ($row['name'] ?? '')),
                'size' => self::numberOrNull($row['size'] ?? null),
                'time' => isset($row['time']) ? (int) $row['time'] : null,
            ];
        }

        return $out;
    }

    /**
     * ساخت بکاپ کامل.
     *
     * روی نسخه‌های جدید UAPI Backup را دارد؛ روی نسخه‌های قدیمی‌تر همین کار
     * فقط با API1 قدیمی Fileman::fullbackup انجام می‌شود (ورودی‌هایش ترتیبی‌اند:
     * مقصد، ایمیل).
     */
    public static function fullBackup(CpanelClient $client, string $notifyEmail = ''): void
    {
        $email = trim($notifyEmail);

        try {
            $client->uapi('Backup', 'fullbackup_to_homedir', $email !== '' ? ['email' => $email] : []);

            return;
        } catch (CpanelError $e) {
            if (!self::isModuleMissing($e)) {
                throw $e;
            }
        }

        $client->api1('Fileman', 'fullbackup', ['homedir', $email]);
    }

    // ----------------------------------------------------------------- WHM

    /**
     * @return array<int, array{user: string, domain: string, email: string, plan: string, suspended: bool, disk_used: mixed, disk_limit: mixed, ip: string}>
     */
    public static function whmAccounts(CpanelClient $client, string $search = ''): array
    {
        $params = [];
        if (trim($search) !== '') {
            $params = ['search' => trim($search), 'searchtype' => 'user'];
        }
        $raw = $client->whm('listaccts', $params);
        $rows = is_array($raw) && isset($raw['acct']) ? $raw['acct'] : $raw;

        $out = [];
        foreach (self::normalizeList($rows) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = self::whmAccountRow($row);
        }
        usort($out, static fn (array $a, array $b): int => strcmp($a['user'], $b['user']));

        return $out;
    }

    /**
     * @return array{user: string, domain: string, email: string, plan: string, suspended: bool, disk_used: mixed, disk_limit: mixed, ip: string}
     */
    public static function whmAccountSummary(CpanelClient $client, string $user): array
    {
        $raw = $client->whm('accountsummary', ['user' => $user]);
        $rows = is_array($raw) && isset($raw['acct']) ? $raw['acct'] : $raw;
        $row = self::normalizeList($rows)[0] ?? null;
        if (!is_array($row)) {
            throw CpanelError::api("اطلاعات حساب «{$user}» برنگشت.");
        }

        return self::whmAccountRow($row);
    }

    public static function whmSuspend(CpanelClient $client, string $user, string $reason = ''): void
    {
        $params = ['user' => $user];
        if (trim($reason) !== '') {
            $params['reason'] = $reason;
        }
        $client->whm('suspendacct', $params);
    }

    public static function whmUnsuspend(CpanelClient $client, string $user): void
    {
        $client->whm('unsuspendacct', ['user' => $user]);
    }

    public static function whmCreateAccount(
        CpanelClient $client,
        string $username,
        string $domain,
        string $password,
        string $plan = '',
    ): void {
        $params = ['username' => $username, 'domain' => $domain, 'password' => $password];
        if (trim($plan) !== '') {
            $params['plan'] = $plan;
        }
        $client->whm('createacct', $params);
    }

    public static function whmTerminateAccount(CpanelClient $client, string $user, bool $keepDns = false): void
    {
        $client->whm('removeacct', ['user' => $user, 'keepdns' => $keepDns ? 1 : 0]);
    }

    public static function whmChangePassword(CpanelClient $client, string $user, string $password): void
    {
        $client->whm('passwd', ['user' => $user, 'password' => $password]);
    }

    /**
     * @return array<int, string>
     */
    public static function whmPackages(CpanelClient $client): array
    {
        $raw = $client->whm('listpkgs');
        $rows = is_array($raw) && isset($raw['pkg']) ? $raw['pkg'] : $raw;
        $out = [];
        foreach (self::normalizeList($rows) as $row) {
            $out[] = is_array($row) ? (string) ($row['name'] ?? '') : (string) $row;
        }

        return array_values(array_filter($out));
    }

    public static function whmChangePackage(CpanelClient $client, string $user, string $package): void
    {
        $client->whm('changepackage', ['user' => $user, 'pkg' => $package]);
    }

    public static function whmRestartService(CpanelClient $client, string $service): void
    {
        $client->whm('restartservice', ['service' => $service]);
    }

    /**
     * لینک ورود یک‌بارمصرف به cPanel یا WHM آن کاربر.
     */
    public static function whmLoginUrl(CpanelClient $client, string $user, string $service = 'cpaneld'): string
    {
        $raw = $client->whm('create_user_session', ['user' => $user, 'service' => $service]);
        $url = is_array($raw) ? (string) ($raw['url'] ?? '') : '';
        if ($url === '') {
            throw CpanelError::api('WHM لینک ورود برنگرداند (شاید توکن دسترسی create_user_session ندارد).');
        }

        return $url;
    }

    /**
     * @return array<string, mixed>
     */
    public static function whmServerStatus(CpanelClient $client): array
    {
        $load = self::softCall(fn () => $client->whm('systemloadavg'), null)
            ?? self::softCall(fn () => $client->whm('loadavg'), []);
        $version = self::softCall(fn () => $client->whm('version'), []);
        $hostname = self::softCall(fn () => $client->whm('gethostname'), []);
        $services = [];
        foreach (self::normalizeList(self::softCall(fn () => $client->whm('servicestatus'), [])) as $row) {
            if (!is_array($row)) {
                continue;
            }
            foreach (self::normalizeList($row['service'] ?? $row) as $service) {
                if (!is_array($service) || !isset($service['name'])) {
                    continue;
                }
                $services[] = [
                    'name' => (string) $service['name'],
                    'running' => (bool) ($service['running'] ?? false),
                    'monitored' => (bool) ($service['monitored'] ?? false),
                ];
            }
        }

        return [
            'hostname' => is_array($hostname) ? (string) ($hostname['hostname'] ?? '') : (string) $hostname,
            'version' => is_array($version) ? (string) ($version['version'] ?? '') : (string) $version,
            'load' => is_array($load) ? $load : [],
            'services' => $services,
        ];
    }

    // ------------------------------------------------------------- کمکی‌ها

    /**
     * @param array<string, mixed> $row
     *
     * @return array{user: string, domain: string, email: string, plan: string, suspended: bool, disk_used: mixed, disk_limit: mixed, ip: string}
     */
    private static function whmAccountRow(array $row): array
    {
        return [
            'user' => (string) ($row['user'] ?? ''),
            'domain' => (string) ($row['domain'] ?? ''),
            'email' => (string) ($row['email'] ?? ''),
            'plan' => (string) ($row['plan'] ?? ''),
            'suspended' => (bool) ($row['suspended'] ?? false),
            'disk_used' => $row['diskused'] ?? null,
            'disk_limit' => $row['disklimit'] ?? null,
            'ip' => (string) ($row['ip'] ?? ''),
        ];
    }

    /**
     * @return array{0: string, 1: string} [local, domain]
     */
    public static function splitEmail(string $email): array
    {
        $email = trim($email);
        $at = strrpos($email, '@');
        if ($at === false) {
            throw CpanelError::config("آدرس ایمیل نامعتبر است: «{$email}» (باید به‌شکل user@example.com باشد).");
        }

        return [substr($email, 0, $at), substr($email, $at + 1)];
    }

    private static function domainPart(string $email): string
    {
        $at = strrpos($email, '@');

        return $at === false ? '' : substr($email, $at + 1);
    }

    /**
     * «نامحدود»/خالی → 0 که برای cPanel یعنی بی‌نهایت.
     */
    private static function quotaValue(mixed $quotaMb): int
    {
        if ($quotaMb === null || $quotaMb === '') {
            return 0;
        }
        $text = strtolower(trim((string) $quotaMb));
        if (in_array($text, ['0', 'unlimited', 'نامحدود', 'بی‌نهایت'], true)) {
            return 0;
        }

        return max(0, (int) round((float) $text));
    }

    /**
     * پاسخ‌های cPanel گاهی لیست، گاهی دیکشنری‌ای از ردیف‌ها هستن.
     *
     * @return array<int, mixed>
     */
    public static function normalizeList(mixed $value): array
    {
        if ($value === null || $value === '') {
            return [];
        }
        if (!is_array($value)) {
            return [$value];
        }

        return array_is_list($value) ? $value : array_values($value);
    }

    /**
     * @return array<int, string>
     */
    private static function stringList(mixed $value): array
    {
        $out = [];
        foreach (self::normalizeList($value) as $item) {
            if (is_array($item)) {
                $item = $item['domain'] ?? ($item['name'] ?? null);
            }
            if (is_scalar($item) && trim((string) $item) !== '') {
                $out[] = trim((string) $item);
            }
        }

        return $out;
    }

    private static function numberOrNull(mixed $value): ?float
    {
        return is_numeric($value) ? (float) $value : null;
    }

    private static function mbToBytes(?float $mb): ?float
    {
        return $mb === null ? null : $mb * 1024 * 1024;
    }

    /**
     * فیلدهای با آندرلاین (_diskused) بایت‌اند و بدون آندرلاین مگابایت؛ هر دو
     * را به بایت برمی‌گردانیم تا لایه‌ی نمایش یک واحد بیشتر نبیند.
     *
     * @param array<string, mixed> $row
     */
    private static function usedBytes(array $row): ?float
    {
        return self::numberOrNull($row['_diskused'] ?? null)
            ?? self::mbToBytes(self::numberOrNull($row['diskused'] ?? null));
    }

    /**
     * سهمیه هم همان‌طور، با این تفاوت که مقدارهای غیرعددی («unlimited») باید
     * دست‌نخورده رد شوند تا Format::quota خودش «نامحدود» نشان بدهد.
     *
     * @param array<string, mixed> $row
     */
    private static function quotaBytes(array $row): mixed
    {
        $raw = $row['_diskquota'] ?? null;
        if ($raw !== null) {
            return $raw;
        }
        $raw = $row['diskquota'] ?? null;

        return is_numeric($raw) ? self::mbToBytes((float) $raw) : $raw;
    }

    /**
     * همان کار، اول با UAPI و اگر آن نسخه‌ی cPanel ماژولش را نداشت با API2.
     *
     * @param array{0: string, 1: string, 2?: array<string, mixed>} $uapi
     * @param array{0: string, 1: string, 2?: array<string, mixed>} $api2
     */
    private static function uapiOrApi2(CpanelClient $client, array $uapi, array $api2): mixed
    {
        try {
            return $client->uapi($uapi[0], $uapi[1], $uapi[2] ?? []);
        } catch (CpanelError $e) {
            if (!self::isModuleMissing($e)) {
                throw $e;
            }
        }

        return $client->api2($api2[0], $api2[1], $api2[2] ?? []);
    }

    /**
     * تشخیص «این ماژول روی این نسخه‌ی cPanel وجود ندارد» از بقیه‌ی خطاها —
     * تنها حالتی که برگشتن به API2 معنی می‌دهد.
     */
    private static function isModuleMissing(CpanelError $e): bool
    {
        if ($e->kind !== CpanelError::KIND_API) {
            return false;
        }

        foreach (['Failed to load module', "Can\'t locate", 'is not a valid module', 'does not exist', 'Undefined subroutine', 'No such module'] as $needle) {
            if (stripos($e->getMessage(), $needle) !== false) {
                return true;
            }
        }

        return false;
    }

    // ------------------------------------------------- قالب‌های آماده‌ی DNS

    /**
     * مجموعه‌رکوردهای آماده برای کارهای رایج. به‌جای اینکه کاربر MX و SPF را
     * دستی و با اولویت درست بنویسد، یک قالب را انتخاب می‌کند.
     *
     * @return array<string, array{label: string, hint: string, records: array<int, array{name: string, type: string, data: array<int, string>}>}>
     */
    public static function dnsTemplates(string $domain): array
    {
        $domain = rtrim(SiteCheck::cleanDomain($domain), '.');
        $dotted = $domain . '.';
        // مایکروسافت میزبان MX را از روی خود دامنه می‌سازد: example.com → example-com
        $microsoft = str_replace('.', '-', $domain) . '.mail.protection.outlook.com.';

        return [
            'google' => [
                'label' => '📧 Google Workspace',
                'hint' => 'ایمیل دامنه روی جیمیل سازمانی می‌نشیند (MX + SPF).',
                'records' => [
                    ['name' => $dotted, 'type' => 'MX', 'data' => ['1', 'SMTP.GOOGLE.COM.']],
                    ['name' => $dotted, 'type' => 'TXT', 'data' => ['v=spf1 include:_spf.google.com ~all']],
                ],
            ],
            'microsoft' => [
                'label' => '📧 Microsoft 365',
                'hint' => 'ایمیل دامنه روی اوت‌لوک سازمانی (MX + SPF + autodiscover).',
                'records' => [
                    ['name' => $dotted, 'type' => 'MX', 'data' => ['0', $microsoft]],
                    ['name' => $dotted, 'type' => 'TXT', 'data' => ['v=spf1 include:spf.protection.outlook.com -all']],
                    ['name' => 'autodiscover.' . $dotted, 'type' => 'CNAME', 'data' => ['autodiscover.outlook.com.']],
                ],
            ],
            'local_mail' => [
                'label' => '📬 ایمیل روی همین هاست',
                'hint' => 'نامه‌ها به خود سرور cPanel می‌رسند (MX + SPF).',
                'records' => [
                    ['name' => $dotted, 'type' => 'MX', 'data' => ['0', 'mail.' . $dotted]],
                    ['name' => $dotted, 'type' => 'TXT', 'data' => ['v=spf1 +mx +a ~all']],
                ],
            ],
            'dmarc' => [
                'label' => '🛡 DMARC (ضدجعل ایمیل)',
                'hint' => 'جلوی جعل آدرس ایمیل دامنه را می‌گیرد؛ حالت گزارش‌محور و بی‌خطر.',
                'records' => [
                    [
                        'name' => '_dmarc.' . $dotted,
                        'type' => 'TXT',
                        'data' => ['v=DMARC1; p=none; rua=mailto:postmaster@' . $domain],
                    ],
                ],
            ],
            'www' => [
                'label' => '🌐 اشاره‌ی www به دامنه',
                'hint' => 'اگر www.دامنه بالا نمی‌آید، همین یک رکورد معمولاً کافی است.',
                'records' => [
                    ['name' => 'www.' . $dotted, 'type' => 'CNAME', 'data' => [$dotted]],
                ],
            ],
        ];
    }

    /**
     * قالب را روی زون می‌نشاند. رکوردی که عیناً وجود دارد دوباره ساخته نمی‌شود
     * و اگر یکی از رکوردها رد شد، بقیه متوقف نمی‌شوند.
     *
     * @return array{added: array<int, string>, skipped: array<int, string>, failed: array<int, string>}
     */
    public static function applyDnsTemplate(CpanelClient $client, string $zone, string $template): array
    {
        $templates = self::dnsTemplates($zone);
        if (!isset($templates[$template])) {
            throw CpanelError::config("قالب DNS «{$template}» را نمی‌شناسم.");
        }

        $existing = [];
        foreach (self::dnsZone($client, $zone)['records'] as $record) {
            $existing[self::recordKey($record['type'], $record['name'], $record['data'])] = true;
        }

        $result = ['added' => [], 'skipped' => [], 'failed' => []];
        foreach ($templates[$template]['records'] as $record) {
            $label = $record['type'] . ' ' . rtrim($record['name'], '.') . ' → ' . implode(' ', $record['data']);
            if (isset($existing[self::recordKey($record['type'], $record['name'], $record['data'])])) {
                $result['skipped'][] = $label;
                continue;
            }
            try {
                self::addDnsRecord($client, $zone, $record['name'], $record['type'], $record['data']);
                $result['added'][] = $label;
            } catch (Throwable $e) {
                $result['failed'][] = $label . ' — ' . $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * کلید یکتای یک رکورد برای تشخیص تکراری‌بودن (نقطه‌ی آخر نام و بزرگی/کوچکی
     * حروف در DNS معنا ندارند).
     *
     * @param array<int, string> $data
     */
    private static function recordKey(string $type, string $name, array $data): string
    {
        $value = (string) preg_replace('/\s+/', ' ', implode(' ', $data));

        return strtoupper($type) . '|' . strtolower(rtrim(trim($name), '.')) . '|' . strtolower(trim($value));
    }

    /**
     * زون را به قالب استاندارد BIND درمی‌آورد تا بشود نگهش داشت یا روی سرور
     * دیگری واردش کرد.
     *
     * @param array{serial: ?string, records: array<int, array{line: int, name: string, ttl: ?int, type: string, data: array<int, string>}>, api2: bool} $zoneData
     */
    public static function zoneToBind(string $zone, array $zoneData): string
    {
        $lines = [
            '; zone: ' . $zone,
            '; گرفته‌شده با ربات cPanel TeamKan — ' . gmdate('Y-m-d H:i:s') . ' UTC',
        ];
        if (($zoneData['serial'] ?? null) !== null) {
            $lines[] = '; serial: ' . $zoneData['serial'];
        }
        $lines[] = '';

        foreach ($zoneData['records'] as $record) {
            if ($record['type'] === '' || $record['data'] === []) {
                continue;
            }
            $name = trim($record['name']) !== '' ? $record['name'] : '@';
            $ttl = ($record['ttl'] ?? null) !== null && (int) $record['ttl'] > 0 ? (string) $record['ttl'] : '14400';
            $value = strtoupper($record['type']) === 'TXT'
                ? self::quoteTxt($record['data'])
                : implode(' ', $record['data']);
            $lines[] = sprintf('%-32s %-7s IN  %-6s %s', $name, $ttl, strtoupper($record['type']), $value);
        }

        $lines[] = '';

        return implode("\n", $lines);
    }

    /**
     * @param array<int, string> $data
     */
    private static function quoteTxt(array $data): string
    {
        $out = [];
        foreach ($data as $chunk) {
            $chunk = (string) $chunk;
            $out[] = str_starts_with($chunk, '"') ? $chunk : '"' . str_replace('"', '\"', $chunk) . '"';
        }

        return implode(' ', $out);
    }

    // ------------------------------------------------------- گشت امنیتی

    /** فایل‌هایی که اگر از وب قابل‌دانلود باشند دردسر می‌سازند. */
    private const SENSITIVE_NAMES = [
        '.env' => 'متغیرهای محیطی؛ معمولاً رمز دیتابیس و کلیدهای سرویس‌ها داخلش است',
        '.env.bak' => 'نسخه‌ی پشتیبان متغیرهای محیطی',
        '.git' => 'کل تاریخچه‌ی سورس از روی وب قابل بازسازی است',
        '.svn' => 'کل سورس از روی وب قابل بازسازی است',
        '.htpasswd' => 'رمزهای محافظت پوشه',
        'wp-config.php.bak' => 'نسخه‌ی پشتیبان تنظیمات وردپرس (به‌صورت متن ساده دانلود می‌شود)',
        'wp-config.php.save' => 'نسخه‌ی پشتیبان تنظیمات وردپرس',
        'wp-config.php.old' => 'نسخه‌ی پشتیبان تنظیمات وردپرس',
        'config.php.bak' => 'نسخه‌ی پشتیبان فایل تنظیمات',
        'phpinfo.php' => 'اطلاعات کامل PHP و مسیرهای سرور را لو می‌دهد',
        'info.php' => 'معمولاً همان phpinfo است',
        'adminer.php' => 'ابزار مدیریت دیتابیس؛ اگر رمز نداشته باشد یعنی دیتابیس باز است',
        '.ssh' => 'کلیدهای SSH',
    ];

    /** پسوندهایی که داخل پوشه‌ی سایت نباید بمانند. */
    private const SENSITIVE_EXTENSIONS = [
        'sql' => 'خروجی دیتابیس؛ با یک لینک ساده دانلود می‌شود',
        'sql.gz' => 'خروجی فشرده‌ی دیتابیس',
        'zip' => 'آرشیو سورس یا بکاپ',
        'tar' => 'آرشیو سورس یا بکاپ',
        'tar.gz' => 'آرشیو سورس یا بکاپ',
        'tgz' => 'آرشیو سورس یا بکاپ',
        'bak' => 'نسخه‌ی پشتیبان فایل',
        'old' => 'نسخه‌ی قدیمی فایل',
        'swp' => 'فایل موقت ویرایشگر؛ محتوای فایل اصلی را دارد',
    ];

    /**
     * گشت امنیتی روی پوشه‌های سایت: دسترسی‌های خطرناک و فایل‌هایی که نباید از
     * وب قابل‌دانلود باشند.
     *
     * فقط پوشه‌های سایت (document root) گشته می‌شوند، چون فایل بیرون از آن‌ها
     * از وب دیده نمی‌شود.
     *
     * @return array{roots: array<int, string>, writable: array<int, array{path: string, perm: string, dir: bool}>, exposed: array<int, array{path: string, why: string}>, scanned: int}
     */
    public static function securityScan(CpanelClient $client, int $maxDirs = 24): array
    {
        $roots = [];
        foreach (self::softCall(fn () => self::domainsData($client), []) as $detail) {
            $docroot = trim((string) $detail['docroot']);
            if ($docroot !== '') {
                $roots[self::normalizePath($docroot)] = true;
            }
        }
        if ($roots === []) {
            $roots[self::homeDir($client) . '/public_html'] = true;
        }
        $roots = array_keys($roots);

        $queue = [];
        foreach ($roots as $root) {
            $queue[] = [$root, 0];
        }

        $writable = [];
        $exposed = [];
        $scanned = 0;

        while ($queue !== [] && $scanned < $maxDirs) {
            [$dir, $depth] = array_shift($queue);
            $entries = self::softCall(fn () => self::listFiles($client, $dir), []);
            $scanned++;

            foreach ($entries as $entry) {
                if (self::isWorldWritable($entry['permissions'])) {
                    $writable[] = ['path' => $entry['path'], 'perm' => $entry['permissions'], 'dir' => $entry['dir']];
                }

                $why = self::sensitiveReason($entry['name']);
                if ($why !== null) {
                    $exposed[] = ['path' => $entry['path'], 'why' => $why];
                }

                // فقط یک لایه پایین‌تر می‌رویم؛ گشتن کل سایت صدها درخواست API می‌شود.
                if ($entry['dir'] && $depth < 1 && !in_array($entry['name'], ['node_modules', 'vendor', 'cache', '.well-known'], true)) {
                    $queue[] = [$entry['path'], $depth + 1];
                }
            }
        }

        return ['roots' => $roots, 'writable' => $writable, 'exposed' => $exposed, 'scanned' => $scanned];
    }

    /**
     * دسترسی «نوشتن برای همه» (بیت ۲ در رقم آخر) — همان چیزی که با chmod 777
     * یا 666 ساخته می‌شود.
     */
    public static function isWorldWritable(string $permissions): bool
    {
        $clean = trim($permissions);
        if ($clean === '' || !ctype_digit($clean)) {
            return false;
        }
        $others = (int) substr($clean, -1);

        return ($others & 2) === 2;
    }

    /**
     * اگر این نام نباید داخل پوشه‌ی سایت باشد، دلیلش را برمی‌گرداند.
     */
    public static function sensitiveReason(string $name): ?string
    {
        $lower = strtolower(trim($name));
        if ($lower === '') {
            return null;
        }
        if (isset(self::SENSITIVE_NAMES[$lower])) {
            return self::SENSITIVE_NAMES[$lower];
        }
        foreach (self::SENSITIVE_EXTENSIONS as $extension => $why) {
            if (str_ends_with($lower, '.' . $extension)) {
                return $why;
            }
        }

        return null;
    }

    // --------------------------------------------- انتقال فایل بین دو سرور

    /**
     * یک فایل را از هاستی به هاست دیگر می‌برد. فایل از مسیر ربات عبور می‌کند،
     * پس برای فایل‌های خیلی بزرگ مناسب نیست.
     *
     * @return array{name: string, size: int, destination: string}
     */
    public static function transferFile(
        CpanelClient $from,
        string $path,
        CpanelClient $to,
        string $destination,
        int $maxBytes = 33554432,
    ): array {
        $path = self::normalizePath($path);
        $contents = self::downloadFile($from, $path);
        $size = strlen($contents);

        if ($size === 0) {
            throw CpanelError::config("فایل «{$path}» خالی بود یا خوانده نشد.");
        }
        if ($size > $maxBytes) {
            throw CpanelError::config(
                'حجم فایل حدود ' . round($size / 1048576, 1) . ' مگابایت است و سقف انتقال از راه ربات '
                . round($maxBytes / 1048576) . ' مگابایت است.'
                . "\n\n💡 اول فشرده‌اش کنید، یا فایل را تکه‌تکه بفرستید."
            );
        }

        $destination = self::normalizePath($destination);
        $name = basename($path);
        self::uploadFile($to, $destination, $name, $contents);

        return ['name' => $name, 'size' => $size, 'destination' => rtrim($destination, '/') . '/' . $name];
    }

    /**
     * عکس فوری از وضعیت یک هاست، برای مقایسه‌ی کنار هم.
     *
     * @return array{label: string, host: string, user: string, main_domain: string, cpanel: string, php: string, usage: array<int, array{label: string, percent: ?float}>, counts: array<string, int>, error: string}
     */
    public static function snapshot(CpanelClient $client, string $label = ''): array
    {
        $snapshot = [
            'label' => $label !== '' ? $label : $client->label(),
            'host' => $client->label(),
            'user' => $client->targetUser(),
            'main_domain' => '',
            'cpanel' => '',
            'php' => '',
            'usage' => [],
            'counts' => ['emails' => 0, 'databases' => 0, 'ftp' => 0, 'subdomains' => 0, 'addon' => 0],
            'error' => '',
        ];

        try {
            $summary = self::summary($client);
        } catch (Throwable $e) {
            $snapshot['error'] = $e->getMessage();

            return $snapshot;
        }

        $snapshot['user'] = (string) $summary['user'];
        $snapshot['main_domain'] = (string) ($summary['domains']['main_domain'] ?? '');
        $snapshot['cpanel'] = (string) ($summary['info']['cpanelversion'] ?? '');
        $snapshot['counts'] = $summary['counts'];

        foreach ($summary['usage'] as $item) {
            $id = strtolower((string) $item['id']);
            if (str_contains($id, 'disk') || str_contains($id, 'inode') || str_contains($id, 'bandwidth')) {
                $snapshot['usage'][] = ['label' => (string) $item['label'], 'percent' => $item['percent']];
            }
        }

        $main = $snapshot['main_domain'];
        foreach (self::softCall(fn () => self::phpVersions($client), []) as $vhost) {
            if ($main === '' || $vhost['vhost'] === $main) {
                $snapshot['php'] = (string) $vhost['version'];
                break;
            }
        }

        return $snapshot;
    }

    // ------------------------------------------- مصرف صندوق‌ها و دیتابیس‌ها

    /**
     * مرتب‌سازی صندوق‌های ایمیل بر اساس مصرف، به‌علاوه‌ی مجموع و تعداد پر.
     *
     * @param array<int, array{email: string, domain: string, used: ?float, quota: mixed, percent: ?float}> $emails
     *
     * @return array{rows: array<int, array{email: string, used: float, quota: mixed, percent: ?float}>, total: float, full: array<int, string>}
     */
    public static function mailboxUsage(array $emails): array
    {
        $rows = [];
        $total = 0.0;
        $full = [];

        foreach ($emails as $email) {
            $used = (float) ($email['used'] ?? 0);
            $total += $used;
            $percent = $email['percent'] ?? null;
            if ($percent !== null && (float) $percent >= 90) {
                $full[] = (string) $email['email'];
            }
            $rows[] = [
                'email' => (string) $email['email'],
                'used' => $used,
                'quota' => $email['quota'] ?? null,
                'percent' => $percent === null ? null : (float) $percent,
            ];
        }

        usort($rows, static fn (array $a, array $b): int => $b['used'] <=> $a['used']);

        return ['rows' => $rows, 'total' => $total, 'full' => $full];
    }

    /**
     * حجم هر دیتابیس، مرتب از بزرگ به کوچک، به‌علاوه‌ی دیتابیس‌های بی‌کاربر.
     *
     * @param array<int, array{name: string, size: ?float, users: array<int, string>}> $databases
     *
     * @return array{rows: array<int, array{name: string, size: float, users: int}>, total: float, orphans: array<int, string>}
     */
    public static function databaseSizes(array $databases): array
    {
        $rows = [];
        $total = 0.0;
        $orphans = [];

        foreach ($databases as $database) {
            $size = (float) ($database['size'] ?? 0);
            $total += $size;
            $users = count($database['users'] ?? []);
            if ($users === 0) {
                $orphans[] = (string) $database['name'];
            }
            $rows[] = ['name' => (string) $database['name'], 'size' => $size, 'users' => $users];
        }

        usort($rows, static fn (array $a, array $b): int => $b['size'] <=> $a['size']);

        return ['rows' => $rows, 'total' => $total, 'orphans' => $orphans];
    }

    // ------------------------------------------------------ تست زنده‌ی PHP

    /**
     * یک فایل PHP موقت داخل پوشه‌ی سایت می‌گذارد، از بیرون صدایش می‌زند و بعد
     * پاکش می‌کند. این تنها راهی است که می‌شود فهمید وب‌سرور واقعاً چه نسخه‌ای
     * از PHP و با چه محدودیت‌هایی اجرا می‌کند (نه آنچه در تنظیمات نوشته شده).
     *
     * @return array{domain: string, url: string, ok: bool, status: int, values: array<string, string>, error: string, cleaned: bool}
     */
    public static function phpLiveTest(CpanelClient $client, string $domain, string $docroot): array
    {
        $domain = SiteCheck::cleanDomain($domain);
        $docroot = self::normalizePath($docroot);
        $name = 'teamkan-phptest-' . bin2hex(random_bytes(6)) . '.php';
        $path = rtrim($docroot, '/') . '/' . $name;
        $url = 'https://' . $domain . '/' . $name;

        $result = ['domain' => $domain, 'url' => $url, 'ok' => false, 'status' => 0, 'values' => [], 'error' => '', 'cleaned' => false];

        self::uploadFile($client, $docroot, $name, self::phpProbeSource());

        try {
            [$status, $body, $error] = self::httpGet($url);
            if ($status === 0) {
                // بعضی سایت‌ها هنوز SSL ندارند؛ با http هم امتحان می‌کنیم.
                [$status, $body, $error] = self::httpGet('http://' . $domain . '/' . $name);
                $result['url'] = 'http://' . $domain . '/' . $name;
            }
            $result['status'] = $status;

            $decoded = json_decode($body, true);
            if (is_array($decoded)) {
                $values = [];
                foreach ($decoded as $key => $value) {
                    $values[(string) $key] = is_scalar($value) ? (string) $value : json_encode($value);
                }
                $result['values'] = $values;
                $result['ok'] = true;
            } elseif (str_contains($body, '<?php')) {
                $result['error'] = 'سرور فایل PHP را اجرا نکرد و متن خامش را برگرداند؛ یعنی PHP روی این دامنه فعال نیست.';
            } elseif ($error !== '') {
                $result['error'] = $error;
            } else {
                $result['error'] = $status === 0
                    ? 'دامنه از بیرون جواب نداد (DNS یا فایروال).'
                    : 'پاسخ سرور کد ' . $status . ' بود و خروجی تست خوانده نشد.';
            }
        } finally {
            // فایل تست تحت هر شرایطی باید برداشته شود.
            $result['cleaned'] = self::softCall(function () use ($client, $path): bool {
                self::deletePath($client, $path);

                return true;
            }, false) === true;
        }

        return $result;
    }

    /**
     * فایل کوچکی که وضعیت واقعی PHP را به‌صورت JSON برمی‌گرداند.
     */
    private static function phpProbeSource(): string
    {
        return implode("\n", [
            '<?php',
            "header('Content-Type: application/json');",
            'echo json_encode([',
            "    'نسخه‌ی PHP' => PHP_VERSION,",
            "    'حالت اجرا' => PHP_SAPI,",
            "    'حافظه' => (string) ini_get('memory_limit'),",
            "    'حداکثر زمان اجرا' => (string) ini_get('max_execution_time'),",
            "    'حداکثر حجم آپلود' => (string) ini_get('upload_max_filesize'),",
            "    'حداکثر حجم POST' => (string) ini_get('post_max_size'),",
            "    'نمایش خطاها' => ini_get('display_errors') ? 'روشن' : 'خاموش',",
            "    'MySQL' => extension_loaded('mysqli') || extension_loaded('pdo_mysql') ? 'دارد' : 'ندارد',",
            "    'cURL' => extension_loaded('curl') ? 'دارد' : 'ندارد',",
            "    'GD' => extension_loaded('gd') ? 'دارد' : 'ندارد',",
            "    'zip' => extension_loaded('zip') ? 'دارد' : 'ندارد',",
            "    'قابل نوشتن' => is_writable(__DIR__) ? 'بله' : 'خیر',",
            '], JSON_UNESCAPED_UNICODE);',
            '',
        ]);
    }

    /**
     * @return array{0: int, 1: string, 2: string}
     */
    private static function httpGet(string $url, int $timeout = 15, int $maxBytes = 0): array
    {
        $ch = curl_init($url);
        if ($ch === false) {
            return [0, '', 'curl در دسترس نیست.'];
        }
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_SSL_VERIFYPEER => false,
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_USERAGENT => 'cPanelTeamKan/1.0 (+php-test)',
        ]);

        // با سقف حجم، خودمان بایت‌ها را جمع می‌کنیم تا بشود وسط کار قطع کرد و
        // یک فایل چندگیگابایتی حافظه‌ی سرور را نخورد.
        $buffer = '';
        if ($maxBytes > 0) {
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, false);
            curl_setopt($ch, CURLOPT_WRITEFUNCTION, static function ($handle, string $chunk) use (&$buffer, $maxBytes): int {
                $buffer .= $chunk;

                return strlen($buffer) > $maxBytes ? 0 : strlen($chunk);
            });
        }

        $body = curl_exec($ch);
        if ($maxBytes > 0) {
            $body = $buffer;
        }
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_error($ch);
        curl_close($ch);

        return [$status, is_string($body) ? $body : '', $error];
    }

    // ------------------------------------------------- جلوگیری از هات‌لینک

    /**
     * هات‌لینک یعنی سایت دیگری مستقیم به تصویر شما لینک بدهد و پهنای‌باند شما
     * را خرج کند. جلویش با یک بلوک نشان‌دار در .htaccess گرفته می‌شود.
     *
     * @param array<int, string> $allowed دامنه‌هایی که اجازه دارند
     */
    public static function setHotlinkProtection(
        CpanelClient $client,
        string $docroot,
        bool $enable,
        array $allowed = [],
        string $extensions = 'jpe?g|png|gif|webp|svg|bmp|ico|mp4|webm|pdf',
    ): void {
        $htaccess = self::normalizePath($docroot) . '/.htaccess';
        $current = (string) self::softCall(fn () => self::readFile($client, $htaccess), '');

        if (!$enable) {
            self::saveFile($client, $htaccess, self::stripBlock($current, 'HOTLINK'));

            return;
        }

        $lines = [
            '# TEAMKAN HOTLINK START',
            'RewriteEngine On',
            'RewriteCond %{HTTP_REFERER} !^$',
        ];
        foreach (self::hotlinkHosts($allowed) as $host) {
            // پایان الگو بسته می‌شود تا example.com.evil.com هم مجاز حساب نشود.
            $lines[] = 'RewriteCond %{HTTP_REFERER} !^https?://([^/]+\.)?' . $host . '([:/]|$) [NC]';
        }
        $lines[] = 'RewriteRule \.(' . $extensions . ')$ - [F,NC]';
        $lines[] = '# TEAMKAN HOTLINK END';
        $lines[] = '';

        self::saveFile($client, $htaccess, implode("\n", $lines) . self::stripBlock($current, 'HOTLINK'));
    }

    /**
     * دامنه‌های مجاز را برای استفاده در الگوی regex آماده می‌کند.
     *
     * @param array<int, string> $allowed
     *
     * @return array<int, string>
     */
    public static function hotlinkHosts(array $allowed): array
    {
        $hosts = [];
        foreach ($allowed as $entry) {
            foreach (preg_split('/[\s,]+/', (string) $entry) ?: [] as $item) {
                $host = SiteCheck::cleanDomain((string) $item);
                $host = (string) preg_replace('/^www\./', '', $host);
                if ($host === '') {
                    continue;
                }
                $hosts[str_replace('.', '\.', $host)] = true;
            }
        }

        return array_keys($hosts);
    }

    public static function isHotlinkOn(CpanelClient $client, string $docroot): bool
    {
        $current = (string) self::softCall(fn () => self::readFile($client, self::normalizePath($docroot) . '/.htaccess'), '');

        return str_contains($current, 'TEAMKAN HOTLINK START');
    }

    // ---------------------------------------------------------- فیلتر ایمیل

    /**
     * فیلترهای سطح حساب (همان Email Filters پنل).
     *
     * @return array<int, array{name: string, enabled: bool, rules: string}>
     */
    public static function emailFilters(CpanelClient $client, string $account = ''): array
    {
        $params = $account === '' ? [] : ['account' => $account];
        $raw = $client->uapi('Email', 'list_filters', $params);

        $out = [];
        foreach (self::normalizeList($raw) as $row) {
            if (is_string($row)) {
                $out[] = ['name' => $row, 'enabled' => true, 'rules' => ''];
                continue;
            }
            if (!is_array($row)) {
                continue;
            }
            $name = (string) ($row['filtername'] ?? ($row['name'] ?? ''));
            if ($name === '') {
                continue;
            }
            $out[] = [
                'name' => $name,
                'enabled' => !isset($row['enabled']) || (string) $row['enabled'] === '1',
                'rules' => trim((string) ($row['filter'] ?? '')),
            ];
        }

        return $out;
    }

    /** بخش‌هایی از نامه که می‌شود رویشان فیلتر گذاشت. */
    public const FILTER_PARTS = [
        '$header_subject:' => 'موضوع نامه',
        '$header_from:' => 'فرستنده',
        '$header_to:' => 'گیرنده',
        '$message_body' => 'متن نامه',
    ];

    /** کاری که فیلتر انجام می‌دهد. */
    public const FILTER_ACTIONS = [
        'blackhole' => 'حذف بی‌صدا',
        'fail' => 'رد کردن با خطا',
        'save' => 'انداختن در پوشه',
    ];

    /**
     * ساخت فیلتر ایمیل. مقصد فقط برای «انداختن در پوشه» معنا دارد.
     */
    public static function addEmailFilter(
        CpanelClient $client,
        string $name,
        string $part,
        string $value,
        string $action,
        string $destination = '',
        string $account = '',
        string $match = 'contains',
    ): void {
        if (!isset(self::FILTER_PARTS[$part])) {
            throw CpanelError::config('بخش انتخاب‌شده‌ی نامه معتبر نیست.');
        }
        if (!isset(self::FILTER_ACTIONS[$action])) {
            throw CpanelError::config('عملیات انتخاب‌شده معتبر نیست.');
        }

        $destination = trim($destination);
        if ($action === 'save' && $destination === '') {
            $destination = 'INBOX';
        }

        $params = [
            'filtername' => $name,
            'part' => $part,
            'match' => $match,
            'val' => $value,
            'opt' => 'or',
            'action' => $action,
            'dest' => $destination,
        ];
        if ($account !== '') {
            $params['account'] = $account;
        }

        $client->uapi('Email', 'store_filter', $params);
    }

    public static function deleteEmailFilter(CpanelClient $client, string $name, string $account = ''): void
    {
        $params = ['filtername' => $name];
        if ($account !== '') {
            $params['account'] = $account;
        }

        $client->uapi('Email', 'delete_filter', $params);
    }

    /**
     * ردیابی ارسال نامه‌ها (همان Track Delivery پنل): آیا نامه تحویل شده،
     * در صف مانده، یا برگشت خورده است.
     *
     * @return array<int, array{time: ?int, sender: string, recipient: string, status: string, message: string}>
     */
    public static function mailDelivery(CpanelClient $client, string $recipient = '', int $limit = 15): array
    {
        $params = ['api.chunk.enable' => 1, 'api.chunk.size' => max(1, $limit)];
        if (trim($recipient) !== '') {
            $params['recipient'] = trim($recipient);
        }

        $raw = $client->uapi('EmailTrack', 'search', $params);

        $out = [];
        foreach (self::normalizeList($raw) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $out[] = [
                'time' => isset($row['sendunixtime']) ? (int) $row['sendunixtime'] : null,
                'sender' => (string) ($row['sender'] ?? ''),
                'recipient' => (string) ($row['recipient'] ?? ($row['deliveredto'] ?? '')),
                'status' => self::deliveryStatus($row),
                'message' => trim((string) ($row['message'] ?? '')),
            ];
            if (count($out) >= $limit) {
                break;
            }
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $row
     */
    private static function deliveryStatus(array $row): string
    {
        $type = strtolower((string) ($row['type'] ?? ''));
        if ($type !== '') {
            return match ($type) {
                'success' => 'تحویل شد',
                'defer', 'inprogress', 'queued' => 'در صف',
                'failure', 'bounce', 'error' => 'ناموفق',
                default => $type,
            };
        }
        if ((string) ($row['success'] ?? '') === '1') {
            return 'تحویل شد';
        }
        if ((string) ($row['defer'] ?? '') === '1') {
            return 'در صف';
        }

        return 'ناموفق';
    }

    // ------------------------------------------------------ خروجی دیتابیس

    /**
     * گرفتن خروجی SQL یک دیتابیس.
     *
     * UAPI برای این کار تابعی ندارد؛ خود cPanel از مسیر قدیمی
     * <code>/getsqlbackup/</code> استفاده می‌کند و ما هم همان را با توکن صدا
     * می‌زنیم. اگر روی این سرور بسته باشد، صریح می‌گوییم و راه جایگزین را
     * پیشنهاد می‌دهیم.
     *
     * @return array{name: string, content: string}
     */
    public static function exportDatabase(CpanelClient $client, string $database): array
    {
        $database = trim($database);
        if ($database === '') {
            throw CpanelError::config('نام دیتابیس خالی است.');
        }

        $content = self::softCall(
            fn () => $client->getRaw('getsqlbackup/' . rawurlencode($database) . '.sql.gz'),
            null,
        );

        if (is_string($content) && $content !== '' && str_starts_with($content, "\x1f\x8b")) {
            return ['name' => $database . '-' . gmdate('Ymd-Hi') . '.sql.gz', 'content' => $content];
        }

        // بعضی سرورها همان محتوا را بدون فشرده‌سازی می‌دهند.
        if (is_string($content) && str_starts_with(ltrim($content), '--') && str_contains($content, 'CREATE')) {
            return ['name' => $database . '-' . gmdate('Ymd-Hi') . '.sql', 'content' => $content];
        }

        throw CpanelError::api(
            "این هاست اجازه‌ی خروجی‌گرفتن مستقیم از دیتابیس «{$database}» را نمی‌دهد.\n\n"
            . '💡 به‌جایش «💾 بکاپ → گرفتن بکاپ کامل» را بزنید؛ دیتابیس‌ها هم داخل همان بکاپ هستند.'
        );
    }

    // -------------------------------------------------------- کار با فایل‌ها

    /** پسوندهایی که جست‌وجوی متن داخلشان بی‌معنی است. */
    private const BINARY_FOR_GREP = 'zip|tar|gz|tgz|bz2|rar|7z|jpe?g|png|gif|webp|ico|bmp|pdf|mp[34]|avi|mkv|mov|woff2?|ttf|eot|so|bin|sqlite3?|db|class|jar';

    /**
     * جست‌وجوی یک عبارت داخل فایل‌های متنی یک پوشه.
     *
     * هر فایل یک درخواست API است، پس تعداد فایل‌ها سقف دارد؛ سقف در خروجی
     * گزارش می‌شود تا کاربر بداند جست‌وجو کامل بوده یا نه.
     *
     * @return array{matches: array<int, array{path: string, line: int, text: string}>, scanned: int, skipped: int, complete: bool}
     */
    public static function grepFiles(
        CpanelClient $client,
        string $dir,
        string $needle,
        int $maxFiles = 25,
        int $maxSize = 524288,
    ): array {
        $needle = trim($needle);
        if ($needle === '') {
            throw CpanelError::config('عبارت جست‌وجو خالی است.');
        }

        $matches = [];
        $scanned = 0;
        $skipped = 0;
        $complete = true;
        $queue = [[self::normalizePath($dir), 0]];

        while ($queue !== []) {
            [$current, $depth] = array_shift($queue);

            foreach (self::softCall(fn () => self::listFiles($client, $current), []) as $entry) {
                if ($entry['dir']) {
                    if ($depth < 1 && !in_array($entry['name'], ['node_modules', 'vendor', 'cache', '.git', 'mail'], true)) {
                        $queue[] = [$entry['path'], $depth + 1];
                    }
                    continue;
                }
                if (preg_match('/\.(' . self::BINARY_FOR_GREP . ')$/i', $entry['name']) === 1
                    || (float) ($entry['size'] ?? 0) > $maxSize) {
                    $skipped++;
                    continue;
                }
                if ($scanned >= $maxFiles) {
                    $complete = false;
                    break 2;
                }

                $scanned++;
                $content = (string) self::softCall(fn () => self::readFile($client, $entry['path']), '');
                if ($content === '' || !str_contains($content, $needle)) {
                    continue;
                }
                foreach (explode("\n", $content) as $index => $line) {
                    if (!str_contains($line, $needle)) {
                        continue;
                    }
                    $matches[] = [
                        'path' => $entry['path'],
                        'line' => $index + 1,
                        'text' => trim(mb_substr($line, 0, 160)),
                    ];
                    if (count($matches) >= 30) {
                        break 3;
                    }
                }
            }
        }

        return ['matches' => $matches, 'scanned' => $scanned, 'skipped' => $skipped, 'complete' => $complete];
    }

    /**
     * اندازه‌ی یک پوشه با زیرپوشه‌هایش (تا سقف مشخصی از پوشه، تا تعداد
     * درخواست‌ها منفجر نشود).
     *
     * @return array{path: string, size: float, files: int, dirs: int, complete: bool, biggest: array<int, array{path: string, size: float}>}
     */
    public static function directorySize(CpanelClient $client, string $dir, int $maxDirs = 60): array
    {
        $root = self::normalizePath($dir);
        $queue = [$root];
        $size = 0.0;
        $files = 0;
        $dirs = 0;
        $perDir = [];
        $complete = true;

        while ($queue !== []) {
            if ($dirs >= $maxDirs) {
                $complete = false;
                break;
            }
            $current = array_shift($queue);
            $dirs++;

            foreach (self::softCall(fn () => self::listFiles($client, $current), []) as $entry) {
                if ($entry['dir']) {
                    $queue[] = $entry['path'];
                    continue;
                }
                $entrySize = (float) ($entry['size'] ?? 0);
                $size += $entrySize;
                $files++;
                $perDir[$current] = ($perDir[$current] ?? 0.0) + $entrySize;
            }
        }

        arsort($perDir);
        $biggest = [];
        foreach (array_slice($perDir, 0, 8, true) as $path => $dirSize) {
            $biggest[] = ['path' => (string) $path, 'size' => (float) $dirSize];
        }

        return ['path' => $root, 'size' => $size, 'files' => $files, 'dirs' => $dirs, 'complete' => $complete, 'biggest' => $biggest];
    }

    /**
     * فایل‌های قدیمی‌تر از N روز در یک پوشه (بدون زیرپوشه‌ها).
     *
     * @return array<int, array{path: string, name: string, size: float, mtime: int}>
     */
    public static function oldFiles(CpanelClient $client, string $dir, int $days): array
    {
        $cutoff = time() - max(1, $days) * 86400;
        $out = [];

        foreach (self::listFiles($client, $dir) as $entry) {
            if ($entry['dir'] || $entry['mtime'] === null || $entry['mtime'] >= $cutoff) {
                continue;
            }
            $out[] = [
                'path' => $entry['path'],
                'name' => $entry['name'],
                'size' => (float) ($entry['size'] ?? 0),
                'mtime' => (int) $entry['mtime'],
            ];
        }

        usort($out, static fn (array $a, array $b): int => $a['mtime'] <=> $b['mtime']);

        return $out;
    }

    /**
     * @param array<int, array{path: string, name: string, size: float, mtime: int}> $files
     *
     * @return array{deleted: int, freed: float, failed: array<int, string>}
     */
    public static function deleteFiles(CpanelClient $client, array $files): array
    {
        $deleted = 0;
        $freed = 0.0;
        $failed = [];

        foreach ($files as $file) {
            try {
                self::deletePath($client, $file['path']);
                $deleted++;
                $freed += $file['size'];
            } catch (Throwable $e) {
                $failed[] = $file['name'] . ' — ' . $e->getMessage();
            }
        }

        return ['deleted' => $deleted, 'freed' => $freed, 'failed' => $failed];
    }

    // ------------------------------------------------------ سخت‌سازی سایت

    /** پوشه‌هایی که معمولاً فایل کاربر داخلشان آپلود می‌شود. */
    private const UPLOAD_DIRS = ['wp-content/uploads', 'uploads', 'upload', 'storage/app/public', 'files', 'media'];

    /**
     * سخت‌سازی پوشه‌ی سایت: بستن لیست‌شدن فایل‌ها، بستن دسترسی وب به فایل‌های
     * حساس، و جلوگیری از اجرای PHP داخل پوشه‌های آپلود.
     *
     * @return array{htaccess: string, uploads: array<int, string>}
     */
    public static function hardenSite(CpanelClient $client, string $docroot, bool $enable): array
    {
        $docroot = self::normalizePath($docroot);
        $htaccess = $docroot . '/.htaccess';
        $current = (string) self::softCall(fn () => self::readFile($client, $htaccess), '');
        $touched = [];

        if (!$enable) {
            self::saveFile($client, $htaccess, self::stripBlock($current, 'HARDEN'));
            foreach (self::uploadDirs($client, $docroot) as $dir) {
                $guard = $dir . '/.htaccess';
                $body = (string) self::softCall(fn () => self::readFile($client, $guard), '');
                if (str_contains($body, 'TEAMKAN HARDEN START')) {
                    self::saveFile($client, $guard, self::stripBlock($body, 'HARDEN'));
                    $touched[] = $dir;
                }
            }

            return ['htaccess' => $htaccess, 'uploads' => $touched];
        }

        $rules = implode("\n", [
            '# TEAMKAN HARDEN START',
            '# لیست‌شدن محتوای پوشه‌ها بسته می‌شود',
            'Options -Indexes',
            '# فایل‌هایی که نباید از وب دانلود شوند',
            '<FilesMatch "(^\.(env|git|htpasswd|htaccess|npmrc|user\.ini)|\.(sql|bak|old|swp|dist|log)$)">',
            '    Require all denied',
            '</FilesMatch>',
            '# TEAMKAN HARDEN END',
            '',
        ]);
        self::saveFile($client, $htaccess, $rules . self::stripBlock($current, 'HARDEN'));

        $guardRules = implode("\n", [
            '# TEAMKAN HARDEN START',
            '# هیچ فایل PHPی داخل پوشه‌ی آپلود اجرا نمی‌شود',
            '<FilesMatch "\.(php[0-9]?|phtml|phar)$">',
            '    Require all denied',
            '</FilesMatch>',
            '# TEAMKAN HARDEN END',
            '',
        ]);
        foreach (self::uploadDirs($client, $docroot) as $dir) {
            $guard = $dir . '/.htaccess';
            $body = (string) self::softCall(fn () => self::readFile($client, $guard), '');
            self::saveFile($client, $guard, $guardRules . self::stripBlock($body, 'HARDEN'));
            $touched[] = $dir;
        }

        return ['htaccess' => $htaccess, 'uploads' => $touched];
    }

    /**
     * پوشه‌های آپلودی که واقعاً وجود دارند.
     *
     * @return array<int, string>
     */
    private static function uploadDirs(CpanelClient $client, string $docroot): array
    {
        $names = [];
        foreach (self::softCall(fn () => self::listFiles($client, $docroot), []) as $entry) {
            if ($entry['dir']) {
                $names[strtolower($entry['name'])] = $entry['path'];
            }
        }

        $found = [];
        foreach (self::UPLOAD_DIRS as $candidate) {
            $first = strtolower(explode('/', $candidate)[0]);
            if (!isset($names[$first])) {
                continue;
            }
            $path = rtrim($docroot, '/') . '/' . $candidate;
            // فقط وقتی مسیر کامل واقعاً هست (مثلاً wp-content/uploads).
            if (self::softCall(fn () => self::listFiles($client, $path), null) !== null) {
                $found[$path] = true;
            }
        }

        return array_keys($found);
    }

    public static function isHardened(CpanelClient $client, string $docroot): bool
    {
        $current = (string) self::softCall(fn () => self::readFile($client, self::normalizePath($docroot) . '/.htaccess'), '');

        return str_contains($current, 'TEAMKAN HARDEN START');
    }

    // ------------------------------------------------------- بررسی DNS واقعی

    /**
     * مقایسه‌ی رکوردهای زون روی هاست با آنچه دنیای بیرون واقعاً می‌بیند.
     *
     * @return array{domain: string, rows: array<int, array{type: string, name: string, zone: array<int, string>, world: array<int, string>, state: string}>, problems: array<int, string>}
     */
    public static function dnsCompare(CpanelClient $client, string $domain): array
    {
        $domain = SiteCheck::cleanDomain($domain);
        $zone = self::softCall(fn () => self::dnsZone($client, $domain), ['records' => [], 'serial' => null, 'api2' => false]);

        $wanted = [
            ['type' => 'A', 'name' => $domain],
            ['type' => 'A', 'name' => 'www.' . $domain],
            ['type' => 'MX', 'name' => $domain],
            ['type' => 'TXT', 'name' => $domain],
            ['type' => 'NS', 'name' => $domain],
        ];

        $rows = [];
        $problems = [];
        foreach ($wanted as $item) {
            $inZone = self::zoneValues($zone['records'] ?? [], $item['type'], $item['name']);
            $inWorld = self::worldValues($item['type'], $item['name']);

            if ($inZone === [] && $inWorld === []) {
                continue;
            }

            $state = 'match';
            if ($inZone !== [] && $inWorld === []) {
                $state = 'missing';
            } elseif ($inZone === [] && $inWorld !== []) {
                $state = 'extra';
            } elseif (array_diff($inZone, $inWorld) !== [] || array_diff($inWorld, $inZone) !== []) {
                $state = 'differs';
            }

            $rows[] = ['type' => $item['type'], 'name' => $item['name'], 'zone' => $inZone, 'world' => $inWorld, 'state' => $state];

            if ($state === 'missing') {
                $problems[] = "رکورد {$item['type']} برای «{$item['name']}» روی هاست هست ولی از بیرون دیده نمی‌شود.";
            } elseif ($state === 'differs') {
                $problems[] = "رکورد {$item['type']} برای «{$item['name']}» با چیزی که دنیا می‌بیند فرق دارد.";
            } elseif ($state === 'extra') {
                $problems[] = "رکورد {$item['type']} برای «{$item['name']}» از بیرون دیده می‌شود ولی در زون این هاست نیست.";
            }
        }

        if (($zone['records'] ?? []) === []) {
            $problems[] = 'زون این دامنه روی خود هاست پیدا نشد؛ احتمالاً DNS دامنه جای دیگری (مثل کلادفلر یا ثبت‌کننده) مدیریت می‌شود.';
        }

        return ['domain' => $domain, 'rows' => $rows, 'problems' => $problems];
    }

    /**
     * @param array<int, array{name: string, type: string, data: array<int, string>}> $records
     *
     * @return array<int, string>
     */
    private static function zoneValues(array $records, string $type, string $name): array
    {
        $name = strtolower(rtrim($name, '.'));
        $out = [];
        foreach ($records as $record) {
            if (strtoupper($record['type']) !== $type) {
                continue;
            }
            if (strtolower(rtrim($record['name'], '.')) !== $name) {
                continue;
            }
            $out[] = strtolower(trim(implode(' ', $record['data'])));
        }
        sort($out);

        return array_values(array_unique($out));
    }

    /**
     * @return array<int, string>
     */
    private static function worldValues(string $type, string $name): array
    {
        $map = ['A' => DNS_A, 'MX' => DNS_MX, 'TXT' => DNS_TXT, 'NS' => DNS_NS, 'CNAME' => DNS_CNAME];
        $records = @dns_get_record($name, $map[$type] ?? DNS_A) ?: [];

        $out = [];
        foreach ($records as $record) {
            $value = match ($type) {
                'A' => (string) ($record['ip'] ?? ''),
                'MX' => trim(($record['pri'] ?? '') . ' ' . ($record['target'] ?? '')),
                'TXT' => (string) ($record['txt'] ?? ''),
                'NS', 'CNAME' => (string) ($record['target'] ?? ''),
                default => '',
            };
            $value = strtolower(trim($value));
            if ($value !== '') {
                $out[] = $value;
            }
        }
        sort($out);

        return array_values(array_unique($out));
    }

    // ------------------------------------------------------------ گواهی SSL

    /**
     * نصب گواهی دستی (وقتی گواهی را از جای دیگری خریده‌اید).
     */
    public static function installSsl(CpanelClient $client, string $domain, string $certificate, string $key, string $bundle = ''): void
    {
        $params = ['domain' => $domain, 'cert' => trim($certificate), 'key' => trim($key)];
        if (trim($bundle) !== '') {
            $params['cabundle'] = trim($bundle);
        }

        $client->uapi('SSL', 'install_ssl', $params);
    }

    public static function deleteSsl(CpanelClient $client, string $domain): void
    {
        $client->uapi('SSL', 'delete_ssl', ['domain' => $domain]);
    }

    // ----------------------------------------------------- محدودیت‌های PHP

    /** محدودیت‌هایی که از داخل ربات می‌شود عوض کرد. */
    public const PHP_DIRECTIVES = [
        'memory_limit' => 'حافظه‌ی مجاز هر درخواست',
        'upload_max_filesize' => 'حداکثر حجم فایل آپلودی',
        'post_max_size' => 'حداکثر حجم کل درخواست',
        'max_execution_time' => 'حداکثر زمان اجرا (ثانیه)',
        'max_input_vars' => 'حداکثر تعداد فیلد فرم',
        'display_errors' => 'نمایش خطاها روی صفحه',
    ];

    public static function setPhpLimit(CpanelClient $client, string $vhost, string $directive, string $value): void
    {
        if (!isset(self::PHP_DIRECTIVES[$directive])) {
            throw CpanelError::config("محدودیت «{$directive}» قابل تغییر نیست.");
        }

        $client->uapi('LangPHP', 'php_ini_set_user_basic_directives', [
            'type' => 'vhost',
            'vhost' => $vhost,
            'directive' => [$directive . ':' . $value],
        ]);
    }

    // ----------------------------------------------------------- قالب کران

    /**
     * کران‌های آماده‌ی کارهای رایج.
     *
     * @return array<string, array{label: string, hint: string, schedule: string, command: string, needs: string}>
     */
    public static function cronTemplates(string $php = '/usr/local/bin/php'): array
    {
        return [
            'wordpress' => [
                'label' => '🟦 کران وردپرس (هر ۵ دقیقه)',
                'hint' => 'وقتی سایت کم‌بازدید است، کارهای زمان‌بندی‌شده‌ی وردپرس اجرا نمی‌شوند؛ این کران خودش اجرایشان می‌کند.',
                'schedule' => '*/5 * * * *',
                'command' => 'cd {docroot} && ' . $php . ' -q wp-cron.php >/dev/null 2>&1',
                'needs' => 'docroot',
            ],
            'laravel' => [
                'label' => '🟥 زمان‌بند لاراول (هر دقیقه)',
                'hint' => 'لاراول برای زمان‌بندی کارهایش دقیقاً به همین یک کران نیاز دارد.',
                'schedule' => '* * * * *',
                'command' => 'cd {docroot} && ' . $php . ' artisan schedule:run >/dev/null 2>&1',
                'needs' => 'docroot',
            ],
            'queue' => [
                'label' => '🟨 اجرای صف لاراول (هر ۵ دقیقه)',
                'hint' => 'کارهای پس‌زمینه‌ی صف را جلو می‌برد بدون اینکه به daemon نیاز باشد.',
                'schedule' => '*/5 * * * *',
                'command' => 'cd {docroot} && ' . $php . ' artisan queue:work --stop-when-empty >/dev/null 2>&1',
                'needs' => 'docroot',
            ],
            'logs' => [
                'label' => '🧹 پاک‌کردن لاگ‌های قدیمی (هفتگی)',
                'hint' => 'فایل‌های لاگ قدیمی‌تر از ۳۰ روز را پاک می‌کند تا فضای هاست پر نشود.',
                'schedule' => '0 3 * * 0',
                'command' => 'find {docroot}/logs -type f -mtime +30 -delete >/dev/null 2>&1',
                'needs' => 'docroot',
            ],
            'keepalive' => [
                'label' => '🔄 بیدار نگه‌داشتن سایت (هر ۱۰ دقیقه)',
                'hint' => 'یک درخواست ساده به سایت می‌زند تا کش گرم بماند و سایت کند باز نشود.',
                'schedule' => '*/10 * * * *',
                'command' => 'curl -s -o /dev/null https://{domain}/',
                'needs' => 'domain',
            ],
        ];
    }

    // -------------------------------------------------------- کلیدهای SSH

    /**
     * @return array<int, array{name: string, type: string, authorized: bool, fingerprint: string}>
     */
    public static function sshKeys(CpanelClient $client): array
    {
        $raw = $client->uapi('SSH', 'list_keys');
        $out = [];

        foreach (self::normalizeList($raw) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $name = (string) ($row['name'] ?? ($row['file'] ?? ''));
            if ($name === '') {
                continue;
            }
            $authorized = $row['authorized'] ?? ($row['auth_status'] ?? null);
            $out[] = [
                'name' => $name,
                'type' => (string) ($row['key_type'] ?? ($row['type'] ?? '')),
                'authorized' => in_array((string) $authorized, ['1', 'authorized', 'true'], true),
                'fingerprint' => (string) ($row['fingerprint'] ?? ''),
            ];
        }

        return $out;
    }

    /**
     * وارد کردن یک کلید عمومی و در همان قدم مجاز کردنش.
     */
    public static function importSshKey(CpanelClient $client, string $name, string $publicKey): void
    {
        $publicKey = trim($publicKey);
        if (!str_starts_with($publicKey, 'ssh-') && !str_starts_with($publicKey, 'ecdsa-')) {
            throw CpanelError::config(
                "این کلید عمومی به نظر نمی‌رسد؛ محتوای فایل <code>id_rsa.pub</code> (که با <code>ssh-rsa</code> یا <code>ssh-ed25519</code> شروع می‌شود) را بفرستید."
            );
        }

        $client->uapi('SSH', 'import_key', ['name' => $name, 'key' => $publicKey]);
        $client->uapi('SSH', 'authorize_key', ['key' => $publicKey]);
    }

    public static function deleteSshKey(CpanelClient $client, string $name): void
    {
        $client->uapi('SSH', 'delete_key', ['name' => $name, 'type' => 'public']);
    }

    // -------------------------------------------------------- کارهای WHM

    public static function whmSetQuota(CpanelClient $client, string $user, mixed $quotaMb): void
    {
        $quota = is_numeric($quotaMb) ? (int) $quotaMb : 0;
        $client->whm('modifyacct', ['user' => $user, 'QUOTA' => $quota]);
    }

    /**
     * @return array<int, array{ip: string, used: bool, dedicated: bool}>
     */
    public static function whmIps(CpanelClient $client): array
    {
        $raw = $client->whm('listips');
        // بسته به نسخه، فهرست زیر کلید ip یا result می‌آید.
        $rows = is_array($raw) ? ($raw['ip'] ?? ($raw['result'] ?? $raw)) : $raw;
        $out = [];

        foreach (self::normalizeList($rows) as $row) {
            if (!is_array($row)) {
                continue;
            }
            $ip = (string) ($row['ip'] ?? ($row['public_ip'] ?? ''));
            if ($ip === '') {
                continue;
            }
            $out[] = [
                'ip' => $ip,
                'used' => (string) ($row['used'] ?? '0') === '1',
                'dedicated' => (string) ($row['dedicated'] ?? '0') === '1',
            ];
        }

        return $out;
    }

    /**
     * @return array<int, array{user: string, reason: string, time: string}>
     */
    public static function whmSuspended(CpanelClient $client): array
    {
        $raw = $client->whm('listsuspended');
        $rows = is_array($raw) ? ($raw['accts'] ?? ($raw['suspended'] ?? ($raw['account'] ?? $raw))) : $raw;
        $out = [];

        foreach (self::normalizeList($rows) as $row) {
            if (is_string($row)) {
                $out[] = ['user' => $row, 'reason' => '', 'time' => ''];
                continue;
            }
            if (!is_array($row)) {
                continue;
            }
            $user = (string) ($row['user'] ?? '');
            if ($user === '') {
                continue;
            }
            $out[] = [
                'user' => $user,
                'reason' => (string) ($row['reason'] ?? ''),
                'time' => (string) ($row['time'] ?? ($row['suspendtime'] ?? '')),
            ];
        }

        return $out;
    }

    // ------------------------------------------------------- چک‌آپ کامل

    /**
     * چند بررسی مهم را یک‌جا انجام می‌دهد و برای هرکدام وضعیت و راهکار می‌دهد.
     *
     * @return array{checks: array<int, array{label: string, state: string, detail: string, hint: string}>, score: int}
     */
    public static function healthCheck(CpanelClient $client): array
    {
        $checks = [];
        $add = static function (string $label, string $state, string $detail, string $hint = '') use (&$checks): void {
            $checks[] = ['label' => $label, 'state' => $state, 'detail' => $detail, 'hint' => $hint];
        };

        // ---- مصرف دیسک و اینود
        foreach (self::softCall(fn () => self::resourceUsage($client), []) as $item) {
            $id = strtolower((string) $item['id']);
            if (!str_contains($id, 'disk') && !str_contains($id, 'inode')) {
                continue;
            }
            $percent = $item['percent'];
            if ($percent === null) {
                continue;
            }
            $add(
                (string) $item['label'],
                $percent >= 90 ? 'bad' : ($percent >= 80 ? 'warn' : 'ok'),
                round($percent) . '٪ پر شده',
                $percent >= 80 ? '«📂 فایل‌ها → 🧹 پاک‌سازی هوشمند» را بزنید.' : '',
            );
        }

        // ---- گواهی SSL
        $soonest = null;
        foreach (self::softCall(fn () => self::sslHosts($client), []) as $host) {
            if (!is_numeric($host['expires'])) {
                continue;
            }
            $days = (int) floor(((int) $host['expires'] - time()) / 86400);
            if ($soonest === null || $days < $soonest[0]) {
                $soonest = [$days, (string) $host['domain']];
            }
        }
        if ($soonest === null) {
            $add('گواهی SSL', 'warn', 'گواهی نصب‌شده‌ای پیدا نشد', '«🔒 SSL → درخواست صدور/تمدید خودکار» را بزنید.');
        } else {
            [$days, $domain] = $soonest;
            $add(
                'گواهی SSL',
                $days < 0 ? 'bad' : ($days <= 14 ? 'warn' : 'ok'),
                $days < 0 ? "گواهی {$domain} منقضی شده" : "نزدیک‌ترین انقضا: {$domain} تا {$days} روز دیگر",
                $days <= 14 ? '«🔒 SSL → درخواست صدور/تمدید خودکار» را بزنید.' : '',
            );
        }

        // ---- تازگی بکاپ
        $newest = null;
        foreach (self::softCall(fn () => self::listBackups($client), []) as $backup) {
            if ($backup['time'] !== null && ($newest === null || $backup['time'] > $newest)) {
                $newest = (int) $backup['time'];
            }
        }
        if ($newest === null) {
            $add('بکاپ', 'bad', 'هیچ بکاپی روی حساب نیست', '«💾 بکاپ → گرفتن بکاپ کامل» یا «🗓 بکاپ خودکار» را تنظیم کنید.');
        } else {
            $days = (int) floor((time() - $newest) / 86400);
            $add(
                'بکاپ',
                $days > 30 ? 'bad' : ($days > 7 ? 'warn' : 'ok'),
                "آخرین بکاپ {$days} روز پیش",
                $days > 7 ? '«🔔 هشدارها → 🗓 بکاپ خودکار» را روشن کنید.' : '',
            );
        }

        // ---- بالا بودن سایت اصلی
        $domains = self::softCall(fn () => self::domains($client), []);
        $main = (string) ($domains['main_domain'] ?? '');
        if ($main !== '') {
            $result = SiteCheck::run($main);
            $status = (int) ($result['https']['status'] ?? 0);
            $add(
                'سایت اصلی',
                $status === 0 ? 'bad' : ($status >= 400 ? 'warn' : 'ok'),
                $status === 0 ? "{$main} از بیرون جواب نداد" : "{$main} کد {$status} داد",
                $status === 0 || $status >= 400 ? '«🌐 دامنه‌ها → 🩺 بررسی سلامت دامنه» جزئیات را می‌گوید.' : '',
            );

            // ---- رکوردهای ایمیل
            $records = self::softCall(fn () => self::mailRecords($main), null);
            if (is_array($records)) {
                $missing = [];
                if ($records['mx'] === []) {
                    $missing[] = 'MX';
                }
                if ($records['spf'] === '') {
                    $missing[] = 'SPF';
                }
                if ($records['dmarc'] === '') {
                    $missing[] = 'DMARC';
                }
                $add(
                    'رکوردهای ایمیل',
                    in_array('MX', $missing, true) ? 'bad' : ($missing === [] ? 'ok' : 'warn'),
                    $missing === [] ? 'MX و SPF و DMARC سرجایشان هستند' : 'کم است: ' . implode('، ', $missing),
                    $missing === [] ? '' : '«🧭 DNS → 🧩 قالب آماده» همین‌ها را یک‌جا می‌سازد.',
                );
            }
        }

        // ---- فایل‌های حساس داخل پوشه‌ی سایت
        $scan = self::softCall(fn () => self::securityScan($client, 8), null);
        if (is_array($scan)) {
            $exposed = count($scan['exposed']);
            $writable = count($scan['writable']);
            $add(
                'امنیت فایل‌ها',
                $exposed > 0 ? 'bad' : ($writable > 0 ? 'warn' : 'ok'),
                $exposed === 0 && $writable === 0
                    ? 'فایل حساس و دسترسی بازی پیدا نشد'
                    : "{$exposed} فایل حساس و {$writable} دسترسی باز",
                $exposed > 0 || $writable > 0 ? '«📂 فایل‌ها → 🛡 گزارش امنیتی» فهرست کامل را می‌دهد.' : '',
            );
        }

        // ---- نسخه‌ی PHP
        foreach (self::softCall(fn () => self::phpVersions($client), []) as $vhost) {
            if ($main !== '' && $vhost['vhost'] !== $main) {
                continue;
            }
            $version = (string) $vhost['version'];
            $old = (bool) preg_match('/php[-_]?(5|70|71|72|73|74)/i', str_replace('.', '', $version));
            $add(
                'نسخه‌ی PHP',
                $old ? 'warn' : 'ok',
                $version,
                $old ? 'نسخه‌های قدیمی PHP دیگر وصله‌ی امنیتی نمی‌گیرند؛ «🐘 نسخه PHP → 🔧 تغییر نسخه».' : '',
            );
            break;
        }

        $score = 0;
        foreach ($checks as $check) {
            $score += match ($check['state']) {
                'ok' => 2,
                'warn' => 1,
                default => 0,
            };
        }
        $max = max(1, count($checks) * 2);

        return ['checks' => $checks, 'score' => (int) round($score / $max * 100)];
    }

    // -------------------------------------------------- فیلتر و صندوق ایمیل

    public static function toggleEmailFilter(CpanelClient $client, string $name, bool $enable, string $account = ''): void
    {
        $params = ['filtername' => $name];
        if ($account !== '') {
            $params['account'] = $account;
        }

        $client->uapi('Email', $enable ? 'enable_filter' : 'disable_filter', $params);
    }

    /** حالت‌های تعلیق یک صندوق ایمیل. */
    public const MAIL_SUSPEND = [
        'outgoing' => 'جلوگیری از ارسال (صندوق اسپم می‌فرستد)',
        'incoming' => 'جلوگیری از دریافت',
        'none' => 'برداشتن هر دو محدودیت',
    ];

    /**
     * تعلیق ارسال یا دریافت یک صندوق — همان کاری که وقتی صندوقی هک شده و
     * دارد اسپم می‌فرستد باید فوری انجام داد.
     */
    public static function setMailSuspension(CpanelClient $client, string $email, string $mode): void
    {
        if (!isset(self::MAIL_SUSPEND[$mode])) {
            throw CpanelError::config('حالت انتخاب‌شده معتبر نیست.');
        }

        if ($mode === 'none') {
            self::softCall(fn () => $client->uapi('Email', 'unsuspend_outgoing', ['email' => $email]), null);
            self::softCall(fn () => $client->uapi('Email', 'unsuspend_incoming', ['email' => $email]), null);

            return;
        }

        $client->uapi('Email', $mode === 'outgoing' ? 'suspend_outgoing' : 'suspend_incoming', ['email' => $email]);
    }

    /** کاری که با نامه‌های رسیده به آدرس‌های ناموجود یک دامنه می‌شود. */
    public const CATCHALL_MODES = [
        'fail' => 'برگرداندن با پیام خطا (پیشنهاد می‌شود)',
        'blackhole' => 'دور ریختن بی‌صدا',
        'fwd' => 'فرستادن به یک صندوق',
    ];

    /**
     * «صندوق پیش‌فرض» دامنه: تکلیف نامه‌هایی که به آدرس ناموجود می‌رسند.
     *
     * روی نسخه‌های تازه UAPI این کار را می‌کند و روی بقیه API2؛ چون اینجا
     * ممکن است خود تابع نباشد (نه ماژول)، هر خطای منطقی را هم fallback
     * حساب می‌کنیم.
     */
    public static function setCatchAll(CpanelClient $client, string $domain, string $mode, string $destination = ''): void
    {
        if (!isset(self::CATCHALL_MODES[$mode])) {
            throw CpanelError::config('حالت انتخاب‌شده معتبر نیست.');
        }
        if ($mode === 'fwd' && trim($destination) === '') {
            throw CpanelError::config('برای فرستادن نامه‌ها، آدرس مقصد را هم بدهید.');
        }

        $message = 'این آدرس روی این دامنه وجود ندارد.';
        try {
            $client->uapi('Email', 'set_default_address', [
                'domain' => $domain,
                'fwdopt' => $mode,
                'fwdemail' => $mode === 'fwd' ? trim($destination) : '',
                'failmsgs' => $mode === 'fail' ? $message : '',
            ]);

            return;
        } catch (CpanelError $e) {
            if (in_array($e->kind, [CpanelError::KIND_AUTH, CpanelError::KIND_TRANSPORT], true)) {
                throw $e;
            }
        }

        $client->api2('Email', 'setdefaultaddress', [
            'domain' => $domain,
            'fwdopt' => $mode,
            'fwdemail' => $mode === 'fwd' ? trim($destination) : '',
            'failmsgs' => $mode === 'fail' ? $message : '',
        ]);
    }

    // ------------------------------------------------ راه‌اندازی دیتابیس

    /**
     * ساخت دیتابیس، کاربر و دادن دسترسی کامل — سه قدمی که همیشه با هم لازم‌اند.
     *
     * @return array{database: string, user: string, password: string, host: string, done: array<int, string>}
     */
    public static function setupDatabase(CpanelClient $client, string $name, string $user, string $password): array
    {
        $done = [];

        self::createDatabase($client, $name);
        $done[] = 'دیتابیس ساخته شد';

        try {
            self::createDatabaseUser($client, $user, $password);
            $done[] = 'کاربر ساخته شد';
        } catch (Throwable $e) {
            // اگر دیتابیس ساخته شده ولی کاربر نه، نصفه‌کاره رها نمی‌کنیم.
            self::softCall(fn () => self::deleteDatabase($client, $name), null);

            throw $e;
        }

        try {
            self::grantDatabaseUser($client, $user, $name);
            $done[] = 'دسترسی کامل داده شد';
        } catch (Throwable $e) {
            self::softCall(fn () => self::deleteDatabaseUser($client, $user), null);
            self::softCall(fn () => self::deleteDatabase($client, $name), null);

            throw $e;
        }

        $info = self::softCall(fn () => self::mysqlServerInfo($client), []);

        return [
            'database' => $name,
            'user' => $user,
            'password' => $password,
            'host' => (string) (is_array($info) ? ($info['host'] ?? 'localhost') : 'localhost'),
            'done' => $done,
        ];
    }

    // ------------------------------------------------- صفحه‌های خطای سایت

    /** کدهای خطایی که صفحه‌ی سفارشی برایشان ساخته می‌شود. */
    public const ERROR_CODES = ['400', '401', '403', '404', '500'];

    /**
     * صفحه‌ی خطای سفارشی: به‌جای صفحه‌ی سفید و انگلیسی سرور، یک صفحه‌ی فارسی
     * با لینک بازگشت به خانه.
     *
     * @param array<int, string> $codes
     *
     * @return array<int, string> فایل‌هایی که ساخته شدند
     */
    public static function setErrorPages(CpanelClient $client, string $docroot, bool $enable, array $codes = ['404', '500']): array
    {
        $docroot = self::normalizePath($docroot);
        $htaccess = $docroot . '/.htaccess';
        $current = (string) self::softCall(fn () => self::readFile($client, $htaccess), '');
        $codes = array_values(array_intersect($codes, self::ERROR_CODES));
        if ($codes === []) {
            $codes = ['404', '500'];
        }

        if (!$enable) {
            self::saveFile($client, $htaccess, self::stripBlock($current, 'ERRORPAGES'));
            $removed = [];
            foreach (self::ERROR_CODES as $code) {
                $path = $docroot . '/' . $code . '.html';
                if (self::softCall(fn () => self::deletePath($client, $path), null) !== null) {
                    $removed[] = $path;
                }
            }

            return $removed;
        }

        $lines = ['# TEAMKAN ERRORPAGES START'];
        $created = [];
        foreach ($codes as $code) {
            $lines[] = 'ErrorDocument ' . $code . ' /' . $code . '.html';
            $path = $docroot . '/' . $code . '.html';
            self::saveFile($client, $path, self::errorPage($code));
            $created[] = $path;
        }
        $lines[] = '# TEAMKAN ERRORPAGES END';
        $lines[] = '';

        self::saveFile($client, $htaccess, implode("\n", $lines) . self::stripBlock($current, 'ERRORPAGES'));

        return $created;
    }

    private static function errorPage(string $code): string
    {
        $title = match ($code) {
            '400' => 'درخواست نامعتبر',
            '401' => 'ورود لازم است',
            '403' => 'دسترسی ندارید',
            '404' => 'صفحه پیدا نشد',
            default => 'خطای سرور',
        };
        $body = match ($code) {
            '404' => 'آدرسی که دنبالش بودید روی این سایت نیست. شاید منتقل شده باشد.',
            '403' => 'اجازه‌ی دیدن این بخش را ندارید.',
            '401' => 'برای دیدن این صفحه باید وارد شوید.',
            '400' => 'درخواست شما درست فرستاده نشد؛ دوباره تلاش کنید.',
            default => 'مشکلی در سرور پیش آمده. کمی بعد دوباره امتحان کنید.',
        };

        return implode("\n", [
            '<!doctype html>',
            '<html lang="fa" dir="rtl">',
            '<meta charset="utf-8">',
            '<meta name="viewport" content="width=device-width, initial-scale=1">',
            '<title>' . $code . ' — ' . $title . '</title>',
            '<style>body{font-family:system-ui,Tahoma,sans-serif;display:flex;min-height:100vh;'
                . 'align-items:center;justify-content:center;margin:0;background:#f6f6f8;color:#222;'
                . 'text-align:center;padding:2rem}div{max-width:32rem;line-height:2}'
                . 'b{font-size:3rem;display:block;color:#888}a{color:#2563eb}</style>',
            '<div><b>' . $code . '</b><h1>' . $title . '</h1><p>' . $body . '</p>'
                . '<p><a href="/">بازگشت به صفحه‌ی اول</a></p></div>',
            '',
        ]);
    }

    // ---------------------------------------------------------- TTL زون

    /**
     * تغییر TTL همه‌ی رکوردهای معمولی یک زون.
     *
     * قبل از جابه‌جایی هاست، TTL را پایین می‌آورند تا تغییر آی‌پی زود همه‌جا
     * اثر کند؛ بعدش دوباره بالا می‌برند تا فشار روی DNS کم شود.
     *
     * @return array{changed: int, skipped: int}
     */
    public static function setZoneTtl(CpanelClient $client, string $zone, int $ttl): array
    {
        if ($ttl < 60 || $ttl > 86400) {
            throw CpanelError::config('TTL باید بین ۶۰ تا ۸۶۴۰۰ ثانیه باشد.');
        }

        $zoneData = self::dnsZone($client, $zone);
        $editable = ['A', 'AAAA', 'CNAME', 'MX', 'TXT'];
        $changed = 0;
        $skipped = 0;

        if ($zoneData['api2']) {
            foreach ($zoneData['records'] as $record) {
                if (!in_array($record['type'], $editable, true)) {
                    $skipped++;
                    continue;
                }
                $params = self::api2RecordParams($zone, $record['name'], $record['type'], $record['data'], $ttl);
                $params['line'] = $record['line'];
                self::softCall(fn () => $client->api2('ZoneEdit', 'edit_zone_record', $params), null);
                $changed++;
            }

            return ['changed' => $changed, 'skipped' => $skipped];
        }

        if ($zoneData['serial'] === null) {
            throw CpanelError::api("سریال SOA برای زون «{$zone}» پیدا نشد؛ چیزی تغییر نکرد.");
        }

        $edits = [];
        foreach ($zoneData['records'] as $record) {
            if (!in_array($record['type'], $editable, true)) {
                $skipped++;
                continue;
            }
            if (($record['ttl'] ?? null) === $ttl) {
                continue;
            }
            $edits[] = json_encode([
                'line_index' => $record['line'],
                'dname' => $record['name'],
                'ttl' => $ttl,
                'record_type' => $record['type'],
                'data' => array_values($record['data']),
            ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $changed++;
        }

        if ($edits !== []) {
            // همه‌ی تغییرها در یک درخواست می‌روند، وگرنه سریال SOA بعد از
            // اولین تغییر عوض می‌شود و بقیه رد می‌شوند.
            $client->uapi('DNS', 'mass_edit_zone', [
                'zone' => $zone,
                'serial' => $zoneData['serial'],
                'edit' => $edits,
            ]);
        }

        return ['changed' => $changed, 'skipped' => $skipped];
    }

    // ------------------------------------------------------- CSR و کلید

    /**
     * ساخت کلید خصوصی و درخواست امضای گواهی (CSR) — برای وقتی که گواهی را از
     * یک صادرکننده‌ی تجاری می‌خرید.
     *
     * این کار روی خود ربات انجام می‌شود، نه روی هاست؛ پس به هیچ تابعی از
     * cPanel وابسته نیست.
     *
     * @param array<string, string> $subject
     *
     * @return array{key: string, csr: string}
     */
    public static function generateCsr(string $domain, array $subject = []): array
    {
        if (!function_exists('openssl_pkey_new')) {
            throw CpanelError::config('افزونه‌ی openssl روی این سرور نصب نیست؛ CSR را از خود cPanel بگیرید.');
        }

        $domain = SiteCheck::cleanDomain($domain);
        if ($domain === '') {
            throw CpanelError::config('نام دامنه معتبر نیست.');
        }

        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        if ($key === false) {
            throw CpanelError::config('ساخت کلید خصوصی انجام نشد.');
        }

        $dn = [
            'commonName' => $domain,
            'countryName' => strtoupper(substr(trim($subject['country'] ?? 'IR'), 0, 2)),
            'organizationName' => trim($subject['organization'] ?? '') ?: $domain,
        ];
        if (trim($subject['email'] ?? '') !== '') {
            $dn['emailAddress'] = trim($subject['email']);
        }

        $csr = openssl_csr_new($dn, $key, ['digest_alg' => 'sha256']);
        if ($csr === false) {
            throw CpanelError::config('ساخت CSR انجام نشد: ' . (string) openssl_error_string());
        }

        openssl_csr_export($csr, $csrOut);
        openssl_pkey_export($key, $keyOut);

        return ['key' => (string) $keyOut, 'csr' => (string) $csrOut];
    }

    // ------------------------------------------------ سطل زباله‌ی فایل‌منیجر

    public static function trashDir(CpanelClient $client): string
    {
        return self::homeDir($client) . '/.trash';
    }

    /**
     * @return array<int, array{name: string, path: string, dir: bool, size: ?float, mtime: ?int, permissions: string}>
     */
    public static function trashItems(CpanelClient $client): array
    {
        return self::softCall(fn () => self::listFiles($client, self::trashDir($client)), []);
    }

    /**
     * برگرداندن یک مورد از سطل زباله به پوشه‌ی دلخواه.
     */
    public static function restoreFromTrash(CpanelClient $client, string $name, string $destination): string
    {
        $source = self::trashDir($client) . '/' . ltrim($name, '/');
        self::movePath($client, $source, self::normalizePath($destination));

        return rtrim(self::normalizePath($destination), '/') . '/' . basename($name);
    }

    /**
     * خالی‌کردن سطل زباله.
     *
     * @return array{deleted: int, freed: float}
     */
    public static function emptyTrash(CpanelClient $client): array
    {
        $deleted = 0;
        $freed = 0.0;

        foreach (self::trashItems($client) as $item) {
            if (self::softCall(function () use ($client, $item): bool {
                self::deletePath($client, $item['path']);

                return true;
            }, false) === true) {
                $deleted++;
                $freed += (float) ($item['size'] ?? 0);
            }
        }

        return ['deleted' => $deleted, 'freed' => $freed];
    }

    // ------------------------------------------------- گرفتن فایل از اینترنت

    /**
     * دانلود یک فایل از اینترنت و گذاشتنش روی هاست.
     *
     * سقف حجم دارد و آدرس‌های داخلی شبکه رد می‌شوند تا نشود از ربات برای
     * دیدن سرویس‌های داخلی سرور استفاده کرد.
     *
     * @return array{name: string, size: int, path: string}
     */
    public static function fetchToHost(CpanelClient $client, string $url, string $dir, string $name = '', int $maxBytes = 47185920): array
    {
        $url = trim($url);
        $parts = parse_url($url);
        $scheme = strtolower((string) ($parts['scheme'] ?? ''));
        $host = (string) ($parts['host'] ?? '');

        if (!in_array($scheme, ['http', 'https'], true) || $host === '') {
            throw CpanelError::config('فقط آدرس‌های <code>http</code> و <code>https</code> پذیرفته می‌شوند.');
        }
        if (self::isInternalHost($host)) {
            throw CpanelError::config('آدرس‌های داخلی شبکه (localhost و آی‌پی‌های خصوصی) پذیرفته نمی‌شوند.');
        }

        [$status, $body, $error] = self::httpGet($url, 120, $maxBytes + 1024);
        if ($status === 0) {
            throw CpanelError::transport($error !== '' ? $error : 'فایل دانلود نشد.');
        }
        if ($status !== 200) {
            throw CpanelError::http($status, 'آدرس داده‌شده فایل نداد.');
        }

        $size = strlen($body);
        if ($size === 0) {
            throw CpanelError::config('فایل خالی بود.');
        }
        if ($size > $maxBytes) {
            throw CpanelError::config(
                'حجم فایل حدود ' . round($size / 1048576, 1) . ' مگابایت است و سقف '
                . round($maxBytes / 1048576) . ' مگابایت است.'
            );
        }

        $name = trim($name) !== '' ? trim($name) : basename((string) ($parts['path'] ?? ''));
        $name = (string) preg_replace('#[/\\\\]#', '', $name);
        if ($name === '' || $name === '.') {
            $name = 'download-' . gmdate('Ymd-His');
        }

        self::uploadFile($client, $dir, $name, $body);

        return ['name' => $name, 'size' => $size, 'path' => rtrim(self::normalizePath($dir), '/') . '/' . $name];
    }

    public static function isInternalHost(string $host): bool
    {
        $host = strtolower(trim($host, "[] \t\n\r"));
        if ($host === '' || $host === 'localhost' || str_ends_with($host, '.localhost') || str_ends_with($host, '.internal')) {
            return true;
        }
        if (filter_var($host, FILTER_VALIDATE_IP) === false) {
            return false;
        }

        return filter_var(
            $host,
            FILTER_VALIDATE_IP,
            FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE,
        ) === false;
    }

    // ---------------------------------------------------------- وب‌دیسک

    /**
     * @return array<int, array{login: string, dir: string, private: bool}>
     */
    public static function webDiskUsers(CpanelClient $client): array
    {
        $raw = $client->uapi('WebDisk', 'list_users');
        $out = [];

        foreach (self::normalizeList($raw) as $row) {
            if (is_string($row)) {
                $out[] = ['login' => $row, 'dir' => '', 'private' => false];
                continue;
            }
            if (!is_array($row)) {
                continue;
            }
            $login = (string) ($row['login'] ?? ($row['user'] ?? ''));
            if ($login === '') {
                continue;
            }
            $out[] = [
                'login' => $login,
                'dir' => (string) ($row['homedir'] ?? ($row['dir'] ?? '')),
                'private' => (string) ($row['private'] ?? '0') === '1',
            ];
        }

        return $out;
    }

    public static function addWebDiskUser(CpanelClient $client, string $user, string $domain, string $password, string $dir): void
    {
        $client->uapi('WebDisk', 'create_user', [
            'user' => $user,
            'domain' => $domain,
            'password' => $password,
            'homedir' => self::normalizePath($dir),
            'perms' => 'rw',
            'enabledigest' => 1,
            'private' => 1,
        ]);
    }

    public static function deleteWebDiskUser(CpanelClient $client, string $login): void
    {
        [$user, $domain] = self::splitEmail($login);
        $client->uapi('WebDisk', 'delete_user', ['user' => $user, 'domain' => $domain, 'destroy' => 0]);
    }

    // ------------------------------------------------------------ کران

    /**
     * تغییر نسخه‌ی PHP همه‌ی دامنه‌ها با هم.
     *
     * @return array{changed: array<int, string>, failed: array<int, string>}
     */
    public static function setPhpVersionForAll(CpanelClient $client, string $version): array
    {
        $changed = [];
        $failed = [];

        foreach (self::phpVersions($client) as $vhost) {
            $name = (string) $vhost['vhost'];
            if ($name === '') {
                continue;
            }
            if ((string) $vhost['version'] === $version) {
                continue;
            }
            try {
                self::setPhpVersion($client, $name, $version);
                $changed[] = $name;
            } catch (Throwable $e) {
                $failed[] = $name . ' — ' . $e->getMessage();
            }
        }

        return ['changed' => $changed, 'failed' => $failed];
    }

    // ----------------------------------------------------------- بکاپ

    public static function deleteBackup(CpanelClient $client, string $name): string
    {
        $name = trim($name);
        if ($name === '' || str_contains($name, '..')) {
            throw CpanelError::config('نام بکاپ معتبر نیست.');
        }

        $path = str_starts_with($name, '/') ? self::normalizePath($name) : self::homeDir($client) . '/' . $name;
        self::deletePath($client, $path);

        return $path;
    }

    /**
     * بکاپ کامل روی یک سرور FTP بیرونی — تا نسخه‌ی پشتیبان روی همان هاستی
     * نماند که ممکن است از دست برود.
     */
    public static function backupToFtp(
        CpanelClient $client,
        string $host,
        string $user,
        string $password,
        string $directory = '',
        string $email = '',
        int $port = 21,
    ): void {
        $params = [
            'host' => trim($host),
            'username' => trim($user),
            'password' => $password,
            'port' => $port,
            'rdir' => trim($directory),
        ];
        $params['email'] = trim($email);
        $params['email_radio'] = trim($email) === '' ? 0 : 1;

        $client->uapi('Backup', 'fullbackup_to_ftp', $params);
    }

    // ------------------------------------------------------- آمار WHM

    /**
     * نمای کلی سرور از روی فهرست حساب‌ها.
     *
     * @return array{accounts: int, suspended: int, disk: float, plans: array<string, int>, newest: array<int, string>}
     */
    public static function whmOverview(CpanelClient $client): array
    {
        $accounts = self::whmAccounts($client);
        $disk = 0.0;
        $suspended = 0;
        $plans = [];

        foreach ($accounts as $account) {
            $disk += self::parseSize((string) ($account['disk_used'] ?? ''));
            if ($account['suspended']) {
                $suspended++;
            }
            $plan = trim((string) ($account['plan'] ?? '')) ?: '—';
            $plans[$plan] = ($plans[$plan] ?? 0) + 1;
        }
        arsort($plans);

        return [
            'accounts' => count($accounts),
            'suspended' => $suspended,
            'disk' => $disk,
            'plans' => $plans,
            'newest' => array_slice(array_column($accounts, 'user'), -5),
        ];
    }

    /**
     * پرکارترین فرستنده‌های ایمیل بر اساس لاگ تحویل — برای وقتی که حجم ارسال
     * ناگهان بالا رفته و می‌خواهیم بدانیم کدام صندوق مسبب است.
     *
     * @return array{rows: array<int, array{sender: string, total: int, failed: int}>, total: int}
     */
    public static function topSenders(CpanelClient $client, int $limit = 200): array
    {
        $rows = [];
        $total = 0;

        foreach (self::mailDelivery($client, '', $limit) as $record) {
            $sender = $record['sender'] !== '' ? $record['sender'] : '(نامشخص)';
            if (!isset($rows[$sender])) {
                $rows[$sender] = ['sender' => $sender, 'total' => 0, 'failed' => 0];
            }
            $rows[$sender]['total']++;
            if ($record['status'] === 'ناموفق') {
                $rows[$sender]['failed']++;
            }
            $total++;
        }

        usort($rows, static fn (array $a, array $b): int => $b['total'] <=> $a['total']);

        return ['rows' => array_values($rows), 'total' => $total];
    }

    /**
     * فوروارد کل دامنه: هر نامه‌ای که به هر آدرسی از دامنه‌ی اول برسد، به همان
     * نام روی دامنه‌ی دوم می‌رود.
     */
    public static function addDomainForwarder(CpanelClient $client, string $domain, string $destination): void
    {
        $domain = SiteCheck::cleanDomain($domain);
        $destination = SiteCheck::cleanDomain($destination);
        if ($domain === '' || $destination === '') {
            throw CpanelError::config('هر دو دامنه را درست بفرستید.');
        }
        if ($domain === $destination) {
            throw CpanelError::config('دامنه‌ی مبدأ و مقصد نمی‌توانند یکی باشند.');
        }

        try {
            $client->uapi('Email', 'add_domain_forwarder', ['domain' => $domain, 'dest' => $destination]);

            return;
        } catch (CpanelError $e) {
            if (in_array($e->kind, [CpanelError::KIND_AUTH, CpanelError::KIND_TRANSPORT], true)) {
                throw $e;
            }
        }

        $client->api2('Email', 'adddforward', ['domain' => $domain, 'newdomain' => $destination]);
    }

    /**
     * دسترسی‌های یک کاربر روی یک دیتابیس.
     *
     * @return array<int, string>
     */
    public static function databasePrivileges(CpanelClient $client, string $user, string $database): array
    {
        $raw = $client->uapi('Mysql', 'get_privileges_on_database', ['user' => $user, 'database' => $database]);

        $out = [];
        foreach (self::normalizeList($raw) as $row) {
            $value = is_array($row) ? (string) ($row['privilege'] ?? '') : (string) $row;
            if (trim($value) !== '') {
                $out[] = trim($value);
            }
        }

        return $out;
    }

    // ------------------------------------------------ بلوک‌های .htaccess سایت

    /**
     * یکسان‌سازی آدرس سایت: همیشه با www یا همیشه بدون www.
     *
     * داشتن هر دو نسخه هم برای گوگل بد است هم گاهی باعث حلقه‌ی ورود می‌شود.
     */
    public static function setCanonical(CpanelClient $client, string $docroot, string $domain, string $mode): void
    {
        $domain = SiteCheck::cleanDomain($domain);
        $domain = (string) preg_replace('/^www\./', '', $domain);
        if ($domain === '') {
            throw CpanelError::config('نام دامنه معتبر نیست.');
        }

        $htaccess = self::normalizePath($docroot) . '/.htaccess';
        $current = (string) self::softCall(fn () => self::readFile($client, $htaccess), '');

        if ($mode === 'off') {
            self::saveFile($client, $htaccess, self::stripBlock($current, 'CANONICAL'));

            return;
        }

        $escaped = str_replace('.', '\.', $domain);
        $lines = ['# TEAMKAN CANONICAL START', 'RewriteEngine On'];
        if ($mode === 'www') {
            $lines[] = 'RewriteCond %{HTTP_HOST} ^' . $escaped . '$ [NC]';
            $lines[] = 'RewriteRule ^(.*)$ https://www.' . $domain . '/$1 [R=301,L]';
        } else {
            $lines[] = 'RewriteCond %{HTTP_HOST} ^www\.' . $escaped . '$ [NC]';
            $lines[] = 'RewriteRule ^(.*)$ https://' . $domain . '/$1 [R=301,L]';
        }
        $lines[] = '# TEAMKAN CANONICAL END';
        $lines[] = '';

        self::saveFile($client, $htaccess, implode("\n", $lines) . self::stripBlock($current, 'CANONICAL'));
    }

    /**
     * فشرده‌سازی gzip و کش مرورگر — دو تنظیمی که بیشترین تأثیر را روی سرعت
     * سایت دارند و هر دو با .htaccess انجام می‌شوند.
     */
    public static function setSpeedRules(CpanelClient $client, string $docroot, bool $enable): void
    {
        $htaccess = self::normalizePath($docroot) . '/.htaccess';
        $current = (string) self::softCall(fn () => self::readFile($client, $htaccess), '');

        if (!$enable) {
            self::saveFile($client, $htaccess, self::stripBlock($current, 'SPEED'));

            return;
        }

        $rules = implode("\n", [
            '# TEAMKAN SPEED START',
            '<IfModule mod_deflate.c>',
            '    AddOutputFilterByType DEFLATE text/html text/plain text/css text/xml',
            '    AddOutputFilterByType DEFLATE application/javascript application/json',
            '    AddOutputFilterByType DEFLATE application/xml image/svg+xml',
            '</IfModule>',
            '<IfModule mod_expires.c>',
            '    ExpiresActive On',
            '    ExpiresByType image/jpeg "access plus 1 year"',
            '    ExpiresByType image/png "access plus 1 year"',
            '    ExpiresByType image/webp "access plus 1 year"',
            '    ExpiresByType image/svg+xml "access plus 1 year"',
            '    ExpiresByType text/css "access plus 1 month"',
            '    ExpiresByType application/javascript "access plus 1 month"',
            '    ExpiresByType font/woff2 "access plus 1 year"',
            '</IfModule>',
            '# TEAMKAN SPEED END',
            '',
        ]);

        self::saveFile($client, $htaccess, $rules . self::stripBlock($current, 'SPEED'));
    }

    /**
     * محدودکردن صفحه‌ی ورود وردپرس به چند آی‌پی — جلوی حمله‌های حدس رمز را
     * می‌گیرد، بدون افزونه.
     *
     * @param array<int, string> $ips
     */
    public static function protectWordpressLogin(CpanelClient $client, string $docroot, bool $enable, array $ips = []): array
    {
        $htaccess = self::normalizePath($docroot) . '/.htaccess';
        $current = (string) self::softCall(fn () => self::readFile($client, $htaccess), '');

        if (!$enable) {
            self::saveFile($client, $htaccess, self::stripBlock($current, 'WPLOGIN'));

            return [];
        }

        $clean = [];
        foreach ($ips as $entry) {
            foreach (preg_split('/[\s,]+/', (string) $entry) ?: [] as $ip) {
                $ip = trim((string) $ip);
                if ($ip !== '' && filter_var($ip, FILTER_VALIDATE_IP) !== false) {
                    $clean[$ip] = true;
                }
            }
        }
        $clean = array_keys($clean);
        if ($clean === []) {
            throw CpanelError::config(
                'حداقل یک آی‌پی معتبر بدهید، وگرنه خودتان هم نمی‌توانید وارد پیشخوان شوید.'
            );
        }

        $lines = [
            '# TEAMKAN WPLOGIN START',
            '<Files wp-login.php>',
            '    <RequireAny>',
        ];
        foreach ($clean as $ip) {
            $lines[] = '        Require ip ' . $ip;
        }
        $lines[] = '    </RequireAny>';
        $lines[] = '</Files>';
        $lines[] = '<Files xmlrpc.php>';
        $lines[] = '    Require all denied';
        $lines[] = '</Files>';
        $lines[] = '# TEAMKAN WPLOGIN END';
        $lines[] = '';

        self::saveFile($client, $htaccess, implode("\n", $lines) . self::stripBlock($current, 'WPLOGIN'));

        return $clean;
    }

    /**
     * HSTS: به مرورگر می‌گوید این سایت را همیشه با https باز کن.
     */
    public static function setHsts(CpanelClient $client, string $docroot, bool $enable): void
    {
        $htaccess = self::normalizePath($docroot) . '/.htaccess';
        $current = (string) self::softCall(fn () => self::readFile($client, $htaccess), '');

        if (!$enable) {
            self::saveFile($client, $htaccess, self::stripBlock($current, 'HSTS'));

            return;
        }

        $rules = implode("\n", [
            '# TEAMKAN HSTS START',
            '<IfModule mod_headers.c>',
            '    Header always set Strict-Transport-Security "max-age=15552000"',
            '</IfModule>',
            '# TEAMKAN HSTS END',
            '',
        ]);

        self::saveFile($client, $htaccess, $rules . self::stripBlock($current, 'HSTS'));
    }

    // ------------------------------------------------------------ DNS

    /**
     * خواندن رکورد از یک فایل زون و افزودنشان.
     *
     * @return array{added: array<int, string>, skipped: array<int, string>, failed: array<int, string>}
     */
    public static function importZone(CpanelClient $client, string $zone, string $text): array
    {
        $existing = [];
        foreach (self::dnsZone($client, $zone)['records'] as $record) {
            $existing[self::recordKey($record['type'], $record['name'], $record['data'])] = true;
        }

        $result = ['added' => [], 'skipped' => [], 'failed' => []];
        $allowed = ['A', 'AAAA', 'CNAME', 'MX', 'TXT', 'CAA', 'SRV'];

        foreach (preg_split('/\R/', $text) ?: [] as $line) {
            $line = trim((string) $line);
            if ($line === '' || str_starts_with($line, ';') || str_starts_with($line, '#') || str_starts_with($line, '$')) {
                continue;
            }

            // name [ttl] [IN] TYPE data…
            $parts = preg_split('/\s+/', $line) ?: [];
            if (count($parts) < 3) {
                continue;
            }
            $name = array_shift($parts);
            $ttl = 14400;
            if (ctype_digit((string) ($parts[0] ?? ''))) {
                $ttl = (int) array_shift($parts);
            }
            if (strtoupper((string) ($parts[0] ?? '')) === 'IN') {
                array_shift($parts);
            }
            $type = strtoupper((string) array_shift($parts));
            if (!in_array($type, $allowed, true) || $parts === []) {
                continue;
            }

            $data = array_values(array_map(
                static fn (string $chunk): string => trim($chunk, '"'),
                $parts,
            ));
            if ($type === 'TXT') {
                $data = [trim(implode(' ', $data), '"')];
            }

            $label = $type . ' ' . rtrim($name, '.') . ' → ' . implode(' ', $data);
            if (isset($existing[self::recordKey($type, $name, $data)])) {
                $result['skipped'][] = $label;
                continue;
            }

            try {
                self::addDnsRecord($client, $zone, $name, $type, $data, $ttl);
                $result['added'][] = $label;
                $existing[self::recordKey($type, $name, $data)] = true;
            } catch (Throwable $e) {
                $result['failed'][] = $label . ' — ' . $e->getMessage();
            }
        }

        return $result;
    }

    /**
     * تغییر آی‌پی همه‌ی دامنه‌ها — همان کاری که بعد از جابه‌جایی هاست باید
     * روی همه‌ی زون‌ها انجام شود.
     *
     * @return array{changed: array<int, string>, failed: array<int, string>}
     */
    public static function repointDomains(CpanelClient $client, string $ip): array
    {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            throw CpanelError::config('آی‌پی معتبر نیست (فقط IPv4).');
        }

        $domains = self::domains($client);
        $zones = array_values(array_filter(array_merge([$domains['main_domain']], $domains['addon_domains'])));

        $changed = [];
        $failed = [];
        foreach ($zones as $zone) {
            foreach ([$zone . '.', 'www.' . $zone . '.'] as $name) {
                try {
                    self::setARecord($client, $zone, $name, $ip);
                    $changed[] = rtrim($name, '.');
                } catch (Throwable $e) {
                    $failed[] = rtrim($name, '.') . ' — ' . $e->getMessage();
                }
            }
        }

        return ['changed' => $changed, 'failed' => $failed];
    }

    /**
     * گواهی واقعیِ سرو‌شده‌ی چند دامنه، از بیرون.
     *
     * @param array<int, string> $domains
     *
     * @return array<int, array{domain: string, ok: bool, days: ?int, issuer: string, covers: bool, error: string}>
     */
    public static function certificateSweep(array $domains, int $limit = 8): array
    {
        $out = [];
        foreach (array_slice($domains, 0, $limit) as $domain) {
            $domain = SiteCheck::cleanDomain((string) $domain);
            if ($domain === '') {
                continue;
            }
            $certificate = SiteCheck::certificateFor($domain);
            if ($certificate === null) {
                $out[] = ['domain' => $domain, 'ok' => false, 'days' => null, 'issuer' => '', 'covers' => false,
                    'error' => 'گواهی خوانده نشد (سایت روی https جواب نداد).'];
                continue;
            }
            $validTo = $certificate['valid_to'] ?? null;
            $days = $validTo === null ? null : (int) floor(((int) $validTo - time()) / 86400);
            $covers = (bool) ($certificate['covers_domain'] ?? false);
            $out[] = [
                'domain' => $domain,
                'ok' => $covers && ($days === null || $days > 0),
                'days' => $days,
                'issuer' => (string) ($certificate['issuer'] ?? ''),
                'covers' => $covers,
                'error' => '',
            ];
        }

        return $out;
    }

    // ------------------------------------------------------------ فایل‌ها

    /**
     * شمارش فایل هر پوشه‌ی سطح‌اول هوم — سقف «اینود» هاست‌های اشتراکی معمولاً
     * قبل از سقف حجم پر می‌شود.
     *
     * @return array{rows: array<int, array{path: string, files: int}>, total: int, complete: bool}
     */
    public static function inodeUsage(CpanelClient $client, int $maxDirs = 40): array
    {
        $home = self::homeDir($client);
        $rows = [];
        $total = 0;
        $scanned = 0;
        $complete = true;

        foreach (self::softCall(fn () => self::listFiles($client, $home), []) as $entry) {
            if (!$entry['dir']) {
                $total++;
                continue;
            }

            $count = 0;
            $queue = [$entry['path']];
            while ($queue !== []) {
                if ($scanned >= $maxDirs) {
                    $complete = false;
                    break;
                }
                $current = array_shift($queue);
                $scanned++;
                foreach (self::softCall(fn () => self::listFiles($client, $current), []) as $child) {
                    if ($child['dir']) {
                        $queue[] = $child['path'];
                        continue;
                    }
                    $count++;
                }
            }

            $rows[] = ['path' => $entry['path'], 'files' => $count];
            $total += $count;
        }

        usort($rows, static fn (array $a, array $b): int => $b['files'] <=> $a['files']);

        return ['rows' => array_slice($rows, 0, 12), 'total' => $total, 'complete' => $complete];
    }

    /** فایل‌هایی که آدم معمولاً می‌خواهد سریع نگاهشان کند. */
    private const IMPORTANT_FILES = [
        '.htaccess', 'wp-config.php', 'robots.txt', '.user.ini', 'php.ini',
        '.env', 'index.php', 'configuration.php', 'config.php', 'sitemap.xml',
    ];

    /**
     * فایل‌های مهمی که واقعاً داخل این پوشه هستند.
     *
     * @return array<int, string>
     */
    public static function importantFiles(CpanelClient $client, string $docroot): array
    {
        $found = [];
        foreach (self::softCall(fn () => self::listFiles($client, $docroot), []) as $entry) {
            if (!$entry['dir'] && in_array(strtolower($entry['name']), self::IMPORTANT_FILES, true)) {
                $found[] = $entry['path'];
            }
        }

        return $found;
    }

    /**
     * جست‌وجو و جایگزینی متن در فایل‌های یک پوشه — همان کاری که بعد از عوض‌شدن
     * دامنه باید در چند فایل انجام شود.
     *
     * @return array{changed: array<int, array{path: string, hits: int}>, scanned: int, complete: bool}
     */
    public static function replaceInFiles(
        CpanelClient $client,
        string $dir,
        string $search,
        string $replace,
        int $maxFiles = 25,
    ): array {
        if (trim($search) === '') {
            throw CpanelError::config('عبارت جست‌وجو خالی است.');
        }

        $changed = [];
        $scanned = 0;
        $complete = true;
        $queue = [[self::normalizePath($dir), 0]];

        while ($queue !== []) {
            [$current, $depth] = array_shift($queue);

            foreach (self::softCall(fn () => self::listFiles($client, $current), []) as $entry) {
                if ($entry['dir']) {
                    if ($depth < 1 && !in_array($entry['name'], ['node_modules', 'vendor', 'cache', '.git', 'mail'], true)) {
                        $queue[] = [$entry['path'], $depth + 1];
                    }
                    continue;
                }
                if (preg_match('/\.(' . self::BINARY_FOR_GREP . ')$/i', $entry['name']) === 1
                    || (float) ($entry['size'] ?? 0) > 524288) {
                    continue;
                }
                if ($scanned >= $maxFiles) {
                    $complete = false;
                    break 2;
                }

                $scanned++;
                $content = (string) self::softCall(fn () => self::readFile($client, $entry['path']), '');
                if ($content === '' || !str_contains($content, $search)) {
                    continue;
                }

                $hits = substr_count($content, $search);
                self::saveFile($client, $entry['path'], str_replace($search, $replace, $content));
                $changed[] = ['path' => $entry['path'], 'hits' => $hits];
            }
        }

        return ['changed' => $changed, 'scanned' => $scanned, 'complete' => $complete];
    }

    /**
     * مقایسه‌ی محتوای دو پوشه (سطح اول): چه چیزی فقط در یکی هست و کدام‌ها
     * حجمشان فرق دارد.
     *
     * @return array{only_left: array<int, string>, only_right: array<int, string>, different: array<int, string>, same: int}
     */
    public static function compareDirectories(CpanelClient $client, string $left, string $right): array
    {
        $index = static function (array $entries): array {
            $out = [];
            foreach ($entries as $entry) {
                $out[$entry['name']] = $entry;
            }

            return $out;
        };

        $a = $index(self::listFiles($client, $left));
        $b = $index(self::listFiles($client, $right));

        $onlyLeft = array_values(array_diff(array_keys($a), array_keys($b)));
        $onlyRight = array_values(array_diff(array_keys($b), array_keys($a)));
        $different = [];
        $same = 0;

        foreach (array_intersect(array_keys($a), array_keys($b)) as $name) {
            $left1 = $a[$name];
            $right1 = $b[$name];
            if ($left1['dir'] !== $right1['dir'] || (float) ($left1['size'] ?? 0) !== (float) ($right1['size'] ?? 0)) {
                $different[] = (string) $name;
                continue;
            }
            $same++;
        }

        return ['only_left' => $onlyLeft, 'only_right' => $onlyRight, 'different' => $different, 'same' => $same];
    }

    // ------------------------------------------------------- کلید و کران

    public static function authorizeSshKey(CpanelClient $client, string $name, bool $authorize): void
    {
        $client->uapi('SSH', $authorize ? 'authorize_key' : 'deauthorize_key', ['name' => $name]);
    }

    /** زمان‌بندی‌های آماده برای ساخت کران بدون نوشتن سینتکس. */
    public const CRON_WHEN = [
        '*/5 * * * *' => 'هر ۵ دقیقه',
        '*/15 * * * *' => 'هر ۱۵ دقیقه',
        '0 * * * *' => 'هر ساعت',
        '0 */6 * * *' => 'هر ۶ ساعت',
        '0 3 * * *' => 'هر شب ساعت ۳',
        '0 3 * * 0' => 'هر هفته، جمعه‌شب ساعت ۳',
        '0 3 1 * *' => 'اول هر ماه ساعت ۳',
    ];

    /**
     * کپی محدودیت‌های PHP یک دامنه روی بقیه‌ی دامنه‌ها.
     *
     * @return array{applied: array<int, string>, failed: array<int, string>, directives: array<string, string>}
     */
    public static function copyPhpLimits(CpanelClient $client, string $source): array
    {
        $limits = self::phpLimits($client, $source);
        $wanted = array_intersect_key($limits, self::PHP_DIRECTIVES);
        if ($wanted === []) {
            throw CpanelError::config("از «{$source}» محدودیتی خوانده نشد.");
        }

        $applied = [];
        $failed = [];
        foreach (self::phpVersions($client) as $vhost) {
            $name = (string) $vhost['vhost'];
            if ($name === '' || $name === $source) {
                continue;
            }
            try {
                foreach ($wanted as $directive => $value) {
                    self::setPhpLimit($client, $name, (string) $directive, (string) $value);
                }
                $applied[] = $name;
            } catch (Throwable $e) {
                $failed[] = $name . ' — ' . $e->getMessage();
            }
        }

        return ['applied' => $applied, 'failed' => $failed, 'directives' => $wanted];
    }

    /**
     * بازکردن یک بکاپ داخل پوشه‌ی دلخواه.
     */
    public static function restoreBackup(CpanelClient $client, string $name, string $destination): string
    {
        $path = str_starts_with($name, '/') ? self::normalizePath($name) : self::homeDir($client) . '/' . $name;
        $destination = self::normalizePath($destination);
        self::extractArchive($client, $path, $destination);

        return $destination;
    }

    // -------------------------------------------------------- کارهای WHM

    public static function whmCreatePackage(CpanelClient $client, string $name, mixed $quotaMb, mixed $bandwidthMb): void
    {
        $client->whm('addpkg', [
            'name' => $name,
            'quota' => is_numeric($quotaMb) ? (int) $quotaMb : 0,
            'bwlimit' => is_numeric($bandwidthMb) ? (int) $bandwidthMb : 0,
            'maxsub' => 'unlimited',
            'maxpop' => 'unlimited',
            'maxsql' => 'unlimited',
        ]);
    }

    public static function whmSetSiteIp(CpanelClient $client, string $user, string $ip): void
    {
        if (filter_var($ip, FILTER_VALIDATE_IP) === false) {
            throw CpanelError::config('آی‌پی معتبر نیست.');
        }

        $client->whm('setsiteip', ['user' => $user, 'ip' => $ip]);
    }

    /**
     * @return array<string, string>
     */
    public static function whmVersion(CpanelClient $client): array
    {
        $raw = $client->whm('version');
        $out = [];
        if (is_array($raw)) {
            foreach ($raw as $key => $value) {
                if (is_scalar($value)) {
                    $out[(string) $key] = (string) $value;
                }
            }
        } elseif (is_scalar($raw)) {
            $out['version'] = (string) $raw;
        }

        return $out;
    }

    /**
     * فراخوانی‌های «خوب‌است‌اگر‌باشد» — اگه ماژولش روی سرور نصب نباشه یا توکن
     * دسترسی نداشته باشه، کل خروجی نباید بترکه.
     *
     * @template T
     *
     * @param callable(): T $call
     * @param T             $fallback
     *
     * @return T
     */
    private static function softCall(callable $call, mixed $fallback): mixed
    {
        try {
            return $call();
        } catch (CpanelError $e) {
            if (in_array($e->kind, [CpanelError::KIND_AUTH, CpanelError::KIND_TRANSPORT], true)) {
                throw $e;
            }

            return $fallback;
        }
    }
}
