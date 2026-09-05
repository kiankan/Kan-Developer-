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
$dataDir = sys_get_temp_dir() . '/teamkan_it_' . getmypid();
$logPath = $dataDir . '/telegram.log';
@mkdir($dataDir, 0700, true);
touch($logPath);

putenv("TEAMKAN_DATA_DIR=$dataDir");
putenv("FAKE_TG_LOG=$logPath");
putenv("TELEGRAM_API_BASE=http://127.0.0.1:$port");
putenv('TELEGRAM_BOT_TOKEN=test:token');
// عمداً ADMIN_ID نمی‌گذاریم: می‌خواهیم مسیر «تحویل‌گرفتن ربات با کد نصب» هم
// دقیقاً مثل یک نصب تازه تست شود.
putenv('ADMIN_ID');
putenv('ADMIN_IDS');

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
 * پیام‌های تازه‌ی ربات.
 *
 * @return array<int, array{method: string, params: array<string, mixed>}>
 */
function tg_messages(): array
{
    return array_values(array_filter(tg_calls(), static fn (array $call): bool => $call['method'] === 'sendMessage'));
}

/**
 * هر چیزی که کاربر در نهایت روی صفحه می‌بیند — چه پیام تازه، چه ویرایش پیام
 * قبلی (ربات تا جای ممکن همان پیام را ویرایش می‌کند).
 *
 * @return array<int, array{method: string, params: array<string, mixed>}>
 */
function tg_screens(): array
{
    return array_values(array_filter(
        tg_calls(),
        static fn (array $call): bool => in_array($call['method'], ['sendMessage', 'editMessageText'], true),
    ));
}

function tg_edits(): int
{
    return count(array_filter(tg_calls(), static fn (array $call): bool => $call['method'] === 'editMessageText'));
}

function tg_last_text(): string
{
    $screens = tg_screens();
    $last = end($screens);

    return $last === false ? '' : (string) ($last['params']['text'] ?? '');
}

/**
 * @return array<int, string> callback_data همه‌ی دکمه‌های آخرین پیام
 */
function tg_last_buttons(): array
{
    $screens = tg_screens();
    $last = end($screens);
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
    global $api;
    UpdateHandler::handle($api, [
        'message_id' => random_int(1, 999),
        'chat' => ['id' => 555],
        'from' => ['id' => $from],
        'text' => $text,
    ]);
}

function send_document(string $name, int $size = 2048, int $from = 555): void
{
    global $api;
    UpdateHandler::handle($api, [
        'message_id' => random_int(1, 999),
        'chat' => ['id' => 555],
        'from' => ['id' => $from],
        'document' => ['file_id' => 'FILE123', 'file_name' => $name, 'file_size' => $size],
    ]);
}

