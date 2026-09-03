#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

const USAGE = <<<TXT
    KanPanel — مدیریت cPanel/WHM از خط فرمان (همان API‌ای که ربات تلگرام استفاده می‌کند).

    سرورها:
      php cli.php servers
      php cli.php use <server-id>
      php cli.php server:add --label=<name> --type=cpanel|whm --host=<host> [--port=<n>] --user=<user> --token=<token> [--no-verify-ssl]
      php cli.php server:del <server-id>
      php cli.php ping

    حساب:
      php cli.php summary
      php cli.php domains
      php cli.php sub:add <subdomain> <rootdomain> [dir]
      php cli.php sub:del <full-subdomain>

    ایمیل:
      php cli.php email:list
      php cli.php email:add <email> <password> [quota-mb]
      php cli.php email:passwd <email> <password>
      php cli.php email:quota <email> <quota-mb>
      php cli.php email:del <email>

    دیتابیس:
      php cli.php db:list
      php cli.php db:add <name>
      php cli.php db:del <name>
      php cli.php dbuser:add <name> <password>
      php cli.php dbuser:del <name>
      php cli.php db:grant <user> <database> [privileges]

    FTP / کران / DNS / SSL / PHP / بکاپ:
      php cli.php ftp:list | ftp:add <user> <password> [dir] [quota-mb] | ftp:del <user>
      php cli.php cron:list | cron:add "<m h dom mon dow>" "<command>" | cron:del <linekey>
      php cli.php dns:list <zone> | dns:add <zone> <name> <type> <value> [ttl] | dns:del <zone> <line>
      php cli.php ssl:list
      php cli.php php:list | php:set <vhost> <version>
      php cli.php backup:full [notify-email]

    WHM (سرورهای نوع whm):
      php cli.php whm:accounts [search]
      php cli.php whm:summary <user>
      php cli.php whm:suspend <user> [reason]      | php cli.php whm:unsuspend <user>
      php cli.php whm:create <user> <domain> <password> [plan]
      php cli.php whm:terminate <user> --yes
      php cli.php whm:passwd <user> <password>     | php cli.php whm:login <user>
      php cli.php whm:status | php cli.php whm:packages

    تماس مستقیم با API (هر تابعی، حتی چیزهایی که منو ندارند):
      php cli.php uapi <Module> <function> [key=value ...]
      php cli.php whmapi <function> [key=value ...]
      php cli.php api2 <Module> <function> [key=value ...]

    هاست اشتراکی (اجرای ربات با کران، بدون پروسه‌ی دائمی):
      php cli.php selfhost:cron [php-binary] [project-path]

    ربات و ادمین‌ها:
      php cli.php code                    نمایش کد نصب (برای تحویل‌گرفتن ربات)
      php cli.php admins                  فهرست ادمین‌ها

    متفرقه:
      php cli.php log [count]
      php cli.php webhook:set <url> | php cli.php webhook:delete

    سوییچ‌های سراسری:
      --server=<id>   اجرای دستور روی سرور دیگر (به‌جای سرور فعال)
      --user=<user>   کاربر مقصد برای دستورهای سطح حساب روی یک سرور WHM
      --json          خروجی JSON خام به‌جای متن فارسی

    TXT;

/** @var array<string, string> $options */
$options = [];
/** @var array<int, string> $positional */
$positional = [];
foreach (array_slice($argv, 1) as $argument) {
    if (str_starts_with($argument, '--')) {
        $pair = explode('=', substr($argument, 2), 2);
        $options[$pair[0]] = $pair[1] ?? '1';
        continue;
    }
    $positional[] = $argument;
}

$command = array_shift($positional) ?? '';
$json = isset($options['json']);

function fail(string $message): never
{
    fwrite(STDERR, "خطا: $message\n");
    exit(1);
}

function usageFail(): never
{
    fwrite(STDERR, USAGE . "\n");
    exit(1);
}

/**
 * @param array<int, string> $positional
 */
function arg(array $positional, int $index, ?string $default = null): string
{
    $value = $positional[$index] ?? $default;
    if ($value === null) {
        usageFail();
    }

    return $value;
}

function show(string $html): void
{
    echo Render::toPlain($html), "\n";
}

function showJson(mixed $data): void
{
    echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), "\n";
}

/**
 * @param array<string, string> $options
 */
function client(array $options): CpanelClient
{
    $client = ServerStore::client($options['server'] ?? null);

    return isset($options['user']) ? $client->withUser($options['user']) : $client;
}

