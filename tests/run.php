#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * تست‌های واحد، بدون هیچ وابستگی خارجی و بدون تماس با شبکه:
 *
 *   php tests/run.php
 *
 * لایه‌ی HTTP با یک transport ساختگی جایگزین می‌شه، پس همه‌چیز آفلاین و
 * قابل‌اجرا روی هر ماشینیه.
 */

require_once __DIR__ . '/../bootstrap.php';

$dataDir = sys_get_temp_dir() . '/teamkan_tests_' . getmypid();
@mkdir($dataDir, 0700, true);
putenv("TEAMKAN_DATA_DIR=$dataDir");

$passed = 0;
$failed = 0;

function check(string $name, callable $test): void
{
    global $passed, $failed;
    try {
        $test();
        $passed++;
        echo "  ✓ $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $name\n     " . $e->getMessage() . "\n";
    }
}

function assertSame(mixed $expected, mixed $actual, string $message = ''): void
{
    if ($expected !== $actual) {
        throw new RuntimeException(
            ($message !== '' ? "$message — " : '')
            . 'انتظار: ' . var_export($expected, true) . ' | دریافت: ' . var_export($actual, true)
        );
    }
}

function assertTrue(bool $value, string $message = 'شرط برقرار نبود'): void
{
    if (!$value) {
        throw new RuntimeException($message);
    }
}

function assertThrows(callable $call, ?string $kind = null): CpanelError
{
    try {
        $call();
    } catch (CpanelError $e) {
        if ($kind !== null && $e->kind !== $kind) {
            throw new RuntimeException("نوع خطا $kind انتظار می‌رفت، ولی {$e->kind} آمد: {$e->getMessage()}");
        }

        return $e;
    }

    throw new RuntimeException('انتظار خطا داشتیم ولی خطایی رخ نداد.');
}

/**
 * transport ساختگی: پاسخ ثابت می‌ده و آخرین درخواست رو برای بازرسی نگه می‌داره.
 *
 * @param array<int, array{status?: int, body: string, error?: string}>|array{status?: int, body: string, error?: string} $responses
 */
function fakeTransport(array $responses, array &$captured): callable
{
    $queue = array_is_list($responses) ? $responses : [$responses];
    $index = 0;

    return static function (string $url, string $body, array $headers, bool $verify) use ($queue, &$index, &$captured): array {
        $response = $queue[min($index, count($queue) - 1)];
        $index++;
        $captured[] = ['url' => $url, 'body' => $body, 'headers' => $headers, 'verify' => $verify];

        return [
            'status' => $response['status'] ?? 200,
            'body' => $response['body'],
            'error' => $response['error'] ?? '',
        ];
    };
}

/**
 * @param array<string, mixed> $overrides
 *
 * @return array<string, mixed>
 */
function cpanelServer(array $overrides = []): array
{
    return $overrides + [
        'id' => 'test',
        'label' => 'Test',
        'type' => CpanelClient::TYPE_CPANEL,
        'host' => 'host.example.com',
        'port' => 2083,
        'user' => 'demo',
        'token' => 'TOKEN123',
        'ssl_verify' => true,
    ];
}

echo "CpanelClient\n";

check('ساخت کوئری با کلیدهای تکراری و مقدار بولی', function (): void {
    assertSame(
        'add=a&add=b&flag=1&empty=',
        CpanelClient::buildQuery(['add' => ['a', 'b'], 'flag' => true, 'empty' => null]),
    );
});

check('UAPI: آدرس، هدر احراز هویت و بدنه درست ساخته می‌شود', function (): void {
    $captured = [];
    $client = new CpanelClient(cpanelServer(), fakeTransport(['body' => '{"status":1,"data":[{"email":"a@b.c"}]}'], $captured));
    $data = $client->uapi('Email', 'list_pops', ['domain' => 'b.c']);

    assertSame('https://host.example.com:2083/execute/Email/list_pops', $captured[0]['url']);
    assertSame('domain=b.c', $captured[0]['body']);
    assertTrue(in_array('Authorization: cpanel demo:TOKEN123', $captured[0]['headers'], true), 'هدر توکن cPanel نبود');
    assertTrue($captured[0]['verify'], 'بررسی SSL باید روشن باشد');
    assertSame('a@b.c', $data[0]['email']);
});

check('UAPI: خطای منطقی cPanel به CpanelError تبدیل می‌شود', function (): void {
    $captured = [];
    $client = new CpanelClient(cpanelServer(), fakeTransport(['body' => '{"status":0,"errors":["Domain not found"]}'], $captured));
    $error = assertThrows(static fn () => $client->uapi('Email', 'add_pop'), CpanelError::KIND_API);
    assertTrue(str_contains($error->getMessage(), 'Domain not found'), 'متن خطای سرور باید منتقل شود');
});

check('UAPI: پاسخ ۴۰۳ یعنی خطای احراز هویت', function (): void {
    $captured = [];
    $client = new CpanelClient(cpanelServer(), fakeTransport(['status' => 403, 'body' => 'Forbidden'], $captured));
    assertThrows(static fn () => $client->uapi('Email', 'list_pops'), CpanelError::KIND_AUTH);
});

check('UAPI: پاسخ غیر JSON خطای HTTP می‌دهد', function (): void {
    $captured = [];
    $client = new CpanelClient(cpanelServer(), fakeTransport(['status' => 200, 'body' => '<html>login</html>'], $captured));
    assertThrows(static fn () => $client->uapi('Email', 'list_pops'), CpanelError::KIND_HTTP);
});

