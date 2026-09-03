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

$dataDir = sys_get_temp_dir() . '/kan_checker_tests_' . getmypid();
@mkdir($dataDir, 0700, true);
putenv("KAN_DATA_DIR=$dataDir");

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
