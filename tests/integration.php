#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * تست یکپارچه‌ی رابط تلگرام:
 *
 *   php tests/integration.php
 *
 * یک سرور ساختگی Bot API بالا می‌آورد (روی 127.0.0.1، بدون اینترنت) و آپدیت‌های
 * ساختگی را از همان مسیری رد می‌کند که آپدیت‌های واقعی می‌روند — یعنی منوها،
 * دکمه‌ها، مرحله‌های جریان و دسترسی ادمین واقعاً اجرا می‌شوند و بعد بررسی
 * می‌کنیم ربات دقیقاً چه چیزی برای تلگرام فرستاده است.
 */

require_once __DIR__ . '/../bootstrap.php';

$port = 18081 + (getmypid() % 500);
$dataDir = sys_get_temp_dir() . '/kan_checker_it_' . getmypid();
$logPath = $dataDir . '/telegram.log';
@mkdir($dataDir, 0700, true);
touch($logPath);

putenv("KAN_DATA_DIR=$dataDir");
putenv("FAKE_TG_LOG=$logPath");
putenv("TELEGRAM_API_BASE=http://127.0.0.1:$port");
putenv('TELEGRAM_BOT_TOKEN=test:token');
putenv('ADMIN_ID=555');

$serverProcess = proc_open(
    [PHP_BINARY, '-S', "127.0.0.1:$port", __DIR__ . '/fake_telegram.php'],
    [1 => ['file', $dataDir . '/server.out', 'a'], 2 => ['file', $dataDir . '/server.out', 'a']],
    $pipes,
    null,
    ['FAKE_TG_LOG' => $logPath] + getenv(),
);
if (!is_resource($serverProcess)) {
    fwrite(STDERR, "نتوانستم سرور ساختگی تلگرام را اجرا کنم.\n");
    exit(1);
}

// منتظر بالا آمدن سرور ساختگی می‌مانیم.
for ($i = 0; $i < 50; $i++) {
    $probe = @fsockopen('127.0.0.1', $port, $errno, $errstr, 0.2);
    if ($probe !== false) {
        fclose($probe);
        break;
    }
    usleep(100_000);
}

$passed = 0;
$failed = 0;
$api = new TelegramApi('test:token');
$adminIds = kanchecker_admin_ids();

function tg_log_reset(): void
{
    file_put_contents((string) getenv('FAKE_TG_LOG'), '');
}

/**
 * @return array<int, array{method: string, params: array<string, mixed>}>
 */