check('قطع ارتباط شبکه خطای transport می‌دهد', function (): void {
    $captured = [];
    $client = new CpanelClient(cpanelServer(), fakeTransport(['body' => '', 'error' => 'connection timed out'], $captured));
    assertThrows(static fn () => $client->uapi('Email', 'list_pops'), CpanelError::KIND_TRANSPORT);
});

check('سرور ناقص (بدون توکن) خطای پیکربندی می‌دهد', function (): void {
    assertThrows(static fn () => new CpanelClient(cpanelServer(['token' => ''])), CpanelError::KIND_CONFIG);
});

check('WHM: api.version و هدر whm ست می‌شود و data برمی‌گردد', function (): void {
    $captured = [];
    $client = new CpanelClient(
        cpanelServer(['type' => CpanelClient::TYPE_WHM, 'port' => 2087, 'user' => 'root']),
        fakeTransport(['body' => '{"data":{"acct":[{"user":"ali"}]},"metadata":{"result":1,"reason":"OK"}}'], $captured),
    );
    $data = $client->whm('listaccts');

    assertSame('https://host.example.com:2087/json-api/listaccts', $captured[0]['url']);
    assertSame('api.version=1', $captured[0]['body']);
    assertTrue(in_array('Authorization: whm root:TOKEN123', $captured[0]['headers'], true), 'هدر توکن WHM نبود');
    assertSame('ali', $data['acct'][0]['user']);
});

check('WHM: metadata.result=0 خطا می‌دهد', function (): void {
    $captured = [];
    $client = new CpanelClient(
        cpanelServer(['type' => CpanelClient::TYPE_WHM]),
        fakeTransport(['body' => '{"metadata":{"result":0,"reason":"Access denied"}}'], $captured),
    );
    $error = assertThrows(static fn () => $client->whm('suspendacct'), CpanelError::KIND_API);
    assertTrue(str_contains($error->getMessage(), 'Access denied'));
});

check('WHM روی سرور cPanel اجازه ندارد', function (): void {
    $captured = [];
    $client = new CpanelClient(cpanelServer(), fakeTransport(['body' => '{}'], $captured));
    assertThrows(static fn () => $client->whm('listaccts'), CpanelError::KIND_CONFIG);
    assertSame([], $captured, 'نباید درخواستی به سرور فرستاده شود');
});

check('UAPI روی سرور WHM بدون کاربر مقصد رد می‌شود', function (): void {
    $captured = [];
    $client = new CpanelClient(cpanelServer(['type' => CpanelClient::TYPE_WHM]), fakeTransport(['body' => '{}'], $captured));
    assertThrows(static fn () => $client->uapi('Email', 'list_pops'), CpanelError::KIND_CONFIG);
    assertSame([], $captured, 'نباید درخواستی به سرور فرستاده شود');
});

// نویسه‌های فارسی مثل « و » از نظر PHP بخشی از نام متغیر حساب می‌شن، پس
// "$var»" به یک متغیر تعریف‌نشده تبدیل می‌شه و مقدار از پیام خطا می‌افته.
// این تست جلوی برگشتن آن اشتباه را می‌گیرد.
check('نام‌ها داخل پیام‌های خطای فارسی درست جای‌گذاری می‌شوند', function (): void {
    $captured = [];
    $client = new CpanelClient(cpanelServer(), fakeTransport(['body' => '{"status":0,"errors":["nope"]}'], $captured));
    $error = assertThrows(static fn () => $client->uapi('Email', 'add_pop'), CpanelError::KIND_API);
    assertTrue(str_contains($error->getMessage(), 'Email::add_pop'), 'نام تابع باید داخل پیام باشد: ' . $error->getMessage());

    $whm = new CpanelClient(
        cpanelServer(['type' => CpanelClient::TYPE_WHM]),
        fakeTransport(['body' => '{"metadata":{"result":0,"reason":"denied"}}'], $captured),
    );
    $whmError = assertThrows(static fn () => $whm->whm('suspendacct'), CpanelError::KIND_API);
    assertTrue(str_contains($whmError->getMessage(), 'suspendacct'), 'نام دستور WHM باید داخل پیام باشد: ' . $whmError->getMessage());

    $emailError = assertThrows(static fn () => CpanelService::splitEmail('bad-address'), CpanelError::KIND_CONFIG);
    assertTrue(str_contains($emailError->getMessage(), 'bad-address'), 'مقدار نامعتبر باید داخل پیام باشد: ' . $emailError->getMessage());

    $missing = assertThrows(static fn () => new CpanelClient(cpanelServer(['host' => ''])), CpanelError::KIND_CONFIG);
    assertTrue(str_contains($missing->getMessage(), 'host'), 'نام فیلد ناقص باید داخل پیام باشد: ' . $missing->getMessage());
});

check('UAPI روی WHM با کاربر مقصد از پراکسی /json-api/cpanel رد می‌شود', function (): void {
    $captured = [];
    $client = (new CpanelClient(
        cpanelServer(['type' => CpanelClient::TYPE_WHM, 'port' => 2087, 'user' => 'root']),
        fakeTransport(['body' => '{"result":{"status":1,"data":[{"email":"x@y.z"}]}}'], $captured),
    ))->withUser('ali');

    $data = $client->uapi('Email', 'list_pops');

    assertSame('https://host.example.com:2087/json-api/cpanel', $captured[0]['url']);
    assertTrue(str_contains($captured[0]['body'], 'cpanel_jsonapi_apiversion=3'), 'apiversion=3 نبود');
    assertTrue(str_contains($captured[0]['body'], 'cpanel_jsonapi_user=ali'), 'کاربر مقصد در پارامترها نبود');
    assertSame('x@y.z', $data[0]['email']);
    assertSame('ali', $client->targetUser());
});

