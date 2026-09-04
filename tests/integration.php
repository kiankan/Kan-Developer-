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