/**
 * @param array<string, string> $options
 */
function accountClient(array $options): CpanelClient
{
    $client = client($options);
    if ($client->isWhm() && $client->targetUser() === '') {
        fail('سرور فعال از نوع WHM است؛ کاربر مقصد را با --user=<username> مشخص کنید.');
    }

    return $client;
}

/**
 * @param array<string, string> $options
 */
function whmClient(array $options): CpanelClient
{
    $client = client($options);
    if (!$client->isWhm()) {
        fail('این دستور فقط روی سروری از نوع whm کار می‌کند.');
    }

    return $client;
}

/**
 * key=value های خط فرمان را به پارامترهای API تبدیل می‌کند؛ کلید تکراری
 * (مثل add=... در mass_edit_zone) به‌صورت آرایه نگه داشته می‌شود.
 *
 * @param array<int, string> $pairs
 *
 * @return array<string, mixed>
 */
function kvParams(array $pairs): array
{
    $params = [];
    foreach ($pairs as $pair) {
        if (!str_contains($pair, '=')) {
            fail("پارامتر باید به‌شکل key=value باشد: $pair");
        }
        [$key, $value] = explode('=', $pair, 2);
        if (isset($params[$key])) {
            $params[$key] = array_merge((array) $params[$key], [$value]);
            continue;
        }
        $params[$key] = $value;
    }

    return $params;
}

/**
 * @param array<string, mixed> $context
 */
function audit(string $action, array $context): void
{
    AuditLog::record('cli', (string) (ServerStore::activeId() ?? '-'), $action, $context);
}