check('API2: پوسته‌ی cpanelresult باز می‌شود', function (): void {
    $captured = [];
    $client = new CpanelClient(
        cpanelServer(),
        fakeTransport(['body' => '{"cpanelresult":{"result":{"status":1,"data":[{"db":"demo_x"}]}}}'], $captured),
    );
    $data = $client->api2('MysqlFE', 'listdbs');

    assertTrue(str_contains($captured[0]['body'], 'cpanel_jsonapi_apiversion=2'), 'apiversion=2 نبود');
    assertSame('demo_x', $data[0]['db']);
});

check('خاموش‌کردن بررسی SSL به لایه‌ی انتقال می‌رسد', function (): void {
    $captured = [];
    $client = new CpanelClient(cpanelServer(['ssl_verify' => false]), fakeTransport(['body' => '{"status":1,"data":[]}'], $captured));
    $client->uapi('DomainInfo', 'list_domains');
    assertSame(false, $captured[0]['verify']);
});

check('آدرس هاست با پورت چسبیده درست تجزیه می‌شود', function (): void {
    assertSame(['host' => 'server.example.com', 'port' => 2083], Address::parse('https://server.example.com:2083/cpanel'));
    assertSame(['host' => 'server.example.com', 'port' => 2096], Address::parse('server.example.com:2096'));
    assertSame(['host' => 'server.example.com', 'port' => null], Address::parse('server.example.com'));
    assertSame(['host' => '1.2.3.4', 'port' => 2087], Address::parse(' 1.2.3.4:2087/ '));
    assertSame(['host' => '[::1]', 'port' => 2083], Address::parse('[::1]:2083'));
    assertSame(['host' => 'example.com', 'port' => null], Address::parse('example.com:99999'), 'پورت خارج از بازه نادیده گرفته شود');

    // پورت صریح > پورت داخل آدرس > پیش‌فرض نوع سرور
    assertSame(2095, Address::resolvePort('a.com:2083', 2095, CpanelClient::TYPE_CPANEL));
    assertSame(2083, Address::resolvePort('a.com:2083', '', CpanelClient::TYPE_CPANEL));
    assertSame(2087, Address::resolvePort('a.com', null, CpanelClient::TYPE_WHM));
    assertSame(2083, Address::resolvePort('a.com', null, CpanelClient::TYPE_CPANEL));
});

check('هر هاست با پورت خودش کار می‌کند، هم‌زمان', function (): void {
    // پورت هم می‌تواند جدا ذخیره شده باشد و هم به آدرس چسبیده باشد؛ هیچ‌کدام
    // نباید باعث آدرس خراب مثل host:2083:2083 شود.
    $onDefault = new CpanelClient(cpanelServer(['host' => 'a.example.com']));
    $onCustom = new CpanelClient(cpanelServer(['host' => 'b.example.com:2096', 'port' => 2083]));
    $onWhm = new CpanelClient(cpanelServer(['host' => 'c.example.com', 'type' => CpanelClient::TYPE_WHM, 'port' => 2087]));
    $behindProxy = new CpanelClient(cpanelServer(['host' => 'https://panel.example.com:443/cpanel', 'port' => 0]));

    assertSame('https://a.example.com:2083', $onDefault->endpoint());
    assertSame('https://b.example.com:2096', $onCustom->endpoint(), 'پورت داخل آدرس باید برنده شود');
    assertSame('https://c.example.com:2087', $onWhm->endpoint());
    assertSame('https://panel.example.com:443', $behindProxy->endpoint());
});

check('سرورهای ذخیره‌شده پورت جداگانه‌ی خودشان را نگه می‌دارند', function (): void {
    $a = ServerStore::add(['label' => 'A', 'type' => 'cpanel', 'host' => 'a.example.com:2083', 'user' => 'ua', 'token' => 'ta']);
    $b = ServerStore::add(['label' => 'B', 'type' => 'cpanel', 'host' => 'https://b.example.com:2096/', 'user' => 'ub', 'token' => 'tb']);
    $c = ServerStore::add(['label' => 'C', 'type' => 'whm', 'host' => 'c.example.com', 'user' => 'root', 'token' => 'tc']);

    assertSame(2083, ServerStore::get($a)['port']);
    assertSame('b.example.com', ServerStore::get($b)['host'], 'پروتکل و پورت نباید داخل هاست بمانند');
    assertSame(2096, ServerStore::get($b)['port']);
    assertSame(2087, ServerStore::get($c)['port']);

    assertSame('https://b.example.com:2096', ServerStore::client($b)->endpoint());
    assertSame('https://c.example.com:2087', ServerStore::client($c)->endpoint());

    foreach ([$a, $b, $c] as $id) {
        ServerStore::delete($id);
    }
});

echo "\nCpanelService\n";

check('جداکردن ایمیل به نام کاربری و دامنه', function (): void {
    assertSame(['info', 'example.com'], CpanelService::splitEmail('info@example.com'));
    assertThrows(static fn () => CpanelService::splitEmail('no-at-sign'), CpanelError::KIND_CONFIG);
});

check('اعتبارسنجی زمان‌بندی کران', function (): void {
    assertSame(
        ['minute' => '*/5', 'hour' => '*', 'day' => '*', 'month' => '*', 'weekday' => '1-5'],
        CpanelService::parseSchedule(' */5 * * *   1-5 '),
    );
    assertSame(null, CpanelService::parseSchedule('* * * *'), 'چهار فیلد باید رد شود');
    assertSame(null, CpanelService::parseSchedule('* * * * ; rm -rf /'), 'کاراکتر غیرمجاز باید رد شود');
});