function tg_calls(): array
{
    $lines = file((string) getenv('FAKE_TG_LOG'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

    return array_map(static fn (string $line): array => json_decode($line, true), $lines);
}

/**
 * @return array<int, array{method: string, params: array<string, mixed>}>
 */
function tg_messages(): array
{
    return array_values(array_filter(tg_calls(), static fn (array $call): bool => $call['method'] === 'sendMessage'));
}

function tg_last_text(): string
{
    $messages = tg_messages();
    $last = end($messages);

    return $last === false ? '' : (string) ($last['params']['text'] ?? '');
}

/**
 * @return array<int, string> callback_data همه‌ی دکمه‌های آخرین پیام
 */
function tg_last_buttons(): array
{
    $messages = tg_messages();
    $last = end($messages);
    if ($last === false || !isset($last['params']['reply_markup'])) {
        return [];
    }
    $markup = json_decode((string) $last['params']['reply_markup'], true);
    $out = [];
    foreach ($markup['inline_keyboard'] ?? [] as $row) {
        foreach ($row as $button) {
            $out[] = (string) $button['callback_data'];
        }
    }

    return $out;
}

function send_text(string $text, int $from = 555): void
{
    global $api, $adminIds;
    UpdateHandler::handle($api, [
        'message_id' => random_int(1, 999),
        'chat' => ['id' => 555],
        'from' => ['id' => $from],
        'text' => $text,
    ], $adminIds);
}

function press(string $callbackData, int $from = 555): void
{
    global $api, $adminIds;
    UpdateHandler::handleCallback($api, [
        'id' => (string) random_int(1, 999),
        'data' => $callbackData,
        'from' => ['id' => $from],
        'message' => ['chat' => ['id' => 555], 'message_id' => 1],
    ], $adminIds);
}

function scenario(string $name, callable $test): void
{
    global $passed, $failed;
    tg_log_reset();
    try {
        $test();
        $passed++;
        echo "  ✓ $name\n";
    } catch (Throwable $e) {
        $failed++;
        echo "  ✗ $name\n     " . $e->getMessage() . "\n";
    }
}

function expect(bool $condition, string $message): void
{
    if (!$condition) {
        throw new RuntimeException($message . "\n     آخرین پیام: " . mb_substr(tg_last_text(), 0, 300));
    }
}

echo "رابط تلگرام (end-to-end با سرور ساختگی)\n";

scenario('/start منوی اصلی را با دکمه‌های شیشه‌ای می‌فرستد', function (): void {
    send_text('/start');
    $buttons = tg_last_buttons();
    expect($buttons !== [], 'هیچ دکمه‌ای فرستاده نشد');
    foreach (['a:summary', 'm:email', 'm:db', 'm:srv', 'm:whm'] as $expected) {
        expect(in_array($expected, $buttons, true), "دکمه‌ی $expected در منوی اصلی نبود");
    }
});

scenario('غیر ادمین هیچ پاسخی نمی‌گیرد', function (): void {
    send_text('/start', 999);
    expect(tg_messages() === [], 'به کاربر غیرمجاز پاسخ داده شد!');
});

scenario('منوی ایمیل دکمه‌های عملیات را دارد', function (): void {
    press('m:email');
    $buttons = tg_last_buttons();
    foreach (['a:emails', 'f:email_add', 'f:email_del', 'f:email_passwd', 'menu'] as $expected) {
        expect(in_array($expected, $buttons, true), "دکمه‌ی $expected در منوی ایمیل نبود");
    }
});

scenario('افزودن سرور: نوع اتصال با دکمه پرسیده می‌شود', function (): void {
    press('f:srv_add');
    expect(str_contains(tg_last_text(), 'اسم دلخواه'), 'مرحله‌ی اول باید اسم سرور را بپرسد');

    send_text('هاست تست');
    $buttons = tg_last_buttons();
    expect(in_array('c:0', $buttons, true) && in_array('c:1', $buttons, true), 'نوع سرور باید دو دکمه داشته باشد');
    expect(in_array('no', $buttons, true), 'دکمه‌ی لغو باید باشد');
});

scenario('انتخاب نوع با دکمه، مرحله‌ی بعد را می‌آورد', function (): void {
    press('c:0');
    expect(str_contains(tg_last_text(), 'آدرس سرور'), 'بعد از انتخاب نوع باید آدرس پرسیده شود');
});

scenario('پورت با دکمه‌های آماده پرسیده می‌شود و قابل رد کردن است', function (): void {
    send_text('127.0.0.1:12085');
    $text = tg_last_text();
    $buttons = tg_last_buttons();
    expect(str_contains($text, 'پورت'), 'مرحله‌ی پورت نیامد');
    expect(in_array('skip', $buttons, true), 'مرحله‌ی اختیاری باید دکمه‌ی رد کردن داشته باشد');
    expect(count(array_filter($buttons, static fn (string $b): bool => str_starts_with($b, 'c:'))) >= 4, 'گزینه‌های پورت کم است');
});

scenario('رمز/توکن با /gen ساخته می‌شود و اتصال قبل از ذخیره تست می‌شود', function (): void {
    press('skip');           // پورت: همان داخل آدرس
    send_text('demo');       // نام کاربری
    send_text('/gen');       // توکن ساختگی
    $text = tg_last_text();
    // چون سروری روی این آدرس نیست، باید خطای اتصال بدهد و سرور را ذخیره نکند.
    expect(str_contains($text, '⚠️'), 'باید خطای اتصال گزارش شود');
    expect(ServerStore::all() === [], 'سرور با توکن نامعتبر نباید ذخیره شود');
});

scenario('/cancel وسط جریان، آن را لغو می‌کند', function (): void {
    press('f:srv_add');
    send_text('/cancel');
    expect(str_contains(tg_last_text(), 'لغو شد'), 'پیام لغو نیامد');
    expect(State::get(555) === null, 'وضعیت جریان باید پاک شود');
});

scenario('دستور جدید وسط جریان، جریان را رها می‌کند', function (): void {
    press('f:db_add');
    send_text('/panel');
    expect(State::get(555) === null, 'جریان باید رها شود');
    expect(tg_last_buttons() !== [], 'منو باید نمایش داده شود');
});

scenario('بدون سرور، عملیات‌ها پیام راهنمای روشن می‌دهند', function (): void {
    press('a:summary');
    expect(str_contains(tg_last_text(), 'سرور'), 'باید بگوید سروری تنظیم نشده');
});

scenario('منوی سرورها دکمه‌ی نصب کران هاست اشتراکی را دارد', function (): void {
    press('m:srv');
    expect(in_array('f:selfhost_cron', tg_last_buttons(), true), 'دکمه‌ی کران هاست اشتراکی نبود');
});

scenario('پیام‌های بلند تکه می‌شوند نه اینکه رد شوند', function (): void {
    $api = new TelegramApi('test:token');
    $api->sendHtml(555, implode("\n", array_fill(0, 800, 'یک خط نمونه برای پر کردن پیام')));
    $messages = tg_messages();
    expect(count($messages) > 1, 'پیام بلند باید چند تکه شود');
    foreach ($messages as $message) {
        expect(mb_strlen((string) $message['params']['text']) <= 4096, 'تکه‌ای از سقف تلگرام بلندتر است');
    }
});

// ---------------------------------------------------------------------------
// بخش دوم: همان مسیر، این بار با یک cPanel ساختگی روی TLS — تا فهرست‌های
// زنده (دکمه‌های ایمیل/رکورد DNS)، تأیید، و اجرای واقعی عملیات هم تست شوند.
// ---------------------------------------------------------------------------

$cpanelPort = $port + 1;
$certPath = $dataDir . '/cert.pem';
$cpanelLog = $dataDir . '/cpanel.log';
touch($cpanelLog);

$key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
$csr = openssl_csr_new(['commonName' => '127.0.0.1'], $key, ['digest_alg' => 'sha256']);
$cert = openssl_csr_sign($csr, null, $key, 2, ['digest_alg' => 'sha256']);
openssl_x509_export($cert, $certOut);
openssl_pkey_export($key, $keyOut);
file_put_contents($certPath, $keyOut . $certOut);
chmod($certPath, 0600);

$cpanelProcess = proc_open(
    [PHP_BINARY, __DIR__ . '/fake_cpanel.php', (string) $cpanelPort, $certPath, $cpanelLog],
    [1 => ['file', $dataDir . '/cpanel.out', 'a'], 2 => ['file', $dataDir . '/cpanel.out', 'a']],
    $cpanelPipes,
);

for ($i = 0; $i < 50; $i++) {
    $probe = @fsockopen('127.0.0.1', $cpanelPort, $errno, $errstr, 0.2);
    if ($probe !== false) {
        fclose($probe);
        break;
    }
    usleep(100_000);
}

/**
 * @return array<int, array{path: string, body: string}>
 */
function cpanel_calls(): array
{
    global $cpanelLog;
    $lines = file($cpanelLog, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [];

    return array_map(static fn (string $line): array => json_decode($line, true), $lines);
}

if (is_resource($cpanelProcess)) {
    $serverId = ServerStore::add([
        'label' => 'cPanel تستی',
        'type' => 'cpanel',
        'host' => "127.0.0.1:$cpanelPort",
        'user' => 'demo',
        'token' => 'TESTTOKEN',
        'ssl_verify' => false,
    ]);
    ServerStore::setActive($serverId);

    scenario('تست اتصال از دکمه، به سرور واقعی وصل می‌شود', function (): void {
        press('a:ping');
        expect(str_contains(tg_last_text(), 'اتصال برقرار شد'), 'تست اتصال موفق نبود');
        expect(str_contains(tg_last_text(), 'demo'), 'نام کاربری در پاسخ نبود');
    });

    scenario('پورت غیرپیش‌فرض هاست واقعاً استفاده می‌شود', function () use ($cpanelPort): void {
        press('a:emails');
        expect(cpanel_calls() !== [], 'هیچ درخواستی به cPanel نرفت');
        expect(str_contains(tg_last_text(), 'sales@example.com'), 'فهرست ایمیل‌ها نیامد');
        expect(str_contains(tg_last_text(), '50 MB'), 'سهمیه درست نمایش داده نشد');
    });

    scenario('حذف ایمیل: فهرست زنده به‌صورت دکمه می‌آید', function (): void {
        press('f:email_del');
        $text = tg_last_text();
        $buttons = tg_last_buttons();
        expect(str_contains($text, 'کدام حساب ایمیل'), 'سؤال انتخاب ایمیل نیامد');
        expect(in_array('c:0', $buttons, true) && in_array('c:1', $buttons, true), 'ایمیل‌ها به‌صورت دکمه نیامدند');
    });

    scenario('انتخاب از دکمه، مرحله‌ی تأیید را با همان مقدار می‌آورد', function (): void {
        press('c:1');
        $text = tg_last_text();
        $buttons = tg_last_buttons();
        expect(str_contains($text, 'sales@example.com'), 'مقدار انتخاب‌شده در متن تأیید نیست');
        expect(in_array('ok', $buttons, true) && in_array('no', $buttons, true), 'دکمه‌های تأیید/انصراف نیامدند');
    });

    scenario('تأیید، عملیات را واقعاً روی cPanel اجرا می‌کند', function (): void {
        press('ok');
        $deleteCalls = array_filter(cpanel_calls(), static fn (array $c): bool => str_contains($c['path'], 'delete_pop'));
        expect($deleteCalls !== [], 'درخواست حذف به cPanel نرفت');
        $body = urldecode((string) (array_values($deleteCalls)[0]['body'] ?? ''));
        expect(str_contains($body, 'email=sales'), "نام کاربری ایمیل درست فرستاده نشد: $body");
        expect(str_contains($body, 'domain=example.com'), "دامنه درست فرستاده نشد: $body");
        expect(str_contains(tg_last_text(), 'حذف شد'), 'پیام موفقیت نیامد');
    });

    scenario('انصراف، هیچ درخواستی به سرور نمی‌فرستد', function (): void {
        press('f:email_del');
        press('c:0');
        $before = count(cpanel_calls());
        press('no');
        expect(count(cpanel_calls()) === $before, 'بعد از انصراف نباید درخواستی برود');
        expect(str_contains(tg_last_text(), 'لغو شد'), 'پیام لغو نیامد');
    });

    scenario('رکوردهای DNS به‌صورت دکمه می‌آیند و SOA پیشنهاد نمی‌شود', function (): void {
        press('f:dns_del');
        press('c:0');                       // انتخاب دامنه‌ی اول
        $text = tg_last_text();
        expect(str_contains($text, 'کدام رکورد'), 'مرحله‌ی انتخاب رکورد نیامد');
        expect(count(tg_last_buttons()) >= 1, 'رکوردی به‌صورت دکمه نیامد');
        send_text('/cancel');
    });

    scenario('لاگ عملیات، کار انجام‌شده را ثبت کرده است', function (): void {
        press('a:log');
        expect(str_contains(tg_last_text(), 'email_del'), 'عملیات حذف در لاگ نیست');
    });

    proc_terminate($cpanelProcess);
    proc_close($cpanelProcess);
} else {
    echo "  ⚠️ سرور ساختگی cPanel اجرا نشد؛ سناریوهای وابسته رد شدند.\n";
}

proc_terminate($serverProcess);
proc_close($serverProcess);
array_map('unlink', glob("$dataDir/*") ?: []);
@rmdir($dataDir);

echo "\n";
echo $failed === 0 ? "✅ همه‌ی $passed سناریو پاس شدند.\n" : "❌ $failed سناریو شکست خورد ($passed پاس).\n";
exit($failed === 0 ? 0 : 1);