try {
    switch ($command) {
        // ------------------------------------------------------------ سرورها
        case 'servers':
            $servers = ServerStore::all();
            if ($json) {
                foreach ($servers as $id => $server) {
                    $servers[$id]['token'] = Format::maskSecret((string) $server['token']);
                }
                showJson($servers);
                break;
            }
            show(Render::servers($servers, ServerStore::activeId()));
            break;

        case 'use':
            ServerStore::setActive(arg($positional, 0));
            show('✅ سرور فعال شد: ' . (string) (ServerStore::active()['label'] ?? ''));
            break;

        case 'server:add':
            foreach (['host', 'user', 'token'] as $required) {
                if (!isset($options[$required])) {
                    fail("--$required الزامی است.");
                }
            }
            $type = ($options['type'] ?? 'cpanel') === 'whm' ? CpanelClient::TYPE_WHM : CpanelClient::TYPE_CPANEL;
            $server = [
                'label' => $options['label'] ?? $options['host'],
                'type' => $type,
                'host' => ServerStore::normalizeHost($options['host']),
                'port' => (int) ($options['port'] ?? 0) ?: ($type === CpanelClient::TYPE_WHM ? 2087 : 2083),
                'user' => $options['user'],
                'token' => $options['token'],
                'ssl_verify' => !isset($options['no-verify-ssl']),
            ];
            show(Render::ping(ServerStore::client(null, $server)->ping()));
            $id = ServerStore::add($server);
            ServerStore::setActive($id);
            audit('server:add', ['id' => $id, 'host' => $server['host'], 'type' => $type]);
            show("✅ سرور ذخیره و فعال شد. شناسه: $id");
            break;

        case 'server:del':
            $id = arg($positional, 0);
            $server = ServerStore::get($id);
            if ($server === null) {
                fail("سروری با شناسه‌ی «{$id}» پیدا نشد.");
            }
            if (($server['source'] ?? '') === 'env') {
                fail('این سرور در .env تعریف شده؛ برای حذفش مقادیر CPANEL_*/WHM_* را از .env بردارید.');
            }
            ServerStore::delete($id);
            audit('server:del', ['id' => $id]);
            show("🗑 سرور $id حذف شد.");
            break;

        case 'ping':
            $info = client($options)->ping();
            $json ? showJson($info) : show(Render::ping($info));
            break;

        // ------------------------------------------------------------- حساب
        case 'summary':
            $data = CpanelService::summary(accountClient($options));
            $json ? showJson($data) : show(Render::summary($data));
            break;

        case 'domains':
            $data = CpanelService::domains(accountClient($options));
            $json ? showJson($data) : show(Render::domains($data));
            break;

        case 'sub:add':
            CpanelService::addSubdomain(
                accountClient($options),
                arg($positional, 0),
                arg($positional, 1),
                $positional[2] ?? '',
            );
            audit('sub:add', ['subdomain' => $positional[0], 'rootdomain' => $positional[1]]);
            show('✅ ساب‌دامنه ساخته شد.');
            break;

        case 'sub:del':
            CpanelService::deleteSubdomain(accountClient($options), arg($positional, 0));
            audit('sub:del', ['domain' => $positional[0]]);
            show('🗑 ساب‌دامنه حذف شد.');
            break;

        // ------------------------------------------------------------ ایمیل
        case 'email:list':
            $data = CpanelService::emails(accountClient($options));
            $json ? showJson($data) : show(Render::emails($data));
            break;

        case 'email:add':
            CpanelService::addEmail(accountClient($options), arg($positional, 0), arg($positional, 1), $positional[2] ?? 0);
            audit('email:add', ['email' => $positional[0]]);
            show('✅ حساب ایمیل ساخته شد.');
            break;

        case 'email:passwd':
            CpanelService::setEmailPassword(accountClient($options), arg($positional, 0), arg($positional, 1));
            audit('email:passwd', ['email' => $positional[0]]);
            show('✅ رمز ایمیل عوض شد.');
            break;

        case 'email:quota':
            CpanelService::setEmailQuota(accountClient($options), arg($positional, 0), arg($positional, 1));
            audit('email:quota', ['email' => $positional[0], 'quota' => $positional[1]]);
            show('✅ سهمیه‌ی ایمیل به‌روزرسانی شد.');
            break;

        case 'email:del':
            CpanelService::deleteEmail(accountClient($options), arg($positional, 0));
            audit('email:del', ['email' => $positional[0]]);
            show('🗑 حساب ایمیل حذف شد.');
            break;

        // ---------------------------------------------------------- دیتابیس
        case 'db:list':
            $client = accountClient($options);
            $databases = CpanelService::databases($client);
            $users = CpanelService::databaseUsers($client);
            $json ? showJson(['databases' => $databases, 'users' => $users]) : show(Render::databases($databases, $users));
            break;

        case 'db:add':
            CpanelService::createDatabase(accountClient($options), arg($positional, 0));
            audit('db:add', ['name' => $positional[0]]);
            show('✅ دیتابیس ساخته شد.');
            break;

        case 'db:del':
            CpanelService::deleteDatabase(accountClient($options), arg($positional, 0));
            audit('db:del', ['name' => $positional[0]]);
            show('🗑 دیتابیس حذف شد.');
            break;

        case 'dbuser:add':
            CpanelService::createDatabaseUser(accountClient($options), arg($positional, 0), arg($positional, 1));
            audit('dbuser:add', ['name' => $positional[0]]);
            show('✅ کاربر دیتابیس ساخته شد.');
            break;

        case 'dbuser:del':
            CpanelService::deleteDatabaseUser(accountClient($options), arg($positional, 0));
            audit('dbuser:del', ['name' => $positional[0]]);
            show('🗑 کاربر دیتابیس حذف شد.');
            break;

        case 'db:grant':
            CpanelService::grantDatabaseUser(
                accountClient($options),
                arg($positional, 0),
                arg($positional, 1),
                $positional[2] ?? 'ALL PRIVILEGES',
            );
            audit('db:grant', ['user' => $positional[0], 'database' => $positional[1]]);
            show('✅ دسترسی تنظیم شد.');
            break;

        // --------------------------------------------------------------- FTP
        case 'ftp:list':
            $data = CpanelService::ftpAccounts(accountClient($options));
            $json ? showJson($data) : show(Render::ftp($data));
            break;

        case 'ftp:add':
            CpanelService::addFtp(
                accountClient($options),
                arg($positional, 0),
                arg($positional, 1),
                $positional[2] ?? '',
                $positional[3] ?? 0,
            );
            audit('ftp:add', ['user' => $positional[0]]);
            show('✅ حساب FTP ساخته شد.');
            break;

        case 'ftp:del':
            CpanelService::deleteFtp(accountClient($options), arg($positional, 0));
            audit('ftp:del', ['user' => $positional[0]]);
            show('🗑 حساب FTP حذف شد.');
            break;

        // -------------------------------------------------------------- کران
        case 'cron:list':
            $data = CpanelService::cronJobs(accountClient($options));
            $json ? showJson($data) : show(Render::cron($data));
            break;

        case 'cron:add':
            $schedule = CpanelService::parseSchedule(arg($positional, 0));
            if ($schedule === null) {
                fail('زمان‌بندی باید ۵ فیلد کران باشد، مثل "*/5 * * * *".');
            }
            CpanelService::addCron(accountClient($options), $schedule, arg($positional, 1));
            audit('cron:add', ['schedule' => $positional[0], 'command' => $positional[1]]);
            show('✅ کران‌جاب اضافه شد.');
            break;

        case 'cron:del':
            CpanelService::deleteCron(accountClient($options), arg($positional, 0));
            audit('cron:del', ['key' => $positional[0]]);
            show('🗑 کران‌جاب حذف شد.');
            break;

        // --------------------------------------------------------------- DNS
        case 'dns:list':
            $zone = arg($positional, 0);
            $data = CpanelService::dnsZone(accountClient($options), $zone);
            $json ? showJson($data) : show(Render::dns($zone, $data));
            break;

        case 'dns:add':
            CpanelService::addDnsRecord(
                accountClient($options),
                arg($positional, 0),
                arg($positional, 1),
                arg($positional, 2),
                preg_split('/\s+/', trim(arg($positional, 3))) ?: [],
                (int) ($positional[4] ?? 14400),
            );
            audit('dns:add', ['zone' => $positional[0], 'name' => $positional[1], 'type' => $positional[2]]);
            show('✅ رکورد اضافه شد.');
            break;

        case 'dns:del':
            CpanelService::deleteDnsRecord(accountClient($options), arg($positional, 0), (int) arg($positional, 1));
            audit('dns:del', ['zone' => $positional[0], 'line' => $positional[1]]);
            show('🗑 رکورد حذف شد.');
            break;

        // ------------------------------------------------------- SSL/PHP/بکاپ
        case 'ssl:list':
            $data = CpanelService::sslHosts(accountClient($options));
            $json ? showJson($data) : show(Render::ssl($data));
            break;

        case 'php:list':
            $client = accountClient($options);
            $vhosts = CpanelService::phpVersions($client);
            $installed = CpanelService::installedPhpVersions($client);
            $json ? showJson(['vhosts' => $vhosts, 'installed' => $installed]) : show(Render::php($vhosts, $installed));
            break;

        case 'php:set':
            CpanelService::setPhpVersion(accountClient($options), arg($positional, 0), arg($positional, 1));
            audit('php:set', ['vhost' => $positional[0], 'version' => $positional[1]]);
            show('✅ نسخه‌ی PHP تغییر کرد.');
            break;

        case 'backup:full':
            CpanelService::fullBackup(accountClient($options), $positional[0] ?? '');
            audit('backup:full', ['email' => $positional[0] ?? '']);
            show('✅ ساخت بکاپ کامل شروع شد.');
            break;

        // --------------------------------------------------------------- WHM
        case 'whm:accounts':
            $data = CpanelService::whmAccounts(whmClient($options), $positional[0] ?? '');
            $json ? showJson($data) : show(Render::whmAccounts($data));
            break;

        case 'whm:summary':
            $data = CpanelService::whmAccountSummary(whmClient($options), arg($positional, 0));
            $json ? showJson($data) : show(Render::whmAccount($data));
            break;

        case 'whm:suspend':
            CpanelService::whmSuspend(whmClient($options), arg($positional, 0), $positional[1] ?? '');
            audit('whm:suspend', ['user' => $positional[0]]);
            show('⛔️ حساب معلق شد.');
            break;

        case 'whm:unsuspend':
            CpanelService::whmUnsuspend(whmClient($options), arg($positional, 0));
            audit('whm:unsuspend', ['user' => $positional[0]]);
            show('✅ تعلیق برداشته شد.');
            break;

        case 'whm:create':
            CpanelService::whmCreateAccount(
                whmClient($options),
                arg($positional, 0),
                arg($positional, 1),
                arg($positional, 2),
                $positional[3] ?? '',
            );
            audit('whm:create', ['user' => $positional[0], 'domain' => $positional[1]]);
            show('✅ حساب ساخته شد.');
            break;

        case 'whm:terminate':
            $user = arg($positional, 0);
            if (!isset($options['yes'])) {
                fail("این دستور حساب «{$user}» را برای همیشه حذف می‌کند؛ اگر مطمئنید --yes را اضافه کنید.");
            }
            CpanelService::whmTerminateAccount(whmClient($options), $user);
            audit('whm:terminate', ['user' => $user]);
            show('💀 حساب حذف شد.');
            break;

        case 'whm:passwd':
            CpanelService::whmChangePassword(whmClient($options), arg($positional, 0), arg($positional, 1));
            audit('whm:passwd', ['user' => $positional[0]]);
            show('✅ رمز حساب عوض شد.');
            break;

        case 'whm:login':
            $url = CpanelService::whmLoginUrl(whmClient($options), arg($positional, 0));
            audit('whm:login', ['user' => $positional[0]]);
            echo $url, "\n";
            break;

        case 'whm:status':
            $data = CpanelService::whmServerStatus(whmClient($options));
            $json ? showJson($data) : show(Render::whmStatus($data));
            break;

        case 'whm:packages':
            $data = CpanelService::whmPackages(whmClient($options));
            $json ? showJson($data) : show('📦 پلن‌ها: ' . implode('، ', $data));
            break;

        // ------------------------------------------------- تماس مستقیم با API
        case 'uapi':
            showJson(accountClient($options)->uapi(
                arg($positional, 0),
                arg($positional, 1),
                kvParams(array_slice($positional, 2)),
            ));
            break;

        case 'whmapi':
            showJson(whmClient($options)->whm(arg($positional, 0), kvParams(array_slice($positional, 1))));
            break;

        case 'api2':
            showJson(accountClient($options)->api2(
                arg($positional, 0),
                arg($positional, 1),
                kvParams(array_slice($positional, 2)),
            ));
            break;

        // ------------------------------------------------- هاست اشتراکی
        case 'selfhost:cron':
            $phpBin = $positional[0] ?? '/usr/local/bin/php';
            $projectPath = rtrim($positional[1] ?? __DIR__, '/');
            $cronCommand = "{$phpBin} {$projectPath}/poll.php >/dev/null 2>&1";
            CpanelService::addCron(
                accountClient($options),
                ['minute' => '*', 'hour' => '*', 'day' => '*', 'month' => '*', 'weekday' => '*'],
                $cronCommand,
            );
            audit('selfhost:cron', ['command' => $cronCommand]);
            show("✅ کران هر دقیقه‌ای ساخته شد:\n{$cronCommand}");
            break;

        // ------------------------------------------------- ربات و ادمین‌ها
        case 'code':
        case 'claim':
            if (Admins::isClaimed()) {
                echo "ربات قبلاً تحویل گرفته شده؛ ادمین‌های فعلی: " . implode('، ', Admins::all()) . "\n";
                echo "برای اضافه‌کردن ادمین جدید، از داخل خود ربات: 👮 ادمین‌ها → ➕ افزودن ادمین\n";
                break;
            }
            echo "🤖 ربات را در تلگرام باز کنید و این کد را برایش بفرستید تا مالکش شوید:\n\n";
            echo '    ' . Admins::claimCode() . "\n";
            break;

        case 'admins':
            $env = Admins::fromEnv();
            $file = Admins::fromFile();
            if ($json) {
                showJson(['env' => $env, 'bot' => $file]);
                break;
            }
            echo $env === [] && $file === []
                ? "هنوز ادمینی تعریف نشده (ربات تحویل گرفته نشده).\n"
                : 'ادمین‌ها: ' . implode('، ', array_merge($env, $file)) . "\n";
            break;

        // ---------------------------------------------------------- متفرقه
        case 'log':
            $lines = AuditLog::tail((int) ($positional[0] ?? 20));
            echo $lines === [] ? "هنوز عملیاتی ثبت نشده.\n" : implode("\n", $lines) . "\n";
            break;

        case 'webhook:set':
            $secret = trim((string) (getenv('WEBHOOK_SECRET') ?: ''));
            (new TelegramApi(kanpanel_bot_token()))->setWebhook(arg($positional, 0), $secret);
            echo "وبهوک تنظیم شد: {$positional[0]}\n";
            echo $secret === ''
                ? "⚠️ WEBHOOK_SECRET در .env خالی است؛ توصیه می‌شود یک رشته‌ی تصادفی بگذارید تا فقط تلگرام بتواند به این آدرس درخواست بدهد.\n"
                : "🔐 با هدر امنیتی تنظیم شد.\n";
            break;

        case 'webhook:delete':
            (new TelegramApi(kanpanel_bot_token()))->deleteWebhook();
            echo "وبهوک حذف شد.\n";
            break;

        case '':
        case 'help':
        case '-h':
        case '--help':
            echo USAGE, "\n";
            break;

        default:
            fwrite(STDERR, "دستور ناشناخته: $command\n\n");
            usageFail();
    }
} catch (CpanelError $e) {
    fwrite(STDERR, Render::toPlain(Render::error($e)) . "\n");
    exit(1);
}