check('لیست‌های دیکشنری‌شکل cPanel به لیست ساده تبدیل می‌شوند', function (): void {
    assertSame([1, 2], CpanelService::normalizeList(['a' => 1, 'b' => 2]));
    assertSame([], CpanelService::normalizeList(null));
    assertSame(['x'], CpanelService::normalizeList('x'));
});

check('لیست ایمیل‌ها با واحدهای متفاوت درست نرمال می‌شود', function (): void {
    $captured = [];
    $body = json_encode(['status' => 1, 'data' => [
        ['email' => 'b@x.com', 'domain' => 'x.com', '_diskused' => 1048576, '_diskquota' => 52428800, 'diskusedpercent' => 2],
        ['email' => 'a@x.com', 'domain' => 'x.com', 'diskused' => 5, 'diskquota' => 'unlimited'],
    ]]);
    $client = new CpanelClient(cpanelServer(), fakeTransport(['body' => (string) $body], $captured));
    $emails = CpanelService::emails($client);

    assertSame('a@x.com', $emails[0]['email'], 'خروجی باید مرتب باشد');
    assertSame(5.0 * 1024 * 1024, $emails[0]['used'], 'مگابایت باید به بایت تبدیل شود');
    assertSame('نامحدود', Format::quota($emails[0]['quota'], 'B'));
    assertSame(1048576.0, $emails[1]['used']);
    assertSame('50 MB', Format::quota($emails[1]['quota'], 'B'));
});

check('سهمیه‌ی مگابایتی بدون آندرلاین هم درست تفسیر می‌شود', function (): void {
    $captured = [];
    $body = json_encode(['status' => 1, 'data' => [
        ['email' => 'a@x.com', 'domain' => 'x.com', 'diskused' => 2, 'diskquota' => 100],
    ]]);
    $client = new CpanelClient(cpanelServer(), fakeTransport(['body' => (string) $body], $captured));
    $emails = CpanelService::emails($client);

    assertSame('2 MB', Format::bytes($emails[0]['used']));
    assertSame('100 MB', Format::quota($emails[0]['quota'], 'B'), 'مگابایت نباید بایت خوانده شود');
});

check('زون DNS: base64 دیکد و سریال SOA پیدا می‌شود', function (): void {
    $captured = [];
    $body = json_encode(['status' => 1, 'data' => [
        ['type' => 'control', 'text_b64' => base64_encode('$TTL 14400')],
        ['type' => 'record', 'line_index' => 8, 'record_type' => 'SOA', 'ttl' => 86400,
            'dname_b64' => base64_encode('example.com.'),
            'data_b64' => array_map('base64_encode', ['ns1.example.com.', 'root.example.com.', '2024010101', '3600'])],
        ['type' => 'record', 'line_index' => 12, 'record_type' => 'A', 'ttl' => 14400,
            'dname_b64' => base64_encode('www.example.com.'),
            'data_b64' => [base64_encode('203.0.113.10')]],
    ]]);
    $client = new CpanelClient(cpanelServer(), fakeTransport(['body' => (string) $body], $captured));
    $zone = CpanelService::dnsZone($client, 'example.com');

    assertSame('2024010101', $zone['serial']);
    assertSame(2, count($zone['records']));
    assertSame('www.example.com.', $zone['records'][1]['name']);
    assertSame('203.0.113.10', $zone['records'][1]['data'][0]);
    assertSame(12, $zone['records'][1]['line']);
});

check('افزودن رکورد DNS: اول سریال خوانده و بعد mass_edit_zone صدا زده می‌شود', function (): void {
    $captured = [];
    $parse = json_encode(['status' => 1, 'data' => [
        ['type' => 'record', 'line_index' => 8, 'record_type' => 'SOA',
            'dname_b64' => base64_encode('example.com.'),
            'data_b64' => array_map('base64_encode', ['ns1.example.com.', 'root.example.com.', '2024010101'])],
    ]]);
    $client = new CpanelClient(cpanelServer(), fakeTransport([
        ['body' => (string) $parse],
        ['body' => '{"status":1,"data":{}}'],
    ], $captured));

    CpanelService::addDnsRecord($client, 'example.com', 'www', 'a', ['203.0.113.10'], 300);

    assertSame(2, count($captured));
    assertTrue(str_contains($captured[1]['url'], 'mass_edit_zone'), 'باید mass_edit_zone صدا زده شود');
    $body = urldecode($captured[1]['body']);
    assertTrue(str_contains($body, 'serial=2024010101'), "سریال باید ارسال شود: $body");
    assertTrue(str_contains($body, '"record_type":"A"'), 'نوع رکورد باید بزرگ ارسال شود');
    assertTrue(str_contains($body, '"ttl":300'), 'TTL باید ارسال شود');
});

check('حساب‌های WHM مرتب و نرمال می‌شوند', function (): void {
    $captured = [];
    $body = json_encode(['metadata' => ['result' => 1], 'data' => ['acct' => [
        ['user' => 'zeta', 'domain' => 'z.com', 'suspended' => 1],
        ['user' => 'alpha', 'domain' => 'a.com', 'suspended' => 0],
    ]]]);
    $client = new CpanelClient(cpanelServer(['type' => CpanelClient::TYPE_WHM]), fakeTransport(['body' => (string) $body], $captured));
    $accounts = CpanelService::whmAccounts($client);

    assertSame('alpha', $accounts[0]['user']);
    assertSame(true, $accounts[1]['suspended']);
});