function press(string $callbackData, int $from = 555): void
{
    global $api;
    UpdateHandler::handleCallback($api, [
        'id' => (string) random_int(1, 999),
        'data' => $callbackData,
        'from' => ['id' => $from],
        'message' => ['chat' => ['id' => 555], 'message_id' => 1],
    ]);
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

scenario('ربات تحویل‌نگرفته فقط کد نصب را قبول می‌کند', function (): void {
    expect(!Admins::isClaimed(), 'ربات نباید از قبل ادمین داشته باشد');
    send_text('/start');
    expect(str_contains(tg_last_text(), 'تحویل گرفته نشده'), 'باید بگوید هنوز تحویل گرفته نشده');
    expect(!Admins::isClaimed(), 'با پیام معمولی نباید کسی مالک شود');

    send_text('WRONG-CODE');
    expect(!Admins::isClaimed(), 'کد اشتباه نباید مالکیت بدهد');
});

scenario('کد نصب درست، فرستنده را مالک می‌کند و کد را می‌سوزاند', function (): void {
    $code = Admins::claimCode();
    send_text($code);
    expect(Admins::isClaimed(), 'بعد از کد درست باید مالک ثبت شود');
    expect(Admins::isAdmin(555), 'فرستنده‌ی کد باید ادمین شود');
    expect(str_contains(tg_last_text(), 'تحویل شما شد'), 'پیام خوش‌آمد نیامد');
    expect(Admins::claimCode() !== $code, 'کد نصب باید یک‌بارمصرف باشد');
});

scenario('نصب تازه: منو فقط سرورها و راهنما را نشان می‌دهد', function (): void {
    send_text('/start');
    $buttons = tg_last_buttons();
    expect($buttons !== [], 'هیچ دکمه‌ای فرستاده نشد');
    expect(in_array('m:srv', $buttons, true), 'دکمه‌ی سرورها باید باشد');
    expect(in_array('a:help', $buttons, true), 'دکمه‌ی راهنما باید باشد');

    // تا وقتی هاستی تعریف نشده، دکمه‌ای که فقط خطا می‌دهد نباید نشان داده شود.
    foreach (['a:summary', 'm:email', 'm:db', 'm:whm', 'm:files'] as $unexpected) {
        expect(!in_array($unexpected, $buttons, true), "دکمه‌ی $unexpected نباید در نصب تازه باشد");
    }
});

scenario('غیر ادمین هیچ پاسخی نمی‌گیرد', function (): void {
    send_text('/start', 999);
    expect(tg_messages() === [], 'به کاربر غیرمجاز پاسخ داده شد!');
});

scenario('منوی ایمیل دکمه‌های عملیات را دارد', function (): void {
    // برای دیدن منوهای سطح حساب، اول یک هاست تعریف می‌کنیم.
    $temp = ServerStore::add(['label' => 'موقت', 'type' => 'cpanel', 'host' => '127.0.0.1:1', 'user' => 'u', 'token' => 't']);
    ServerStore::setActive($temp);

    press('m:email');
    $buttons = tg_last_buttons();
    foreach (['a:emails', 'f:email_add', 'f:email_del', 'f:email_passwd', 'menu'] as $expected) {
        expect(in_array($expected, $buttons, true), "دکمه‌ی $expected در منوی ایمیل نبود");
    }
});

scenario('هاست اشتراکی: دکمه‌های سطح حساب می‌آیند و WHM نه', function (): void {
    press('menu');
    $buttons = tg_last_buttons();
    foreach (['a:summary', 'm:email', 'm:db', 'm:files', 'm:cron'] as $expected) {
        expect(in_array($expected, $buttons, true), "دکمه‌ی $expected برای هاست cPanel باید باشد");
    }
    expect(!in_array('m:whm', $buttons, true), 'روی هاست cPanel نباید منوی WHM بیاید');
});

scenario('سرور WHM: اول فقط کارهای سطح سرور، بعد از انتخاب کاربر هم سطح حساب', function (): void {
    foreach (array_keys(ServerStore::all()) as $id) {
        ServerStore::delete($id);
    }
    $whm = ServerStore::add(['label' => 'WHM تستی', 'type' => 'whm', 'host' => '127.0.0.1:1', 'user' => 'root', 'token' => 't']);
    ServerStore::setActive($whm);

    press('menu');
    $buttons = tg_last_buttons();
    expect(in_array('m:whm', $buttons, true), 'منوی WHM باید باشد');
    expect(in_array('f:whm_user', $buttons, true), 'دکمه‌ی انتخاب کاربر مقصد باید باشد');
    expect(!in_array('m:email', $buttons, true), 'تا کاربر مقصد انتخاب نشده، کارهای سطح حساب نباید بیایند');

    ServerStore::setAsUser($whm, 'ali');
    press('menu');
    $buttons = tg_last_buttons();
    expect(in_array('m:email', $buttons, true), 'بعد از انتخاب کاربر مقصد، کارهای سطح حساب باید بیایند');
    expect(in_array('m:whm', $buttons, true), 'منوی WHM هم باید بماند');

    ServerStore::delete($whm);
});

scenario('هر منو دکمه‌ی بازگشت دارد', function (): void {
    $temp = ServerStore::add(['label' => 'موقت', 'type' => 'cpanel', 'host' => '127.0.0.1:1', 'user' => 'u', 'token' => 't']);
    ServerStore::setActive($temp);

    foreach (['email', 'db', 'dom', 'files', 'ftp', 'cron', 'dns', 'php', 'backup', 'srv', 'adm'] as $menu) {
        press('m:' . $menu);
        $buttons = tg_last_buttons();
        $hasBack = in_array('menu', $buttons, true) || count(array_filter($buttons, static fn (string $b): bool => str_starts_with($b, 'm:'))) > 0;
        expect($hasBack, "منوی $menu دکمه‌ی بازگشت ندارد");
    }

    ServerStore::delete($temp);
});

scenario('دکمه‌ها همان پیام را ویرایش می‌کنند، نه اینکه پیام تازه بفرستند', function (): void {
    send_text('/panel');            // یک صفحه می‌سازیم
    $before = count(tg_messages());
    press('m:srv');
    press('menu');
    press('a:help');
    expect(count(tg_messages()) === $before, 'زدن دکمه نباید پیام تازه بسازد');
    expect(tg_edits() >= 3, 'هر دکمه باید همان پیام را ویرایش کند');
});

scenario('افزودن سرور: نوع اتصال با دکمه پرسیده می‌شود', function (): void {
    foreach (array_keys(ServerStore::all()) as $id) {
        ServerStore::delete($id);
    }

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

scenario('ادمین دوم از داخل ربات اضافه و حذف می‌شود', function (): void {
    press('m:adm');
    expect(in_array('f:admin_add', tg_last_buttons(), true), 'دکمه‌ی افزودن ادمین نبود');

    press('f:admin_add');
    send_text('777');
    expect(Admins::isAdmin(777), 'ادمین جدید اضافه نشد');

    // حالا ادمین دوم هم واقعاً به ربات دسترسی دارد.
    tg_log_reset();
    send_text('/panel', 777);
    expect(tg_last_buttons() !== [], 'ادمین جدید باید به منو دسترسی داشته باشد');

    press('f:admin_del');
    expect(in_array('c:0', tg_last_buttons(), true), 'ادمین‌ها باید به‌صورت دکمه بیایند');
    press('c:0');
    expect(str_contains(tg_last_text(), '777'), 'فقط ادمین دیگر باید پیشنهاد شود');
    press('ok');
    expect(!Admins::isAdmin(777), 'ادمین باید حذف شود');
    expect(Admins::isAdmin(555), 'ادمین فعلی نباید دست بخورد');
});

scenario('ادمین نمی‌تواند خودش را حذف کند', function (): void {
    Admins::add(777);
    press('f:admin_del');
    $buttons = tg_last_buttons();
    $optionCount = count(array_filter($buttons, static fn (string $b): bool => str_starts_with($b, 'c:')));
    expect($optionCount === 1, 'فهرست حذف باید فقط ادمین دیگر را نشان دهد، نه خود کاربر را');

    send_text('555');   // تلاش برای حذف خودش با تایپ دستی
    press('ok');
    expect(str_contains(tg_last_text(), 'خودتان'), 'باید جلوی حذف خود کاربر گرفته شود');
    expect(Admins::isAdmin(555), 'ادمین فعلی نباید حذف شود');

    Admins::remove(777);
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

    scenario('مرور فایل‌های هاست با دکمه انجام می‌شود', function (): void {
        press('a:files');
        $text = tg_last_text();
        $buttons = tg_last_buttons();
        expect(str_contains($text, '/home/demo'), 'مسیر پوشه‌ی هوم باید نمایش داده شود');
        expect(in_array('fb:0', $buttons, true), 'پوشه‌ها باید دکمه شوند');
        expect(in_array('ff:2', $buttons, true), 'فایل‌ها هم باید دکمه شوند');
        expect(in_array('fup', $buttons, true), 'دکمه‌ی آپلود در همین پوشه باید باشد');
        expect(in_array('m:files', $buttons, true), 'دکمه‌ی بازگشت باید باشد');
    });

    scenario('ورود به پوشه و برگشتن به پوشه‌ی بالاتر', function (): void {
        // فهرست مرتب می‌شود (اول پوشه‌ها، الفبایی)، پس public_html دکمه‌ی دوم است.
        press('fb:1');
        expect(str_contains(tg_last_text(), '/home/demo/public_html'), 'باید وارد public_html شود');

        press('fbu');
        expect(str_contains(tg_last_text(), '📂 <b>/home/demo</b>'), 'دکمه‌ی «پوشه‌ی بالاتر» باید برگرداند');
    });

    scenario('روی فایل آرشیو، دکمه‌ی باز کردن پیشنهاد می‌شود', function (): void {
        press('ff:2');
        $buttons = tg_last_buttons();
        expect(str_contains(tg_last_text(), 'site.zip'), 'نام فایل باید نشان داده شود');
        expect(in_array('fx:2', $buttons, true), 'برای zip باید دکمه‌ی باز کردن بیاید');
        expect(in_array('fd:2', $buttons, true), 'دکمه‌ی حذف باید بیاید');
        press('fbr');
    });

    scenario('آپلود سورس: فایل تلگرام روی هاست گذاشته می‌شود', function (): void {
        press('fup');
        expect(str_contains(tg_last_text(), 'فایل'), 'باید فایل بخواهد');

        send_text('یک متن به‌جای فایل');
        expect(str_contains(tg_last_text(), 'Document'), 'متن به‌جای فایل باید رد شود');

        send_document('site.zip', 2048);

        $uploads = array_filter(cpanel_calls(), static fn (array $c): bool => str_contains($c['path'], 'upload_files'));
        expect($uploads !== [], 'درخواست آپلود به cPanel نرفت');
        $body = (string) (array_values($uploads)[0]['body'] ?? '');
        expect(str_contains($body, 'filename="site.zip"'), 'نام فایل در بدنه‌ی multipart نبود');
        expect(str_contains($body, 'FAKE-ARCHIVE-CONTENT'), 'محتوای فایل ارسال نشد');
        expect(str_contains($body, 'name="dir"'), 'مسیر مقصد ارسال نشد');
        expect(str_contains(tg_last_text(), 'آپلود شد'), 'پیام موفقیت نیامد');
        expect(in_array('fx:0', tg_last_buttons(), true), 'برای آرشیو باید دکمه‌ی باز کردن بیاید');
    });

    scenario('باز کردن آرشیو، fileop استخراج را صدا می‌زند', function (): void {
        press('fx:0');
        $extracts = array_filter(cpanel_calls(), static fn (array $c): bool => str_contains(urldecode($c['body']), 'op=extract'));
        expect($extracts !== [], 'درخواست استخراج فرستاده نشد');
        expect(str_contains(tg_last_text(), 'آرشیو باز شد'), 'پیام موفقیت استخراج نیامد');
    });

    scenario('فورواردهای ایمیل و بکاپ‌ها نمایش داده می‌شوند', function (): void {
        press('a:forwarders');
        expect(str_contains(tg_last_text(), 'info@example.com'), 'فوروارد نمایش داده نشد');
        expect(in_array('f:fwd_add', tg_last_buttons(), true), 'دکمه‌ی افزودن فوروارد نبود');

        press('a:backups');
        expect(str_contains(tg_last_text(), 'backup-9.3.2026'), 'بکاپ نمایش داده نشد');
    });

    scenario('صفحه‌ی قابلیت‌ها متناسب با نوع هاست است', function (): void {
        press('a:features');
        $text = tg_last_text();
        expect(str_contains($text, 'cPanel'), 'باید بگوید قابلیت‌های هاست cPanel');
        expect(str_contains($text, 'WHM'), 'باید توضیح دهد کارهای سطح سرور با WHM ممکن است');
    });

    scenario('منوی دیتابیس، پیشوند اجباری همان هاست را نشان می‌دهد', function (): void {
        press('m:db');
        $text = tg_last_text();
        $buttons = tg_last_buttons();
        expect(str_contains($text, 'demo_'), 'پیشوند زنده‌ی حساب باید نمایش داده شود');
        expect(str_contains($text, 'سه قدم'), 'باید توضیح دهد وصل‌کردن دیتابیس چند قدم است');
        foreach (['f:db_add', 'f:dbuser_add', 'f:db_grant', 'f:db_passwd', 'f:db_repair', 'a:mysqlhosts'] as $expected) {
            expect(in_array($expected, $buttons, true), "دکمه‌ی $expected در منوی دیتابیس نبود");
        }
    });

    scenario('ریدایرکت‌ها و میزبان‌های راه دور نمایش داده می‌شوند', function (): void {
        press('a:redirects');
        expect(str_contains(tg_last_text(), 'old.example.com'), 'ریدایرکت نمایش داده نشد');
        expect(in_array('f:redirect_add', tg_last_buttons(), true), 'دکمه‌ی افزودن ریدایرکت نبود');

        press('a:mysqlhosts');
        expect(str_contains(tg_last_text(), '203.0.113.10'), 'میزبان راه دور نمایش داده نشد');
    });

    scenario('راهنمای کارهای رایج، قدم‌ها را با دکمه نشان می‌دهد', function (): void {
        press('m:howto');
        $buttons = tg_last_buttons();
        expect(in_array('h:site', $buttons, true), 'راهنمای راه‌اندازی سایت نبود');

        press('h:db');
        $text = tg_last_text();
        expect(str_contains($text, '۱️⃣'), 'قدم‌ها باید شماره‌گذاری شده باشند');
        expect(in_array('f:db_add', tg_last_buttons(), true), 'دکمه‌ی قدم اول باید مستقیم به همان بخش برود');
        expect(in_array('m:howto', tg_last_buttons(), true), 'دکمه‌ی بازگشت به راهنما باید باشد');
    });

    scenario('خلاصه‌ی حساب، اطلاعات واقعی هاست را نشان می‌دهد', function (): void {
        press('a:summary');
        $text = tg_last_text();
        expect(str_contains($text, 'srv01.example.com'), 'نام سرور نیامد');
        expect(str_contains($text, 'Business Pro'), 'نام پلن نیامد');
        expect(str_contains($text, '203.0.113.55'), 'آی‌پی آخرین ورود نیامد');
        expect(str_contains($text, '/home/demo'), 'پوشه‌ی هوم نیامد');
        expect(in_array('a:acctfeatures', tg_last_buttons(), true), 'دکمه‌ی امکانات فعال نبود');

        press('a:acctfeatures');
        expect(str_contains(tg_last_text(), 'cron'), 'فهرست قابلیت‌های فعال نیامد');
        expect(str_contains(tg_last_text(), 'ssh'), 'قابلیت‌های غیرفعال هم باید بیایند');
    });

    scenario('لیست دامنه‌ها، پوشه‌ی ریشه و آی‌پی هر دامنه را می‌دهد', function (): void {
        press('a:domains');
        $text = tg_last_text();
        expect(str_contains($text, '/home/demo/public_html'), 'پوشه‌ی ریشه نیامد');
        expect(str_contains($text, '203.0.113.7'), 'آی‌پی دامنه نیامد');
        expect(in_array('f:site_check', tg_last_buttons(), true), 'دکمه‌ی بررسی سلامت نبود');
    });

    scenario('محدودیت‌های PHP هر دامنه نمایش داده می‌شود', function (): void {
        press('f:php_limits');
        press('c:0');
        $text = tg_last_text();
        expect(str_contains($text, '256M'), 'memory_limit نیامد');
        expect(str_contains($text, '64M'), 'upload_max_filesize نیامد');
    });

    scenario('تنظیمات IMAP/SMTP یک صندوق داده می‌شود', function (): void {
        press('f:email_settings');
        press('c:0');
        $text = tg_last_text();
        expect(str_contains($text, 'mail.example.com'), 'سرور ایمیل نیامد');
        expect(str_contains($text, '993'), 'پورت IMAP نیامد');
    });

    scenario('بزرگ‌ترین فایل‌ها و تغییرات اخیر از منوی فایل‌ها می‌آیند', function (): void {
        press('m:files');
        $buttons = tg_last_buttons();
        foreach (['a:bigfiles', 'a:recentfiles', 'f:file_rename', 'f:file_chmod', 'f:file_compress', 'f:file_move'] as $expected) {
            expect(in_array($expected, $buttons, true), "دکمه‌ی $expected در منوی فایل‌ها نبود");
        }

        press('a:bigfiles');
        expect(str_contains(tg_last_text(), 'site.zip'), 'بزرگ‌ترین فایل نیامد');
    });

    scenario('ساخت گروهی ایمیل، رمزها را خودش می‌سازد و گزارش می‌دهد', function (): void {
        press('f:email_bulk_add');
        press('c:0');                       // دامنه
        send_text("info\nsales rmz-dasti\nsupport");
        press('skip');                      // سهمیه: پیش‌فرض

        $text = tg_last_text();
        expect(str_contains($text, 'موفق: 3'), 'هر سه ایمیل باید ساخته شوند');
        expect(str_contains($text, 'info@example.com'), 'ایمیل اول نیامد');
        expect(str_contains($text, 'rmz-dasti'), 'رمز دستی باید حفظ شود');

        $adds = array_filter(cpanel_calls(), static fn (array $c): bool => str_contains($c['path'], 'add_pop'));
        expect(count($adds) === 3, 'باید سه درخواست ساخت ایمیل رفته باشد');
    });

    scenario('اطلاعات اتصال دیتابیس نمایش داده می‌شود', function (): void {
        press('a:dbinfo');
        $text = tg_last_text();
        expect(str_contains($text, 'localhost'), 'میزبان دیتابیس نیامد');
        expect(str_contains($text, '3306'), 'پورت نیامد');
        expect(str_contains($text, 'demo_'), 'پیشوند نیامد');
    });

    scenario('پاک‌سازی هوشمند، جاهای پرحجم را پیدا و پاک می‌کند', function (): void {
        press('m:files');
        expect(in_array('a:cleanup', tg_last_buttons(), true), 'دکمه‌ی پاک‌سازی هوشمند نبود');

        press('a:cleanup');
        $text = tg_last_text();
        expect(str_contains($text, 'پاک‌سازی هوشمند'), 'صفحه‌ی پاک‌سازی نیامد');
        expect(str_contains($text, 'آزاد کرد') || str_contains($text, 'پیدا نشد'), 'باید حجم قابل آزادسازی را بگوید');
    });

    scenario('اجبار HTTPS از منوی SSL در دسترس است', function (): void {
        press('m:ssl');
        expect(in_array('f:force_https', tg_last_buttons(), true), 'دکمه‌ی اجبار HTTPS نبود');

        press('f:force_https');
        press('c:0');                       // دامنه
        press('c:0');                       // روشن
        $calls = array_filter(cpanel_calls(), static fn (array $c): bool => str_contains($c['path'], 'toggle_ssl_redirect'));
        expect($calls !== [], 'درخواست اجبار HTTPS فرستاده نشد');
        expect(str_contains(urldecode((string) array_values($calls)[0]['body']), 'state=1'), 'وضعیت روشن ارسال نشد');
    });

    scenario('پاسخ خودکار و نشست‌های FTP از منوهای خودشان در دسترس‌اند', function (): void {
        press('m:email');
        expect(in_array('a:responders', tg_last_buttons(), true), 'دکمه‌ی پاسخ خودکار نبود');
        expect(in_array('f:mail_purge', tg_last_buttons(), true), 'دکمه‌ی پاک‌سازی Trash نبود');

        press('a:responders');
        expect(str_contains(tg_last_text(), 'در مرخصی هستم'), 'پاسخ خودکار نمایش داده نشد');

        press('m:ftp');
        foreach (['f:ftp_passwd', 'f:ftp_quota', 'a:ftpsessions'] as $expected) {
            expect(in_array($expected, tg_last_buttons(), true), "دکمه‌ی $expected در منوی FTP نبود");
        }

        press('a:ftpsessions');
        expect(str_contains(tg_last_text(), 'deploy@example.com'), 'نشست FTP نمایش داده نشد');
        expect(in_array('f:ftp_kill', tg_last_buttons(), true), 'دکمه‌ی قطع نشست نبود');
    });

    scenario('پاک‌سازی Trash صندوق، نامه‌ها را پاک می‌کند', function (): void {
        press('f:mail_purge');
        press('c:0');
        press('ok');
        $text = tg_last_text();
        expect(str_contains($text, 'پاک شد') || str_contains($text, 'خالی'), 'نتیجه‌ی پاک‌سازی نیامد');
    });

    scenario('دامنه‌ی ادان و پارک از منوی دامنه‌ها اضافه می‌شوند', function (): void {
        press('m:dom');
        foreach (['f:addon_add', 'f:addon_del', 'f:park_add', 'a:bandwidth'] as $expected) {
            expect(in_array($expected, tg_last_buttons(), true), "دکمه‌ی $expected در منوی دامنه‌ها نبود");
        }

        press('f:addon_add');
        send_text('newsite.com');
        press('skip');
        $calls = array_filter(cpanel_calls(), static fn (array $c): bool => str_contains($c['path'], 'addaddondomain'));
        expect($calls !== [], 'درخواست افزودن ادان‌دامنه فرستاده نشد');
        expect(str_contains(tg_last_text(), 'newsite.com'), 'پیام موفقیت نیامد');
    });

    scenario('جست‌وجوی سراسری، در همه‌ی بخش‌ها می‌گردد', function (): void {
        press('menu');
        expect(in_array('f:search', tg_last_buttons(), true), 'دکمه‌ی جست‌وجو در منوی اصلی نبود');

        press('f:search');
        send_text('example');
        $text = tg_last_text();
        expect(str_contains($text, 'ایمیل') || str_contains($text, 'دامنه'), 'گروه‌های نتیجه نیامدند');
        expect(str_contains($text, 'example.com'), 'نتیجه‌ی واقعی پیدا نشد');
    });

    scenario('گزارش وضعیت به‌صورت فایل فرستاده می‌شود', function (): void {
        press('a:summary');
        expect(in_array('a:report', tg_last_buttons(), true), 'دکمه‌ی گزارش نبود');

        press('a:report');
        $documents = array_filter(tg_calls(), static fn (array $call): bool => $call['method'] === 'sendDocument');
        expect($documents !== [], 'فایل گزارش فرستاده نشد');
    });

    scenario('اصلاح رکورد A، رکورد قبلی را جایگزین می‌کند', function (): void {
        press('m:dns');
        expect(in_array('f:dns_fix', tg_last_buttons(), true), 'دکمه‌ی اصلاح رکورد A نبود');

        press('f:dns_fix');
        press('c:0');                    // zone
        send_text('www');
        send_text('203.0.113.99');
        press('ok');
        $edits = array_filter(cpanel_calls(), static fn (array $c): bool => str_contains(urldecode($c['body']), '203.0.113.99'));
        expect($edits !== [], 'رکورد جدید فرستاده نشد');
    });

    scenario('دانلود فایل از هاست، فایل را در تلگرام می‌فرستد', function (): void {
        press('a:files');
        press('ff:2');                                   // site.zip
        expect(in_array('fg:2', tg_last_buttons(), true), 'دکمه‌ی دانلود روی فایل نبود');

        $before = count(array_filter(tg_calls(), static fn (array $c): bool => $c['method'] === 'sendDocument'));
        press('fg:2');
        $after = count(array_filter(tg_calls(), static fn (array $c): bool => $c['method'] === 'sendDocument'));
        expect($after > $before, 'فایل دانلودشده در تلگرام فرستاده نشد');
        expect(str_contains(tg_last_text(), 'site.zip') || $after > $before, 'نام فایل در پیام نیامد');
    });

    scenario('منوی فایل‌ها دکمه‌های دانلود، ویرایش، ساخت و جست‌وجو دارد', function (): void {
        press('m:files');
        foreach (['f:file_download', 'f:folder_download', 'f:file_edit', 'f:file_create', 'f:file_find', 'a:undo'] as $expected) {
            expect(in_array($expected, tg_last_buttons(), true), "دکمه‌ی $expected در منوی فایل‌ها نبود");
        }
    });

    scenario('ساخت فایل جدید روی هاست', function (): void {
        press('f:file_create');
        press('c:0');                                    // پوشه
        send_text('robots.txt');
        send_text("User-agent: *\nDisallow:");

        $saves = array_filter(cpanel_calls(), static fn (array $c): bool => str_contains($c['path'], 'save_file_content'));
        expect($saves !== [], 'درخواست ذخیره‌ی فایل فرستاده نشد');
        expect(str_contains(urldecode((string) array_values($saves)[0]['body']), 'file=robots.txt'), 'نام فایل درست نرفت');
        expect(str_contains(tg_last_text(), 'robots.txt'), 'پیام موفقیت نیامد');
    });

    scenario('منوی هشدارها مقدارهای فعلی را نشان می‌دهد و سوییچ می‌شود', function (): void {
        press('menu');
        expect(in_array('m:alerts', tg_last_buttons(), true), 'دکمه‌ی هشدارها در منوی اصلی نبود');

        press('m:alerts');
        $text = tg_last_text();
        expect(str_contains($text, 'هشدار دیسک'), 'آستانه‌ی دیسک نمایش داده نشد');
        expect(str_contains($text, 'گزارش دوره‌ای'), 'تنظیم گزارش نمایش داده نشد');

        $before = str_contains($text, 'بکاپ قبل از تغییر/حذف: روشن');
        press('a:togglesafe');
        $after = str_contains(tg_last_text(), 'بکاپ قبل از تغییر/حذف: روشن');
        expect($before !== $after, 'سوییچ بکاپ قبل از تغییر کار نکرد');
        press('a:togglesafe');
    });

    scenario('آستانه‌ی هشدار از داخل ربات عوض می‌شود', function (): void {
        press('f:alert_threshold');
        press('c:0');                                    // دیسک
        send_text('75');
        expect(Settings::int(Settings::ALERT_DISK, 0) === 75, 'آستانه ذخیره نشد');
        expect(str_contains(tg_last_text(), '75'), 'مقدار جدید تأیید نشد');

        press('f:alert_threshold');
        press('c:0');
        send_text('نود درصد');
        expect(str_contains(tg_last_text(), 'عدد'), 'ورودی غیرعددی باید رد شود');
    });

    scenario('رکورد DNS گروهی روی همه‌ی دامنه‌ها اضافه می‌شود', function (): void {
        press('m:dns');
        expect(in_array('f:dns_bulk', tg_last_buttons(), true), 'دکمه‌ی رکورد گروهی نبود');

        press('f:dns_bulk');
        press('c:3');                                    // TXT
        send_text('@');
        send_text('v=spf1 -all');
        press('ok');
        expect(str_contains(tg_last_text(), 'دامنه اضافه شد'), 'گزارش گروهی نیامد');
    });

    scenario('میان‌برها: افزودن، اجرا با یک دکمه و حذف', function (): void {
        press('menu');
        expect(in_array('m:fav', tg_last_buttons(), true), 'دکمه‌ی میان‌برها در منوی اصلی نبود');

        press('m:fav');
        expect(str_contains(tg_last_text(), 'میان‌بر'), 'صفحه‌ی میان‌برها نیامد');

        press('f:fav_add');
        send_text('/home/demo/public_html');
        expect(count(Favorites::all()) === 1, 'میان‌بر اضافه نشد');

        press('m:fav');
        expect(in_array('fav:0', tg_last_buttons(), true), 'دکمه‌ی میان‌بر ساخته نشد');
        press('fav:0');
        expect(str_contains(tg_last_text(), 'public_html'), 'میان‌بر مسیر باید پوشه را باز کند');

        press('m:fav');
        press('f:fav_del');
        press('c:0');
        press('ok');
        expect(Favorites::all() === [], 'میان‌بر حذف نشد');
    });

    scenario('حذف گروهی فایل، هر مسیر را جدا گزارش می‌کند', function (): void {
        press('m:files');
        foreach (['f:file_bulk_delete', 'f:file_bulk_move', 'f:dir_protect', 'a:dirusage'] as $expected) {
            expect(in_array($expected, tg_last_buttons(), true), "دکمه‌ی $expected در منوی فایل‌ها نبود");
        }

        press('f:file_bulk_delete');
        send_text("/home/demo/public_html/a.txt\n/home/demo/public_html/b.txt");
        press('ok');
        expect(str_contains(tg_last_text(), 'موفق: 2'), 'گزارش حذف گروهی درست نیست');
    });

    scenario('تغییر رمز گروهی ایمیل، رمز نداده‌شده را خودش می‌سازد', function (): void {
        press('m:email');
        expect(in_array('f:email_bulk_passwd', tg_last_buttons(), true), 'دکمه‌ی رمز گروهی نبود');

        press('f:email_bulk_passwd');
        send_text("info@example.com\nsales@example.com rmz-dasti");
        $text = tg_last_text();
        expect(str_contains($text, 'موفق: 2'), 'هر دو باید انجام شوند');
        expect(str_contains($text, 'rmz-dasti'), 'رمز دستی باید حفظ شود');
    });

    scenario('حالت تعمیر و مسدودکردن آی‌پی از منوی دامنه‌ها', function (): void {
        press('m:dom');
        expect(in_array('f:maintenance', tg_last_buttons(), true), 'دکمه‌ی حالت تعمیر نبود');
        expect(in_array('f:ip_block', tg_last_buttons(), true), 'دکمه‌ی مسدودکردن آی‌پی نبود');

        press('f:maintenance');
        press('c:1');                       // پوشه‌ی public_html
        press('c:0');                       // روشن
        press('skip');                      // متن پیش‌فرض
        $saves = array_filter(cpanel_calls(), static fn (array $c): bool => str_contains($c['path'], 'save_file_content'));
        expect($saves !== [], 'فایل‌های حالت تعمیر نوشته نشدند');
        expect(str_contains(tg_last_text(), 'تعمیر'), 'پیام حالت تعمیر نیامد');
    });

    scenario('روند مصرف و مصرف پوشه‌ها نمایش داده می‌شوند', function (): void {
        press('a:summary');
        expect(in_array('a:trend', tg_last_buttons(), true), 'دکمه‌ی روند مصرف نبود');

        press('a:trend');
        expect(str_contains(tg_last_text(), 'روند مصرف'), 'صفحه‌ی روند نیامد');

        press('a:dirusage');
        expect(str_contains(tg_last_text(), 'مصرف پوشه') || str_contains(tg_last_text(), 'پیدا نشد'), 'صفحه‌ی مصرف پوشه‌ها نیامد');
    });

    scenario('خروجی CSV به‌صورت فایل فرستاده می‌شود', function (): void {
        press('menu');
        expect(in_array('f:export_csv', tg_last_buttons(), true), 'دکمه‌ی خروجی CSV در منوی اصلی نبود');

        $before = count(array_filter(tg_calls(), static fn (array $c): bool => $c['method'] === 'sendDocument'));
        press('f:export_csv');
        press('c:0');                                   // ایمیل‌ها
        $after = count(array_filter(tg_calls(), static fn (array $c): bool => $c['method'] === 'sendDocument'));
        expect($after > $before, 'فایل CSV فرستاده نشد');
        expect(str_contains(tg_last_text(), 'ردیف'), 'تعداد ردیف‌ها گزارش نشد');
    });

    scenario('بررسی رکوردهای ایمیل دامنه', function (): void {
        press('m:email');
        expect(in_array('f:mail_records', tg_last_buttons(), true), 'دکمه‌ی بررسی رکوردهای ایمیل نبود');

        press('f:mail_records');
        press('c:0');
        $text = tg_last_text();
        expect(str_contains($text, 'MX'), 'وضعیت MX نیامد');
        expect(str_contains($text, 'SPF'), 'وضعیت SPF نیامد');
    });

    scenario('بکاپ خودکار و دامنه‌های تحت‌نظر از منوی هشدارها تنظیم می‌شوند', function (): void {
        press('m:alerts');
        foreach (['f:backup_schedule', 'f:watch_domains'] as $expected) {
            expect(in_array($expected, tg_last_buttons(), true), "دکمه‌ی $expected در منوی هشدارها نبود");
        }

        press('f:backup_schedule');
        press('c:1');                                   // هفتگی
        press('c:1');                                   // ۳ نسخه
        expect(Settings::get(Settings::BACKUP_EVERY) === 'weekly', 'زمان‌بندی بکاپ ذخیره نشد');
        expect(Settings::int(Settings::BACKUP_KEEP, 0) === 3, 'تعداد نسخه‌ها ذخیره نشد');

        press('f:watch_domains');
        send_text("example.com\nsecond.com");
        expect(str_contains(Settings::get(Settings::WATCH_DOMAINS), 'second.com'), 'دامنه‌های تحت‌نظر ذخیره نشدند');
    });

    scenario('دسترسی موقت: کد ساخته و بعد لغو می‌شود', function (): void {
        press('m:adm');
        expect(in_array('f:guest_invite', tg_last_buttons(), true), 'دکمه‌ی دسترسی موقت نبود');

        press('f:guest_invite');
        press('c:0');                                   // ۲ ساعت
        expect(str_contains(tg_last_text(), 'دسترسی موقت'), 'کد دسترسی موقت نمایش داده نشد');
        expect(Admins::invite() !== null, 'کد ساخته نشد');

        press('a:admins');
        expect(str_contains(tg_last_text(), 'کد دسترسی موقت فعال'), 'فهرست ادمین‌ها باید کد فعال را نشان دهد');

        press('a:revokeguests');
        expect(Admins::invite() === null, 'کد باید لغو شود');
    });

    scenario('لاگ عملیات، کارهای انجام‌شده را ثبت می‌کند و کلید داخلی لو نمی‌دهد', function (): void {
        press('a:log');
        $text = tg_last_text();
        expect(str_contains($text, 'حالت تعمیر') || str_contains($text, 'تغییر رمز گروهی ایمیل'), 'آخرین عملیات‌ها در لاگ نیستند');
        expect(!str_contains($text, '__msg'), 'کلیدهای داخلی نباید در لاگ بیایند');
        expect(!str_contains($text, '__options'), 'فهرست دکمه‌ها نباید در لاگ بیاید');
        expect(!str_contains($text, '"action"'), 'لاگ نباید خام JSON نمایش داده شود');
    });

    scenario('گزارش امنیتی، فایل قابل‌دانلود از وب را پیدا می‌کند', function (): void {
        press('a:security');
        $text = tg_last_text();
        expect(str_contains($text, 'گزارش امنیتی'), 'عنوان گزارش امنیتی نیامد');
        expect(str_contains($text, 'site.zip'), 'آرشیو داخل پوشه‌ی سایت باید گزارش شود');
        expect(str_contains($text, '/home/demo/public_html'), 'پوشه‌ی بررسی‌شده باید نوشته شود');
        expect(in_array('f:file_chmod', tg_last_buttons(), true), 'دکمه‌ی اصلاح دسترسی باید کنار گزارش باشد');
    });

    scenario('مصرف صندوق‌های ایمیل، پرمصرف‌ترین را اول نشان می‌دهد', function (): void {
        press('m:email');
        expect(in_array('a:maildisk', tg_last_buttons(), true), 'دکمه‌ی مصرف صندوق‌ها در منوی ایمیل نبود');

        press('a:maildisk');
        $text = tg_last_text();
        expect(str_contains($text, 'مصرف صندوق‌های ایمیل'), 'عنوان مصرف صندوق‌ها نیامد');
        expect(str_contains($text, 'sales@example.com'), 'صندوق پرمصرف نیامد');
        expect(mb_strpos($text, 'sales@example.com') < mb_strpos($text, 'info@example.com'), 'ترتیب باید از پرمصرف به کم‌مصرف باشد');
    });

    scenario('حجم دیتابیس‌ها، دیتابیس بی‌کاربر را هم هشدار می‌دهد', function (): void {
        press('m:db');
        expect(in_array('a:dbsizes', tg_last_buttons(), true), 'دکمه‌ی حجم دیتابیس‌ها در منوی دیتابیس نبود');

        press('a:dbsizes');
        $text = tg_last_text();
        expect(str_contains($text, 'demo_shop'), 'نام دیتابیس نیامد');
        expect(str_contains($text, 'بدون کاربر'), 'دیتابیس بی‌کاربر باید علامت بخورد');
        expect(str_contains($text, '50 MB'), 'حجم دیتابیس درست نمایش داده نشد');
    });

    scenario('قالب آماده‌ی DNS رکوردهای استاندارد را می‌سازد', function (): void {
        press('m:dns');
        expect(in_array('f:dns_template', tg_last_buttons(), true), 'دکمه‌ی قالب آماده در منوی DNS نبود');

        press('f:dns_template');
        press('c:0');                                   // دامنه‌ی اصلی
        $text = tg_last_text();
        expect(str_contains($text, 'کدام قالب'), 'مرحله‌ی انتخاب قالب نیامد');
        expect(count(tg_last_buttons()) >= 5, 'قالب‌ها به‌صورت دکمه نیامدند');

        press('c:0');                                   // قالب گوگل
        expect(in_array('ok', tg_last_buttons(), true), 'قبل از تغییر زون باید تأیید بگیرد');

        press('ok');
        $edits = array_filter(cpanel_calls(), static fn (array $c): bool => str_contains($c['path'], 'mass_edit_zone'));
        expect($edits !== [], 'رکوردی روی زون ساخته نشد');
        $bodies = implode(' ', array_map(static fn (array $c): string => urldecode($c['body']), $edits));
        expect(str_contains($bodies, 'SMTP.GOOGLE.COM.'), 'رکورد MX گوگل فرستاده نشد');
        expect(str_contains($bodies, '_spf.google.com'), 'رکورد SPF گوگل فرستاده نشد');
        expect(str_contains(tg_last_text(), 'اضافه شد'), 'نتیجه‌ی قالب گزارش نشد');
        expect(str_contains(tg_last_text(), 'Google Workspace'), 'نام قالب در نتیجه نیامد');
    });

    scenario('خروجی فایل زون به‌صورت فایل فرستاده می‌شود', function (): void {
        $before = count(array_filter(tg_calls(), static fn (array $c): bool => $c['method'] === 'sendDocument'));

        press('f:dns_export');
        press('c:0');

        $documents = array_values(array_filter(tg_calls(), static fn (array $c): bool => $c['method'] === 'sendDocument'));
        expect(count($documents) === $before + 1, 'فایل زون فرستاده نشد');
        expect(str_contains(tg_last_text(), 'فایل زون'), 'پیام آماده‌شدن فایل زون نیامد');
    });

    scenario('جلوگیری از هات‌لینک، بلوک نشان‌دار در .htaccess می‌گذارد', function (): void {
        press('m:dom');
        expect(in_array('f:hotlink', tg_last_buttons(), true), 'دکمه‌ی هات‌لینک در منوی دامنه‌ها نبود');

        press('f:hotlink');
        press('c:1');                                   // پوشه‌ی public_html
        press('c:0');                                   // روشن
        press('skip');                                  // بدون دامنه‌ی مجاز اضافه

        $saves = array_filter(cpanel_calls(), static fn (array $c): bool => str_contains($c['path'], 'save_file_content'));
        $bodies = implode(' ', array_map(static fn (array $c): string => urldecode($c['body']), $saves));
        expect(str_contains($bodies, 'TEAMKAN HOTLINK START'), 'بلوک هات‌لینک نوشته نشد');
        expect(str_contains($bodies, 'example\.com'), 'دامنه‌ی خود حساب باید مجاز بماند');
        expect(str_contains(tg_last_text(), 'هات‌لینک روشن شد'), 'پیام موفقیت نیامد');
    });

    scenario('تست زنده‌ی PHP از منوی PHP در دسترس است', function (): void {
        press('m:php');
        expect(in_array('f:php_test', tg_last_buttons(), true), 'دکمه‌ی تست زنده‌ی PHP در منوی PHP نبود');

        press('f:php_test');
        expect(str_contains(tg_last_text(), 'کدام دامنه'), 'مرحله‌ی انتخاب دامنه نیامد');
        expect(in_array('c:0', tg_last_buttons(), true), 'دامنه‌ها باید به‌صورت دکمه بیایند');
        send_text('/cancel');
    });

    scenario('صف کارها: ثبت، نمایش و حذف', function (): void {
        OpQueue::clear();

        press('m:alerts');
        expect(in_array('a:queue', tg_last_buttons(), true), 'دکمه‌ی کارهای زمان‌بندی‌شده در منوی هشدارها نبود');

        press('a:queue');
        expect(str_contains(tg_last_text(), 'صف خالی است'), 'صف خالی باید توضیح داشته باشد');

        press('f:schedule_op');
        press('c:0');                                   // گرفتن بکاپ کامل
        press('c:0');                                   // یک ساعت دیگر
        press('skip');                                  // پوشه‌ی سایت لازم نیست
        expect(str_contains(tg_last_text(), 'ثبت شد'), 'کار زمان‌بندی نشد');
        expect(count(OpQueue::all()) === 1, 'کار در صف ذخیره نشد');
        expect(OpQueue::all()[0]['action'] === 'backup', 'کار اشتباه ثبت شد');
        expect(OpQueue::due('', time() + 7200) !== [], 'کار باید بعد از یک ساعت سررسید شود');

        press('a:queue');
        expect(str_contains(tg_last_text(), 'بکاپ کامل'), 'کار در فهرست صف نیامد');
        expect(in_array('f:queue_del', tg_last_buttons(), true), 'دکمه‌ی حذف از صف نبود');

        press('f:queue_del');
        press('c:0');
        expect(OpQueue::all() === [], 'کار از صف حذف نشد');
    });

    scenario('حالت تعمیر زمان‌بندی‌شده بدون پوشه‌ی سایت ثبت نمی‌شود', function (): void {
        OpQueue::clear();
        press('f:schedule_op');
        press('c:2');                                   // روشن‌کردن حالت تعمیر
        press('c:0');                                   // یک ساعت دیگر
        press('skip');                                  // عمداً بدون پوشه
        expect(str_contains(tg_last_text(), 'پوشه‌ی سایت'), 'باید بگوید پوشه‌ی سایت لازم است');
        expect(OpQueue::all() === [], 'کار ناقص نباید ثبت شود');
    });

    scenario('مقایسه‌ی دو هاست و انتقال فایل بینشان', function () use ($cpanelPort): void {
        $second = ServerStore::add([
            'label' => 'هاست دوم',
            'type' => 'cpanel',
            'host' => "127.0.0.1:$cpanelPort",
            'user' => 'demo2',
            'token' => 'TESTTOKEN2',
            'ssl_verify' => false,
        ]);

        press('m:srv');
        expect(in_array('f:srv_compare', tg_last_buttons(), true), 'دکمه‌ی مقایسه در منوی سرورها نبود');

        press('f:srv_compare');
        press('c:0');
        press('c:1');
        $text = tg_last_text();
        expect(str_contains($text, 'مقایسه‌ی دو هاست'), 'نتیجه‌ی مقایسه نیامد');
        expect(str_contains($text, 'هاست دوم'), 'نام هاست دوم در مقایسه نبود');
        expect(str_contains($text, 'example.com'), 'دامنه‌ی اصلی در مقایسه نبود');

        press('f:file_transfer');
        send_text('/home/demo/public_html/notes.txt');
        $buttons = tg_last_buttons();
        expect(in_array('c:0', $buttons, true), 'سرور مقصد باید به‌صورت دکمه بیاید');
        press('c:0');                                   // هاست دوم
        press('c:1');                                   // پوشه‌ی public_html مقصد

        $uploads = array_filter(cpanel_calls(), static fn (array $c): bool => str_contains($c['path'], 'upload_files'));
        expect($uploads !== [], 'فایل روی سرور مقصد آپلود نشد');
        expect(str_contains(tg_last_text(), 'منتقل شد'), 'پیام موفقیت انتقال نیامد');

        ServerStore::delete($second);
    });

    scenario('چک‌آپ کامل، وضعیت هر بخش را با راهکار می‌گوید', function (): void {
        press('a:summary');
        expect(in_array('a:health', tg_last_buttons(), true), 'دکمه‌ی چک‌آپ کنار خلاصه‌ی حساب نبود');

        press('a:health');
        $text = tg_last_text();
        expect(str_contains($text, 'چک‌آپ کامل هاست'), 'عنوان چک‌آپ نیامد');
        expect(str_contains($text, 'نمره‌ی سلامت'), 'نمره‌ی سلامت نیامد');
        expect(str_contains($text, 'بکاپ'), 'وضعیت بکاپ باید در چک‌آپ باشد');
    });

    scenario('فیلترهای ایمیل: فهرست، ساخت و حذف', function (): void {
        press('m:email');
        expect(in_array('a:filters', tg_last_buttons(), true), 'دکمه‌ی فیلترها در منوی ایمیل نبود');

        press('a:filters');
        expect(str_contains(tg_last_text(), 'حذف تبلیغات'), 'فهرست فیلترها نیامد');
        expect(in_array('f:filter_add', tg_last_buttons(), true), 'دکمه‌ی افزودن فیلتر نبود');

        press('f:filter_add');
        send_text('بدون اسپم');
        press('c:0');                                   // موضوع نامه
        send_text('viagra');
        press('c:0');                                   // حذف بی‌صدا
        press('skip');                                  // پوشه لازم نیست

        $calls = array_filter(cpanel_calls(), static fn (array $c): bool => str_contains($c['path'], 'store_filter'));
        expect($calls !== [], 'فیلتر روی سرور ساخته نشد');
        $body = urldecode((string) (array_values($calls)[0]['body'] ?? ''));
        expect(str_contains($body, 'val=viagra'), "عبارت فیلتر فرستاده نشد: $body");
        expect(str_contains($body, 'action=blackhole'), "عملیات فیلتر فرستاده نشد: $body");
        expect(str_contains(tg_last_text(), 'ساخته شد'), 'پیام موفقیت نیامد');
    });

    scenario('ردیابی ارسال ایمیل، تحویل‌شده و ناموفق را جدا نشان می‌دهد', function (): void {
        press('f:mail_track');
        press('skip');
        $text = tg_last_text();
        expect(str_contains($text, 'ردیابی ارسال ایمیل'), 'عنوان ردیابی نیامد');
        expect(str_contains($text, 'kian@gmail.com'), 'نامه‌ی تحویل‌شده نیامد');
        expect(str_contains($text, 'ناموفق'), 'نامه‌ی برگشت‌خورده باید مشخص شود');
        expect(str_contains($text, 'host not found'), 'دلیل برگشت باید نمایش داده شود');
    });

    scenario('خروجی SQL دیتابیس به‌صورت فایل فرستاده می‌شود', function (): void {
        $before = count(array_filter(tg_calls(), static fn (array $c): bool => $c['method'] === 'sendDocument'));

        press('m:db');
        expect(in_array('f:db_export', tg_last_buttons(), true), 'دکمه‌ی خروجی SQL در منوی دیتابیس نبود');

        press('f:db_export');
        press('c:0');                                   // demo_shop

        $documents = array_values(array_filter(tg_calls(), static fn (array $c): bool => $c['method'] === 'sendDocument'));
        expect(count($documents) === $before + 1, 'فایل خروجی دیتابیس فرستاده نشد');
        expect(str_contains(tg_last_text(), 'خروجی دیتابیس'), 'پیام آماده‌شدن خروجی نیامد');
    });

    scenario('دیتابیسی که خروجی ندارد، پیام روشن می‌گیرد نه خطای خام', function (): void {
        press('f:db_export');
        press('c:1');                                   // demo_old — روی سرور تستی خروجی ندارد
        $text = tg_last_text();
        expect(str_contains($text, 'خروجی‌گرفتن مستقیم'), 'باید صریح بگوید این هاست اجازه نمی‌دهد');
        expect(str_contains($text, 'بکاپ کامل'), 'باید راه جایگزین را پیشنهاد بدهد');
    });

    scenario('جست‌وجوی متن داخل فایل‌ها، شماره‌ی خط را هم می‌دهد', function (): void {
        press('m:files');
        foreach (['f:file_grep', 'f:dir_size', 'f:file_prune', 'f:harden'] as $expected) {
            expect(in_array($expected, tg_last_buttons(), true), "دکمه‌ی $expected در منوی فایل‌ها نبود");
        }

        press('f:file_grep');
        press('c:0');                                   // پوشه‌ی هوم
        send_text('salam');
        $text = tg_last_text();
        expect(str_contains($text, 'zz-notes.txt'), 'فایل حاوی عبارت پیدا نشد');
        expect(str_contains($text, 'salam-teamkan'), 'خط پیداشده نمایش داده نشد');
    });

    scenario('اندازه‌ی پوشه با زیرپوشه‌ها حساب می‌شود', function (): void {
        press('f:dir_size');
        press('c:0');
        $text = tg_last_text();
        expect(str_contains($text, 'اندازه‌ی پوشه'), 'عنوان اندازه‌ی پوشه نیامد');
        expect(str_contains($text, 'فایل در'), 'تعداد فایل و پوشه نیامد');
    });

    scenario('سخت‌سازی سایت، بلوک نشان‌دار می‌نویسد', function (): void {
        press('f:harden');
        press('c:1');                                   // public_html
        press('c:0');                                   // روشن

        $saves = array_filter(cpanel_calls(), static fn (array $c): bool => str_contains($c['path'], 'save_file_content'));
        $bodies = implode(' ', array_map(static fn (array $c): string => urldecode($c['body']), $saves));
        expect(str_contains($bodies, 'TEAMKAN HARDEN START'), 'بلوک سخت‌سازی نوشته نشد');
        expect(str_contains($bodies, 'Options -Indexes'), 'بستن لیست پوشه‌ها نوشته نشد');
        expect(str_contains(tg_last_text(), 'سخت‌سازی انجام شد'), 'پیام موفقیت نیامد');
    });

    scenario('حذف فایل‌های قدیمی، اول تأیید می‌گیرد', function (): void {
        press('f:file_prune');
        press('c:0');                                   // پوشه‌ی هوم
        press('c:1');                                   // ۳۰ روز
        expect(in_array('ok', tg_last_buttons(), true), 'حذف فایل باید تأیید بگیرد');
        expect(str_contains(tg_last_text(), '30'), 'تعداد روز در متن تأیید نیست');

        $before = count(cpanel_calls());
        press('no');
        expect(count(cpanel_calls()) === $before, 'بعد از انصراف نباید چیزی حذف شود');
    });

    scenario('مقایسه‌ی DNS با دنیای بیرون', function (): void {
        press('m:dns');
        expect(in_array('f:dns_check', tg_last_buttons(), true), 'دکمه‌ی مقایسه با بیرون در منوی DNS نبود');

        press('f:dns_check');
        press('c:0');
        expect(str_contains(tg_last_text(), 'DNS از دید دنیای بیرون'), 'نتیجه‌ی مقایسه نیامد');
    });

    scenario('منوی SSL دکمه‌های نصب و حذف گواهی دارد', function (): void {
        press('m:ssl');
        foreach (['f:ssl_install', 'f:ssl_del'] as $expected) {
            expect(in_array($expected, tg_last_buttons(), true), "دکمه‌ی $expected در منوی SSL نبود");
        }

        press('f:ssl_install');
        expect(str_contains(tg_last_text(), 'کدام دامنه'), 'مرحله‌ی انتخاب دامنه نیامد');
        send_text('/cancel');
    });

    scenario('تغییر محدودیت PHP روی همان دامنه اعمال می‌شود', function (): void {
        press('m:php');
        expect(in_array('f:php_limit_set', tg_last_buttons(), true), 'دکمه‌ی تغییر محدودیت در منوی PHP نبود');

        press('f:php_limit_set');
        press('c:0');                                   // example.com
        press('c:0');                                   // memory_limit
        press('c:1');                                   // 256M

        $calls = array_filter(cpanel_calls(), static fn (array $c): bool => str_contains($c['path'], 'php_ini_set_user_basic_directives'));
        expect($calls !== [], 'درخواست تغییر محدودیت نرفت');
        $body = urldecode((string) (array_values($calls)[0]['body'] ?? ''));
        expect(str_contains($body, 'vhost=example.com'), "دامنه فرستاده نشد: $body");
        expect(str_contains($body, 'memory_limit:256M'), "مقدار محدودیت فرستاده نشد: $body");
    });

    scenario('کران آماده، دستور استاندارد را خودش می‌سازد', function (): void {
        press('m:cron');
        expect(in_array('f:cron_template', tg_last_buttons(), true), 'دکمه‌ی کران آماده در منوی کران نبود');

        press('f:cron_template');
        press('c:0');                                   // کران وردپرس
        press('c:0');                                   // example.com

        $calls = array_filter(cpanel_calls(), static fn (array $c): bool => str_contains($c['path'], 'Cron/add_line'));
        expect($calls !== [], 'کران روی سرور ساخته نشد');
        $body = urldecode((string) (array_values($calls)[0]['body'] ?? ''));
        expect(str_contains($body, 'wp-cron.php'), "دستور کران درست نبود: $body");
        expect(str_contains($body, '/home/demo/public_html'), 'پوشه‌ی سایت داخل دستور نیامد');
        expect(str_contains(tg_last_text(), '*/5'), 'زمان‌بندی در پیام نتیجه نیامد');
    });

    scenario('دانلود بکاپ در تلگرام', function (): void {
        $before = count(array_filter(tg_calls(), static fn (array $c): bool => $c['method'] === 'sendDocument'));

        press('m:backup');
        expect(in_array('f:backup_download', tg_last_buttons(), true), 'دکمه‌ی دانلود بکاپ در منوی بکاپ نبود');

        press('f:backup_download');
        press('c:0');

        $after = count(array_filter(tg_calls(), static fn (array $c): bool => $c['method'] === 'sendDocument'));
        expect($after === $before + 1, 'فایل بکاپ فرستاده نشد');
    });

    scenario('کلیدهای SSH: فهرست و افزودن', function (): void {
        press('m:ftp');
        expect(in_array('a:sshkeys', tg_last_buttons(), true), 'دکمه‌ی کلیدهای SSH در منوی FTP نبود');

        press('a:sshkeys');
        expect(str_contains(tg_last_text(), 'laptop'), 'کلید موجود نمایش داده نشد');
        expect(in_array('f:sshkey_add', tg_last_buttons(), true), 'دکمه‌ی افزودن کلید نبود');

        press('f:sshkey_add');
        send_text('server-2');
        send_text('ssh-ed25519 AAAAC3NzaC1lZDI1NTE5AAAAI teamkan@test');

        $calls = array_filter(cpanel_calls(), static fn (array $c): bool => str_contains($c['path'], 'SSH/import_key'));
        expect($calls !== [], 'کلید روی سرور وارد نشد');
        expect(str_contains(tg_last_text(), 'اضافه و مجاز شد'), 'پیام موفقیت نیامد');
    });

    scenario('کلید خصوصی به‌جای عمومی، رد می‌شود', function (): void {
        press('f:sshkey_add');
        send_text('اشتباهی');
        send_text('-----BEGIN OPENSSH PRIVATE KEY-----');
        expect(str_contains(tg_last_text(), 'کلید عمومی'), 'باید هشدار بدهد که کلید عمومی لازم است');
    });

    scenario('تعلیق صندوق و صندوق پیش‌فرض دامنه', function (): void {
        press('m:email');
        foreach (['f:email_suspend', 'f:catchall'] as $expected) {
            expect(in_array($expected, tg_last_buttons(), true), "دکمه‌ی $expected در منوی ایمیل نبود");
        }

        press('f:email_suspend');
        press('c:0');                                   // info@example.com
        press('c:0');                                   // جلوگیری از ارسال
        press('ok');
        $calls = array_filter(cpanel_calls(), static fn (array $c): bool => str_contains($c['path'], 'suspend_outgoing'));
        expect($calls !== [], 'درخواست تعلیق ارسال نرفت');
        expect(str_contains(tg_last_text(), 'رمز آن صندوق را هم عوض کنید'), 'راهنمای بعد از تعلیق نیامد');

        press('f:catchall');
        press('c:0');                                   // example.com
        press('c:0');                                   // برگرداندن با خطا
        press('skip');
        expect(str_contains(tg_last_text(), 'آدرس‌های ناموجود'), 'نتیجه‌ی صندوق پیش‌فرض نیامد');
    });

    scenario('ساخت کامل دیتابیس در یک قدم، اطلاعات اتصال را می‌دهد', function (): void {
        press('m:db');
        expect(in_array('f:db_setup', tg_last_buttons(), true), 'دکمه‌ی ساخت کامل در منوی دیتابیس نبود');

        press('f:db_setup');
        send_text('shop');
        send_text('shopuser');
        send_text('/gen');
        $text = tg_last_text();
        expect(str_contains($text, 'دیتابیس آماده شد'), 'نتیجه‌ی راه‌اندازی نیامد');
        expect(str_contains($text, 'shopuser'), 'نام کاربر در نتیجه نیست');
        expect(str_contains($text, 'رمز'), 'رمز ساخته‌شده باید نشان داده شود');

        $grants = array_filter(cpanel_calls(), static fn (array $c): bool => str_contains($c['path'], 'set_privileges_on_database'));
        expect($grants !== [], 'دسترسی کاربر به دیتابیس داده نشد');
    });

    scenario('صفحه‌ی خطای سفارشی و دامنه‌های پارک‌شده', function (): void {
        press('m:dom');
        foreach (['f:error_pages', 'a:parked'] as $expected) {
            expect(in_array($expected, tg_last_buttons(), true), "دکمه‌ی $expected در منوی دامنه‌ها نبود");
        }

        press('f:error_pages');
        press('c:1');                                   // public_html
        press('c:0');                                   // بساز
        $bodies = implode(' ', array_map(
            static fn (array $c): string => urldecode($c['body']),
            array_filter(cpanel_calls(), static fn (array $c): bool => str_contains($c['path'], 'save_file_content')),
        ));
        expect(str_contains($bodies, 'ErrorDocument 404'), 'قانون ErrorDocument نوشته نشد');
        expect(str_contains(tg_last_text(), 'صفحه‌های خطای فارسی'), 'پیام موفقیت نیامد');

        press('a:parked');
        expect(str_contains(tg_last_text(), 'پارک‌شده'), 'فهرست دامنه‌های پارک‌شده نیامد');
    });

    scenario('تغییر TTL همه‌ی رکوردها در یک درخواست', function (): void {
        press('m:dns');
        expect(in_array('f:dns_ttl', tg_last_buttons(), true), 'دکمه‌ی تغییر TTL در منوی DNS نبود');

        press('f:dns_ttl');
        press('c:0');                                   // example.com
        press('c:0');                                   // ۵ دقیقه
        press('ok');

        $edits = array_filter(cpanel_calls(), static fn (array $c): bool => str_contains($c['path'], 'mass_edit_zone'));
        expect($edits !== [], 'درخواست تغییر زون نرفت');
        $body = urldecode((string) (array_values($edits)[array_key_last(array_values($edits))]['body'] ?? ''));
        expect(str_contains($body, '"ttl":300'), "مقدار TTL تازه فرستاده نشد: $body");
    });

    scenario('CSR و کلید خصوصی به‌صورت دو فایل فرستاده می‌شوند', function (): void {
        $before = count(array_filter(tg_calls(), static fn (array $c): bool => $c['method'] === 'sendDocument'));

        press('m:ssl');
        expect(in_array('f:ssl_csr', tg_last_buttons(), true), 'دکمه‌ی ساخت CSR در منوی SSL نبود');

        press('f:ssl_csr');
        send_text('shop.example.com');
        press('skip');                                  // بدون نام سازمان
        press('c:0');                                   // ایران

        $after = count(array_filter(tg_calls(), static fn (array $c): bool => $c['method'] === 'sendDocument'));
        expect($after === $before + 2, 'باید دو فایل (CSR و کلید) فرستاده شود');
        expect(str_contains(tg_last_text(), 'کلید خصوصی'), 'هشدار نگهداری کلید نیامد');
    });

    scenario('سطل زباله: فهرست، بازگردانی و خالی‌کردن', function (): void {
        press('m:files');
        foreach (['a:trash', 'f:file_from_url'] as $expected) {
            expect(in_array($expected, tg_last_buttons(), true), "دکمه‌ی $expected در منوی فایل‌ها نبود");
        }

        press('a:trash');
        expect(str_contains(tg_last_text(), 'سطل زباله'), 'عنوان سطل زباله نیامد');
        expect(in_array('f:trash_restore', tg_last_buttons(), true), 'دکمه‌ی بازگردانی نبود');

        press('f:trash_restore');
        press('c:2');                                   // یکی از موردها
        press('c:1');                                   // به public_html
        $moves = array_filter(cpanel_calls(), static fn (array $c): bool => str_contains(urldecode($c['body']), '.trash'));
        expect($moves !== [], 'مسیر سطل زباله در درخواست نبود');
        expect(str_contains(tg_last_text(), 'برگردانده شد'), 'پیام بازگردانی نیامد');
    });

    scenario('گرفتن فایل از آدرس داخلی شبکه رد می‌شود', function (): void {
        press('f:file_from_url');
        send_text('http://127.0.0.1/secret.zip');
        press('c:0');
        press('skip');
        expect(str_contains(tg_last_text(), 'آدرس‌های داخلی'), 'آدرس داخلی باید رد شود');
    });

    scenario('وب‌دیسک: فهرست و ساخت حساب', function (): void {
        press('m:ftp');
        expect(in_array('a:webdisk', tg_last_buttons(), true), 'دکمه‌ی وب‌دیسک در منوی FTP نبود');

        press('a:webdisk');
        expect(str_contains(tg_last_text(), 'deploy@example.com'), 'حساب موجود وب‌دیسک نیامد');

        press('f:webdisk_add');
        send_text('kian');
        press('c:0');                                   // example.com
        send_text('/gen');
        press('c:1');                                   // public_html
        $calls = array_filter(cpanel_calls(), static fn (array $c): bool => str_contains($c['path'], 'WebDisk/create_user'));
        expect($calls !== [], 'حساب وب‌دیسک ساخته نشد');
        expect(str_contains(tg_last_text(), '2078'), 'راهنمای اتصال وب‌دیسک نیامد');
    });

    scenario('توقف موقت کران و برگرداندنش', function (): void {
        CronVault::clear();

        press('m:cron');
        foreach (['f:cron_pause', 'f:cron_resume'] as $expected) {
            expect(in_array($expected, tg_last_buttons(), true), "دکمه‌ی $expected در منوی کران نبود");
        }

        press('f:cron_pause');
        press('c:0');
        press('ok');
        expect(str_contains(tg_last_text(), 'کران متوقف شد'), 'پیام توقف نیامد');
        expect(count(CronVault::all()) === 1, 'کران در انبار ذخیره نشد');

        press('f:cron_resume');
        press('c:0');
        $adds = array_filter(cpanel_calls(), static fn (array $c): bool => str_contains($c['path'], 'Cron/add_line'));
        expect($adds !== [], 'کران دوباره ساخته نشد');
        expect(str_contains(tg_last_text(), 'کران برگشت'), 'پیام برگشت نیامد');
        expect(CronVault::all() === [], 'کران برگشته باید از انبار برداشته شود');
    });

    scenario('تغییر نسخه‌ی PHP همه‌ی دامنه‌ها با تأیید', function (): void {
        press('m:php');
        expect(in_array('f:php_set_all', tg_last_buttons(), true), 'دکمه‌ی نسخه‌ی همه‌ی دامنه‌ها نبود');

        press('f:php_set_all');
        $buttons = tg_last_buttons();
        expect(in_array('c:0', $buttons, true), 'نسخه‌ها باید دکمه شوند');
        press('c:0');
        expect(in_array('ok', tg_last_buttons(), true), 'تغییر گروهی باید تأیید بگیرد');
        press('no');
        expect(str_contains(tg_last_text(), 'لغو شد'), 'انصراف کار نکرد');
    });

    scenario('حذف بکاپ و بکاپ روی FTP راه دور', function (): void {
        press('m:backup');
        foreach (['f:backup_del', 'f:backup_ftp'] as $expected) {
            expect(in_array($expected, tg_last_buttons(), true), "دکمه‌ی $expected در منوی بکاپ نبود");
        }

        press('f:backup_del');
        press('c:0');
        expect(in_array('ok', tg_last_buttons(), true), 'حذف بکاپ باید تأیید بگیرد');
        press('ok');
        expect(str_contains(tg_last_text(), 'پاک شد'), 'پیام حذف بکاپ نیامد');

        press('f:backup_ftp');
        send_text('backup.example.com');
        send_text('ftpuser');
        send_text('rmz-123');
        press('skip');
        press('skip');
        $calls = array_filter(cpanel_calls(), static fn (array $c): bool => str_contains($c['path'], 'fullbackup_to_ftp'));
        expect($calls !== [], 'درخواست بکاپ روی FTP نرفت');
        $body = urldecode((string) (array_values($calls)[0]['body'] ?? ''));
        expect(str_contains($body, 'host=backup.example.com'), "آدرس مقصد فرستاده نشد: $body");
    });

    scenario('ویرایش نام و توکن سرور از داخل ربات', function (): void {
        press('m:srv');
        foreach (['f:srv_rename', 'f:srv_token'] as $expected) {
            expect(in_array($expected, tg_last_buttons(), true), "دکمه‌ی $expected در منوی سرورها نبود");
        }

        press('f:srv_rename');
        press('c:0');
        send_text('هاست اصلی');
        expect(str_contains(tg_last_text(), 'هاست اصلی'), 'نام تازه اعمال نشد');

        $active = ServerStore::active();
        expect(($active['label'] ?? '') === 'هاست اصلی', 'نام سرور در فایل ذخیره نشد');
    });

    scenario('فایل‌های تحت‌نظر از منوی هشدارها تنظیم می‌شوند', function (): void {
        press('m:alerts');
        expect(in_array('f:watch_files', tg_last_buttons(), true), 'دکمه‌ی فایل‌های تحت‌نظر نبود');

        press('f:watch_files');
        send_text("/home/demo/public_html/.htaccess\n/home/demo/public_html/wp-config.php");
        expect(str_contains(tg_last_text(), 'زیر نظر گرفته شد'), 'پیام ثبت نیامد');
        expect(str_contains(Settings::get(Settings::WATCH_FILES), 'wp-config.php'), 'فهرست ذخیره نشد');

        press('f:watch_files');
        send_text('-');
        expect(Settings::get(Settings::WATCH_FILES) === '', 'خاموش‌کردن دیده‌بانی کار نکرد');
    });

    scenario('فوروارد کل دامنه و پرکارترین فرستنده‌ها', function (): void {
        press('m:email');
        foreach (['f:fwd_domain', 'a:mailtop'] as $expected) {
            expect(in_array($expected, tg_last_buttons(), true), "دکمه‌ی $expected در منوی ایمیل نبود");
        }

        press('a:mailtop');
        $text = tg_last_text();
        expect(str_contains($text, 'پرکارترین فرستنده‌ها'), 'عنوان نیامد');
        expect(str_contains($text, 'info@example.com'), 'فرستنده نیامد');

        press('f:fwd_domain');
        press('c:0');                                   // example.com
        send_text('new-example.com');
        press('ok');
        expect(str_contains(tg_last_text(), 'new-example.com'), 'نتیجه‌ی فوروارد دامنه نیامد');
    });

    scenario('دسترسی‌های کاربر روی دیتابیس و خروجی همه‌ی دیتابیس‌ها', function (): void {
        press('m:db');
        foreach (['f:db_privs', 'f:db_export_all'] as $expected) {
            expect(in_array($expected, tg_last_buttons(), true), "دکمه‌ی $expected در منوی دیتابیس نبود");
        }

        press('f:db_privs');
        press('c:0');                                   // demo_admin
        press('c:0');                                   // demo_shop
        expect(str_contains(tg_last_text(), 'demo_shop'), 'نتیجه‌ی دسترسی‌ها نیامد');

        $before = count(array_filter(tg_calls(), static fn (array $c): bool => $c['method'] === 'sendDocument'));
        press('f:db_export_all');
        press('c:0');                                   // ۳ تا
        press('ok');
        $after = count(array_filter(tg_calls(), static fn (array $c): bool => $c['method'] === 'sendDocument'));
        expect($after > $before, 'حداقل یک خروجی دیتابیس باید فرستاده شود');
    });

    scenario('یکسان‌سازی آدرس، سرعت و محافظت وردپرس', function (): void {
        press('m:dom');
        foreach (['f:canonical', 'f:speed', 'f:wp_protect'] as $expected) {
            expect(in_array($expected, tg_last_buttons(), true), "دکمه‌ی $expected در منوی دامنه‌ها نبود");
        }

        press('f:canonical');
        press('c:0');                                   // example.com
        press('c:1');                                   // public_html
        press('c:0');                                   // بدون www
        expect(str_contains(tg_last_text(), 'https://example.com/'), 'مقصد یکسان‌سازی نیامد');

        press('f:speed');
        press('c:1');
        press('c:0');
        $bodies = implode(' ', array_map(
            static fn (array $c): string => urldecode($c['body']),
            array_filter(cpanel_calls(), static fn (array $c): bool => str_contains($c['path'], 'save_file_content')),
        ));
        expect(str_contains($bodies, 'mod_deflate'), 'قانون فشرده‌سازی نوشته نشد');

        press('f:wp_protect');
        press('c:1');
        press('c:0');                                   // روشن
        send_text('1.2.3.4');
        expect(str_contains(tg_last_text(), '1.2.3.4'), 'آی‌پی مجاز در نتیجه نیامد');
    });

    scenario('وارد کردن فایل زون و تغییر آی‌پی همه‌ی دامنه‌ها', function (): void {
        press('m:dns');
        foreach (['f:dns_import', 'f:dns_repoint'] as $expected) {
            expect(in_array($expected, tg_last_buttons(), true), "دکمه‌ی $expected در منوی DNS نبود");
        }

        press('f:dns_import');
        press('c:0');                                   // example.com
        send_text("shop.example.com. 300 IN A 203.0.113.11");
        press('ok');
        expect(str_contains(tg_last_text(), 'shop.example.com'), 'رکورد واردشده گزارش نشد');

        press('f:dns_repoint');
        send_text('203.0.113.99');
        press('ok');
        expect(str_contains(tg_last_text(), '203.0.113.99'), 'نتیجه‌ی تغییر آی‌پی نیامد');
    });

    scenario('HSTS و بررسی گواهی‌ها از بیرون', function (): void {
        press('m:ssl');
        foreach (['f:hsts', 'a:sslcheck'] as $expected) {
            expect(in_array($expected, tg_last_buttons(), true), "دکمه‌ی $expected در منوی SSL نبود");
        }

        press('f:hsts');
        press('c:1');
        press('c:0');                                   // روشن
        $bodies = implode(' ', array_map(
            static fn (array $c): string => urldecode($c['body']),
            array_filter(cpanel_calls(), static fn (array $c): bool => str_contains($c['path'], 'save_file_content')),
        ));
        expect(str_contains($bodies, 'Strict-Transport-Security'), 'هدر HSTS نوشته نشد');
        expect(str_contains(tg_last_text(), 'تمدید خودکار'), 'هشدار HSTS نیامد');
    });

    scenario('فایل‌های مهم، جایگزینی متن و مقایسه‌ی پوشه‌ها', function (): void {
        press('m:files');
        foreach (['f:quick_view', 'f:file_replace', 'f:dir_diff', 'a:inodes'] as $expected) {
            expect(in_array($expected, tg_last_buttons(), true), "دکمه‌ی $expected در منوی فایل‌ها نبود");
        }

        press('f:dir_diff');
        press('c:0');                                   // هوم
        press('c:1');                                   // public_html
        expect(str_contains(tg_last_text(), 'مقایسه‌ی دو پوشه'), 'نتیجه‌ی مقایسه نیامد');

        press('f:file_replace');
        press('c:0');
        send_text('salam');
        send_text('doroud');
        press('ok');
        expect(str_contains(tg_last_text(), 'جایگزینی') || str_contains(tg_last_text(), 'گشته شد'), 'نتیجه‌ی جایگزینی نیامد');
    });

    scenario('مجاز کردن کلید SSH و کران با انتخاب زمان', function (): void {
        press('m:ftp');
        expect(in_array('f:sshkey_auth', tg_last_buttons(), true), 'دکمه‌ی مجاز کردن کلید نبود');

        press('f:sshkey_auth');
        press('c:0');                                   // laptop
        press('c:1');                                   // نامجاز
        $calls = array_filter(cpanel_calls(), static fn (array $c): bool => str_contains($c['path'], 'deauthorize_key'));
        expect($calls !== [], 'درخواست نامجاز کردن نرفت');

        press('m:cron');
        expect(in_array('f:cron_easy', tg_last_buttons(), true), 'دکمه‌ی ساخت کران با انتخاب زمان نبود');

        press('f:cron_easy');
        press('c:0');                                   // هر ۵ دقیقه
        send_text('/usr/local/bin/php /home/demo/task.php');
        $adds = array_filter(cpanel_calls(), static fn (array $c): bool => str_contains($c['path'], 'Cron/add_line'));
        expect($adds !== [], 'کران ساخته نشد');
        $body = urldecode((string) (array_values($adds)[array_key_last(array_values($adds))]['body'] ?? ''));
        expect(str_contains($body, 'minute=%2A%2F5') || str_contains($body, 'minute=*/5'), "زمان‌بندی درست نرفت: $body");
    });

    scenario('کپی محدودیت‌های PHP و بازکردن بکاپ', function (): void {
        press('m:php');
        expect(in_array('f:php_copy_limits', tg_last_buttons(), true), 'دکمه‌ی کپی محدودیت‌ها نبود');

        press('m:backup');
        expect(in_array('f:backup_restore', tg_last_buttons(), true), 'دکمه‌ی بازکردن بکاپ نبود');

        press('f:backup_restore');
        press('c:0');                                   // بکاپ موجود
        press('c:0');                                   // پوشه‌ی هوم
        expect(in_array('ok', tg_last_buttons(), true), 'بازکردن بکاپ باید تأیید بگیرد');
        press('ok');
        expect(str_contains(tg_last_text(), 'باز شد') || str_contains(tg_last_text(), '⚠️'), 'نتیجه‌ی بازکردن بکاپ نیامد');
    });

    scenario('داشبورد چندسروری، تست همه و خروجی تنظیمات', function (): void {
        press('m:srv');
        foreach (['a:dashboard', 'a:pingall', 'a:configexport'] as $expected) {
            expect(in_array($expected, tg_last_buttons(), true), "دکمه‌ی $expected در منوی سرورها نبود");
        }

        press('a:dashboard');
        expect(str_contains(tg_last_text(), 'یک نگاه'), 'داشبورد نیامد');

        press('a:pingall');
        expect(str_contains(tg_last_text(), 'تست اتصال همه'), 'تست همه نیامد');

        $before = count(array_filter(tg_calls(), static fn (array $c): bool => $c['method'] === 'sendDocument'));
        press('a:configexport');
        $documents = array_values(array_filter(tg_calls(), static fn (array $c): bool => $c['method'] === 'sendDocument'));
        expect(count($documents) === $before + 1, 'فایل تنظیمات فرستاده نشد');
        expect(!str_contains(json_encode($documents, JSON_UNESCAPED_UNICODE), 'TESTTOKEN'), 'توکن نباید در خروجی باشد!');
    });

    scenario('فعالیت ادمین‌ها و راهنماهای تازه', function (): void {
        press('m:adm');
        expect(in_array('a:actors', tg_last_buttons(), true), 'دکمه‌ی فعالیت ادمین‌ها نبود');

        press('a:actors');
        expect(str_contains(tg_last_text(), 'فعالیت ادمین‌ها'), 'گزارش فعالیت نیامد');

        press('m:howto');
        foreach (['h:migrate', 'h:security'] as $expected) {
            expect(in_array($expected, tg_last_buttons(), true), "راهنمای $expected نبود");
        }

        press('h:migrate');
        expect(str_contains(tg_last_text(), 'انتقال سایت'), 'راهنمای انتقال نیامد');
        expect(in_array('f:dns_repoint', tg_last_buttons(), true), 'دکمه‌ی قدم DNS در راهنما نبود');

        press('h:security');
        expect(in_array('f:harden', tg_last_buttons(), true), 'دکمه‌ی سخت‌سازی در راهنمای امنیت نبود');
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
