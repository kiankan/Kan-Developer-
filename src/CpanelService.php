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

    public static function addSubdomain(CpanelClient $client, string $subdomain, string $rootDomain, string $dir = ''): void
    {
        $params = ['domain' => $subdomain, 'rootdomain' => $rootDomain];
        if (trim($dir) !== '') {
            $params['dir'] = $dir;
        }
        // SubDomain هم روی بعضی نسخه‌ها فقط API2 دارد.
        self::uapiOrApi2($client, ['SubDomain', 'addsubdomain', $params], ['SubDomain', 'addsubdomain', $params]);
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

    public static function setPhpVersion(CpanelClient $client, string $vhost, string $version): void
    {
        $client->uapi('LangPHP', 'php_set_vhost_lang_version', ['vhost' => $vhost, 'version' => $version]);
    }

    // --------------------------------------------------------------- بکاپ

    public static function fullBackup(CpanelClient $client, string $notifyEmail = ''): void
    {
        $params = [];
        if (trim($notifyEmail) !== '') {
            $params['email'] = $notifyEmail;
        }
        $client->uapi('Backup', 'fullbackup_to_homedir', $params);
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