check('نبودن ماژول Cron در UAPI، خودکار به API2 برمی‌گردد', function (): void {
    // پیام واقعی سرورهایی که UAPI ماژول Cron ندارند.
    $missing = json_encode(['status' => 0, 'errors' => [
        'Failed to load module “Cron”: The system failed to load the module “Cpanel::API::Cron” because of an error: '
        . "Can't locate Cpanel/API/Cron.pm in @INC",
    ]]);
    $api2 = json_encode(['cpanelresult' => ['apiversion' => 2, 'module' => 'Cron', 'func' => 'listcron',
        'event' => ['result' => 1], 'data' => [
            ['linekey' => 'k1', 'minute' => '*/5', 'hour' => '*', 'day' => '*', 'month' => '*', 'weekday' => '*', 'command' => '/usr/local/bin/php /home/demo/cron.php'],
        ]]]);

    $captured = [];
    $client = new CpanelClient(cpanelServer(), fakeTransport([
        ['body' => (string) $missing],
        ['body' => (string) $api2],
    ], $captured));

    $jobs = CpanelService::cronJobs($client);

    assertSame(2, count($captured), 'باید اول UAPI و بعد API2 صدا زده شود');
    assertTrue(str_contains($captured[0]['url'], '/execute/Cron/list_lines'), 'اول باید UAPI امتحان شود');
    assertTrue(str_contains($captured[1]['url'], '/json-api/cpanel'), 'بعدش باید API2 صدا زده شود');
    assertTrue(str_contains($captured[1]['body'], 'cpanel_jsonapi_func=listcron'), 'تابع API2 باید listcron باشد');
    assertSame(1, count($jobs));
    assertSame('*/5 * * * *', $jobs[0]['schedule']);
    assertSame('k1', $jobs[0]['key']);
});

check('خطاهای عادی cPanel بی‌خود روی API2 دوباره امتحان نمی‌شوند', function (): void {
    $captured = [];
    $client = new CpanelClient(cpanelServer(), fakeTransport(
        ['body' => '{"status":0,"errors":["You do not have permission to manage cron jobs."]}'],
        $captured,
    ));

    $error = assertThrows(static fn () => CpanelService::cronJobs($client), CpanelError::KIND_API);
    assertTrue(str_contains($error->getMessage(), 'permission'), 'پیام اصلی باید حفظ شود');
    assertSame(1, count($captured), 'خطای غیرمرتبط نباید باعث تلاش دوباره شود');
});

check('افزودن کران هم روی API2 با همان پارامترها انجام می‌شود', function (): void {
    $missing = '{"status":0,"errors":["Failed to load module “Cron”"]}';
    $captured = [];
    $client = new CpanelClient(cpanelServer(), fakeTransport([
        ['body' => $missing],
        ['body' => '{"cpanelresult":{"event":{"result":1},"data":[{"linekey":"new"}]}}'],
    ], $captured));

    CpanelService::addCron($client, CpanelService::parseSchedule('0 3 * * *'), '/usr/local/bin/php backup.php');

    $body = urldecode($captured[1]['body']);
    assertTrue(str_contains($body, 'cpanel_jsonapi_func=add_line'), 'تابع API2 باید add_line باشد');
    assertTrue(str_contains($body, 'minute=0') && str_contains($body, 'hour=3'), "زمان‌بندی درست ارسال نشد: {$body}");
    assertTrue(str_contains($body, 'command=/usr/local/bin/php backup.php'), "دستور درست ارسال نشد: {$body}");
});

check('API2 وقتی event.result صفر باشد خطا می‌دهد', function (): void {
    $captured = [];
    $client = new CpanelClient(cpanelServer(), fakeTransport(
        ['body' => '{"cpanelresult":{"event":{"result":0,"reason":"Sorry, you do not have access"},"data":[]}}'],
        $captured,
    ));
    $error = assertThrows(static fn () => $client->api2('Cron', 'listcron'), CpanelError::KIND_API);
    assertTrue(str_contains($error->getMessage(), 'do not have access'), 'دلیل خطای API2 باید منتقل شود');
});

check('زون DNS روی نسخه‌های بدون UAPI از ZoneEdit خوانده می‌شود', function (): void {
    $missing = '{"status":0,"errors":["Failed to load module “DNS”"]}';
    $api2 = json_encode(['cpanelresult' => ['event' => ['result' => 1], 'data' => [
        ['Line' => 12, 'name' => 'example.com.', 'type' => 'A', 'ttl' => 14400, 'address' => '203.0.113.10'],
        ['Line' => 15, 'name' => 'mail.example.com.', 'type' => 'MX', 'ttl' => 14400, 'preference' => 10, 'exchange' => 'mail.example.com.'],
        ['Line' => 18, 'name' => 'example.com.', 'type' => 'TXT', 'ttl' => 300, 'txtdata' => 'v=spf1 -all'],
    ]]]);

    $captured = [];
    $client = new CpanelClient(cpanelServer(), fakeTransport([
        ['body' => $missing],
        ['body' => (string) $api2],
    ], $captured));

    $zone = CpanelService::dnsZone($client, 'example.com');

    assertTrue($zone['api2'], 'باید مسیر API2 استفاده شود');
    assertSame(3, count($zone['records']));
    assertSame(['203.0.113.10'], $zone['records'][0]['data']);
    assertSame(['10', 'mail.example.com.'], $zone['records'][1]['data'], 'MX باید اولویت و میزبان را با هم بدهد');
    assertSame(18, $zone['records'][2]['line']);
});

