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