check('افزودن رکورد روی مسیر API2 فیلد درست هر نوع را می‌فرستد', function (): void {
    $missing = '{"status":0,"errors":["Failed to load module “DNS”"]}';
    $zone = '{"cpanelresult":{"event":{"result":1},"data":[]}}';
    $added = '{"cpanelresult":{"event":{"result":1},"data":[{"result":{"status":1}}]}}';

    $cases = [
        ['A', ['203.0.113.10'], 'address=203.0.113.10'],
        ['CNAME', ['target.example.com.'], 'cname=target.example.com.'],
        ['TXT', ['v=spf1', '-all'], 'txtdata=v=spf1 -all'],
        ['MX', ['10', 'mail.example.com.'], 'preference=10'],
    ];

    foreach ($cases as [$type, $data, $expected]) {
        $captured = [];
        $client = new CpanelClient(cpanelServer(), fakeTransport([
            ['body' => $missing],
            ['body' => $zone],
            ['body' => $added],
        ], $captured));

        CpanelService::addDnsRecord($client, 'example.com', 'www', $type, $data, 300);

        $body = urldecode($captured[2]['body']);
        assertTrue(str_contains($body, 'cpanel_jsonapi_func=add_zone_record'), 'باید add_zone_record صدا زده شود');
        assertTrue(str_contains($body, $expected), "برای {$type} انتظار «{$expected}» داشتیم: {$body}");
        assertTrue(str_contains($body, 'ttl=300'), 'TTL باید ارسال شود');
    }
});

echo "\nServerStore\n";

check('افزودن، انتخاب و حذف سرور روی دیسک', function (): void {
    $id = ServerStore::add(['label' => 'هاست اصلی', 'type' => 'cpanel', 'host' => 'https://a.example.com/', 'user' => 'demo', 'token' => 'T']);
    assertSame('a.example.com', ServerStore::get($id)['host'], 'پروتکل و اسلش باید حذف شود');
    assertSame(2083, ServerStore::get($id)['port'], 'پورت پیش‌فرض cPanel');
    assertSame($id, ServerStore::activeId(), 'اولین سرور باید فعال شود');

    $second = ServerStore::add(['label' => 'whm', 'type' => 'whm', 'host' => 'b.example.com', 'user' => 'root', 'token' => 'T2']);
    assertSame(2087, ServerStore::get($second)['port'], 'پورت پیش‌فرض WHM');
    ServerStore::setActive($second);
    assertSame($second, ServerStore::activeId());

    ServerStore::setAsUser($second, 'ali');
    assertSame('ali', ServerStore::client($second)->targetUser());

    ServerStore::delete($second);
    assertSame(null, ServerStore::get($second));
    assertSame($id, ServerStore::activeId(), 'بعد از حذف سرور فعال باید سرور دیگری انتخاب شود');
    ServerStore::delete($id);
});

check('سرور تعریف‌شده در .env خودکار به فهرست اضافه می‌شود', function (): void {
    putenv('CPANEL_HOST=env.example.com');
    putenv('CPANEL_USER=envuser');
    putenv('CPANEL_TOKEN=envtoken');
    putenv('CPANEL_SSL_VERIFY=0');

    $servers = ServerStore::all();
    assertTrue(isset($servers[ServerStore::ENV_CPANEL_ID]), 'سرور .env باید در فهرست باشد');
    assertSame('env', $servers[ServerStore::ENV_CPANEL_ID]['source']);
    assertSame(false, $servers[ServerStore::ENV_CPANEL_ID]['ssl_verify']);

    putenv('CPANEL_HOST');
    putenv('CPANEL_USER');
    putenv('CPANEL_TOKEN');
    putenv('CPANEL_SSL_VERIFY');
});

check('بدون هیچ سروری، پیام راهنما داده می‌شود نه خطای مبهم', function (): void {
    assertThrows(static fn () => ServerStore::client(), CpanelError::KIND_CONFIG);
});

check('شناسه‌ی سرور اشتباه، پیام مخصوص خودش را می‌دهد', function (): void {
    $id = ServerStore::add(['label' => 'Main', 'type' => 'cpanel', 'host' => 'h.example.com', 'user' => 'u', 'token' => 't']);
    $error = assertThrows(static fn () => ServerStore::client('typo'), CpanelError::KIND_CONFIG);
    assertTrue(str_contains($error->getMessage(), 'typo'), 'شناسه‌ی اشتباه باید در پیام بیاید: ' . $error->getMessage());
    assertTrue(str_contains($error->getMessage(), $id), 'شناسه‌های موجود باید فهرست شوند: ' . $error->getMessage());
    ServerStore::delete($id);
});

echo "\nFormat و Render\n";

check('تبدیل حجم و سهمیه', function (): void {
    assertSame('1 KB', Format::bytes(1024));
    assertSame('1.5 MB', Format::bytes(1024 * 1024 * 1.5));
    assertSame('—', Format::bytes(null));
    // عددهایی که به صفر ختم می‌شن نباید ناقص چاپ بشن (0 → «0 B» نه « B»).
    assertSame('0 B', Format::bytes(0));
    assertSame('1,000 B', Format::bytes(1000));
    assertSame('100 KB', Format::bytes(102400));
    assertSame('10٪', Format::percent(10.0));
    assertSame('0٪', Format::percent(0.0));
    assertSame('نامحدود', Format::quota(0));
    assertSame('نامحدود', Format::quota('unlimited'));
    assertSame('50 MB', Format::quota(50));
});

check('پوشاندن توکن در خروجی‌ها', function (): void {
    assertSame('••••', Format::maskSecret('ABCD'));
    assertSame('ABCD••••••WXYZ', Format::maskSecret('ABCDEFGHIJKLMNOPQRSTUVWXYZ'));
    assertSame('—', Format::maskSecret(''));
});

check('تکه‌کردن پیام‌های بلند روی مرز خط', function (): void {
    $text = implode("\n", array_fill(0, 500, 'خط نمونه برای تست طول پیام'));
    $chunks = Render::chunks($text, 1000);
    assertTrue(count($chunks) > 1, 'متن بلند باید تکه شود');
    foreach ($chunks as $chunk) {
        assertTrue(mb_strlen($chunk) <= 1000, 'هر تکه باید از سقف کوتاه‌تر باشد');
    }
    assertSame($text, implode("\n", $chunks), 'محتوا نباید تغییر کند');
});

check('خروجی متنی CLI بدون تگ HTML است', function (): void {
    $html = Render::servers([
        'srv' => ['label' => 'Main', 'type' => 'cpanel', 'host' => 'h.com', 'port' => 2083, 'user' => 'u', 'token' => 'ABCDEFGHIJKL', 'source' => 'file'],
    ], 'srv');
    $plain = Render::toPlain($html);
    assertTrue(!str_contains($plain, '<'), 'تگ HTML نباید باقی بماند');
    assertTrue(!str_contains($plain, 'ABCDEFGHIJKL'), 'توکن کامل نباید نمایش داده شود');
});

check('خطای «ماژول نیست» به زبان آدمیزاد ترجمه می‌شود', function (): void {
    $raw = CpanelError::api('cPanel دستور «SSL::installed_hosts» را رد کرد: Failed to load module “SSL”');
    $text = Render::toPlain(Render::error($raw));
    assertTrue(str_contains($text, 'در دسترس نیست'), 'باید توضیح فارسی داده شود');
    assertTrue(!str_contains($text, 'Failed to load module'), 'پیام خام پرل نباید به کاربر نشان داده شود');
});

check('پیام خطا برای توکن نامعتبر راهنمای ساخت توکن دارد', function (): void {
    $text = Render::toPlain(Render::error(CpanelError::auth(403)));
    assertTrue(str_contains($text, 'Manage API Tokens'), 'راهنمای ساخت توکن باید نمایش داده شود');
});

echo "\nUpdateHandler (جدول جریان‌ها)\n";

/**
 * @return array<string, array<string, mixed>>
 */
function flows(): array
{
    return (new ReflectionClass(UpdateHandler::class))->getConstant('FLOWS');
}

check('هر جریان یک متد اجرای متناظر دارد', function (): void {
    foreach (flows() as $name => $definition) {
        $method = 'run' . str_replace(' ', '', ucwords(str_replace('_', ' ', $name)));
        assertTrue(
            method_exists(UpdateHandler::class, $method),
            "متد $method برای جریان $name وجود ندارد",
        );
    }
});

check('تعریف مرحله‌های هر جریان کامل است', function (): void {
    foreach (flows() as $name => $definition) {
        assertTrue(isset($definition['title']), "جریان $name عنوان ندارد");
        assertTrue(in_array($definition['scope'] ?? '', ['account', 'whm', 'local'], true), "scope جریان $name نامعتبر است");
        assertTrue(($definition['steps'] ?? []) !== [], "جریان $name مرحله‌ای ندارد");
        foreach ($definition['steps'] as $index => $step) {
            assertTrue(isset($step['key'], $step['prompt']), "مرحله‌ی $index از $name ناقص است");
            if (($step['optional'] ?? false) === true) {
                assertTrue(array_key_exists('default', $step), "مرحله‌ی اختیاری {$step['key']} در $name مقدار پیش‌فرض ندارد");
            }
        }
    }
});

check('جای‌خالی‌های متن تأیید به کلیدهای واقعی اشاره می‌کنند', function (): void {
    foreach (flows() as $name => $definition) {
        if (!isset($definition['confirm'])) {
            continue;
        }
        $keys = array_column($definition['steps'], 'key');
        preg_match_all('/\{([a-z_]+)\}/', (string) $definition['confirm'], $matches);
        foreach ($matches[1] as $placeholder) {
            assertTrue(in_array($placeholder, $keys, true), "متن تأیید $name به کلید ناشناخته‌ی $placeholder اشاره دارد");
        }
    }
});

check('پیام‌های داخل جریان از دستورهای جدید تفکیک می‌شوند', function (): void {
    assertTrue(UpdateHandler::isFlowInput('info@example.com'), 'متن معمولی باید ورودی جریان باشد');
    // این دو خودشان بخشی از جریان‌اند و نباید جریان را کنسل کنند.
    assertTrue(UpdateHandler::isFlowInput('/skip'), '/skip باید ورودی جریان بماند');
    assertTrue(UpdateHandler::isFlowInput('/gen'), '/gen باید ورودی جریان بماند');
    assertTrue(!UpdateHandler::isFlowInput('/panel'), 'دستور معمولی باید جریان را رها کند');
    assertTrue(!UpdateHandler::isFlowInput('/emails'), 'دستور معمولی باید جریان را رها کند');
});

check('هر picker در جدول جریان‌ها واقعاً پیاده‌سازی شده است', function (): void {
    $source = (string) file_get_contents(__DIR__ . '/../src/UpdateHandler.php');
    $body = substr($source, (int) strpos($source, 'private static function pickerOptions'));
    $body = substr($body, 0, (int) strpos($body, 'private static function shorten'));

    foreach (flows() as $name => $definition) {
        foreach ($definition['steps'] as $step) {
            if (!isset($step['picker'])) {
                continue;
            }
            assertTrue(
                str_contains($body, "case '{$step['picker']}':"),
                "picker «{$step['picker']}» در جریان {$name} پیاده‌سازی نشده است",
            );
        }
    }
});

check('گزینه‌های ثابت هر مرحله شکل درستی دارند', function (): void {
    foreach (flows() as $name => $definition) {
        foreach ($definition['steps'] as $step) {
            if (!isset($step['choices'])) {
                continue;
            }
            assertTrue($step['choices'] !== [], "گزینه‌های {$name} خالی است");
            foreach ($step['choices'] as $choice) {
                assertTrue(is_array($choice) && count($choice) === 2, "گزینه‌ی {$name} باید [برچسب، مقدار] باشد");
                assertTrue(is_string($choice[0]) && $choice[0] !== '', "برچسب گزینه در {$name} خالی است");
                assertTrue(is_string($choice[1]), "مقدار گزینه در {$name} باید رشته باشد");
            }
            // مرحله‌ای که گزینه‌ی خالی دارد باید اختیاری باشد، وگرنه کاربر با
            // زدن آن دکمه به خطای «مقدار خالی است» می‌خورد.
            $hasEmpty = in_array('', array_column($step['choices'], 1), true);
            if ($hasEmpty) {
                assertTrue(($step['optional'] ?? false) === true, "مرحله‌ی {$step['key']} در {$name} گزینه‌ی خالی دارد ولی اختیاری نیست");
            }
        }
    }
});

check('کیبورد گزینه‌ها صفحه‌بندی می‌شود و callback کوتاه می‌ماند', function (): void {
    $method = new ReflectionMethod(UpdateHandler::class, 'optionsKeyboard');
    $options = [];
    for ($i = 0; $i < 25; $i++) {
        $options[] = ['label' => "خیلی-خیلی-طولانی-گزینه-شماره-{$i}@some-long-domain-example.com", 'value' => "v{$i}"];
    }

    $first = $method->invoke(null, $options, 0, false)['inline_keyboard'];
    $middle = $method->invoke(null, $options, 1, true)['inline_keyboard'];

    $flat = static function (array $rows): array {
        $out = [];
        foreach ($rows as $row) {
            foreach ($row as $button) {
                $out[] = $button;
            }
        }

        return $out;
    };

    $firstButtons = $flat($first);
    assertSame('c:0', $firstButtons[0]['callback_data'], 'اولین دکمه باید به گزینه‌ی صفر اشاره کند');
    foreach ($firstButtons as $button) {
        assertTrue(strlen($button['callback_data']) <= 64, 'callback_data از سقف ۶۴ بایت تلگرام بلندتر است');
        assertTrue(mb_strlen($button['text']) <= 50, 'برچسب دکمه باید کوتاه شود: ' . $button['text']);
    }

    $labels = array_column($flat($middle), 'callback_data');
    assertTrue(in_array('c:8', $labels, true), 'صفحه‌ی دوم باید از گزینه‌ی ۸ شروع شود');
    assertTrue(in_array('pg:0', $labels, true), 'دکمه‌ی صفحه‌ی قبل باید باشد');
    assertTrue(in_array('pg:2', $labels, true), 'دکمه‌ی صفحه‌ی بعد باید باشد');
    assertTrue(in_array('skip', $labels, true), 'مرحله‌ی اختیاری باید دکمه‌ی رد کردن داشته باشد');
    assertTrue(in_array('no', $labels, true), 'دکمه‌ی لغو باید همیشه باشد');

    // فهرست کوتاه نباید دکمه‌ی صفحه‌بندی بگیرد.
    $short = $flat($method->invoke(null, array_slice($options, 0, 3), 0, false)['inline_keyboard']);
    foreach ($short as $button) {
        assertTrue(!str_starts_with($button['callback_data'], 'pg:'), 'فهرست کوتاه نباید صفحه‌بندی داشته باشد');
    }
});

check('همه‌ی دکمه‌ها به جریان/کار موجود وصل‌اند', function (): void {
    $source = (string) file_get_contents(__DIR__ . '/../src/UpdateHandler.php');

    preg_match_all("/'f:([a-z_]+)'/", $source, $flowButtons);
    foreach (array_unique($flowButtons[1]) as $flow) {
        assertTrue(isset(flows()[$flow]), "دکمه به جریان ناشناخته‌ی $flow اشاره دارد");
    }

    preg_match_all("/'a:([a-z]+)'/", $source, $actionButtons);
    foreach (array_unique($actionButtons[1]) as $action) {
        $handled = str_contains($source, "'$action' =>") || str_contains($source, "\$action === '$action'");
        assertTrue($handled, "کار $action در runAction پیاده‌سازی نشده است");
    }

    preg_match_all("/'m:([a-z]+)'/", $source, $menuButtons);
    foreach (array_unique($menuButtons[1]) as $menu) {
        assertTrue(str_contains($source, "'$menu' => ["), "منوی $menu تعریف نشده است");
    }
});

echo "\nState و AuditLog\n";

check('جریان چندمرحله‌ای ذخیره و پاک می‌شود', function (): void {
    State::set(555, 'email_add', 1, ['email' => 'a@b.c']);
    $state = State::get(555);
    assertSame('email_add', $state['flow']);
    assertSame(1, $state['step']);
    assertSame('a@b.c', $state['data']['email']);
    State::clear(555);
    assertSame(null, State::get(555));
});

check('رمزها در لاگ ذخیره نمی‌شوند', function (): void {
    AuditLog::record('tg:1', 'srv', 'email_add', ['email' => 'a@b.c', 'password' => 'super-secret']);
    $last = AuditLog::tail(1)[0] ?? '';
    assertTrue(str_contains($last, 'a@b.c'), 'ایمیل باید ثبت شود');
    assertTrue(!str_contains($last, 'super-secret'), 'رمز نباید ثبت شود');
});

echo "\n";
echo $failed === 0
    ? "✅ همه‌ی $passed تست پاس شدند.\n"
    : "❌ $failed تست شکست خورد ($passed پاس).\n";

array_map('unlink', glob("$dataDir/*") ?: []);
@rmdir($dataDir);

exit($failed === 0 ? 0 : 1);
