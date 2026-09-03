# kan_checker — مدیریت cPanel/WHM از تلگرام و خط فرمان

ابزار مدیریت هاست که از طریق **API رسمی cPanel و WHM** به سرور وصل می‌شود و کارهای روزمره‌ی مدیریت (ایمیل، دیتابیس، دامنه، DNS، FTP، کران، SSL، نسخه‌ی PHP، بکاپ و مدیریت حساب‌های سرور) را از دو راه در اختیارتان می‌گذارد:

- **ربات تلگرام** با منوی دکمه‌ای فارسی — برای وقتی که پشت لپ‌تاپ نیستید.
- **خط فرمان (CLI)** — همان کارها به‌علاوه‌ی امکان صدا زدن مستقیم هر تابع UAPI/WHM API، مناسب اسکریپت و اتوماسیون.

نوشته‌شده با PHP، **بدون هیچ وابستگی خارجی** (فقط `php-cli` با پسوندهای `curl`/`mbstring`/`json`). اتصال فقط با **API Token** انجام می‌شود؛ رمز حساب cPanel هیچ‌جا ذخیره نمی‌شود.

> نسخه‌های قبلی این پروژه یک چکر Fragment/TON بود. کل پروژه به ابزار مدیریت cPanel تبدیل شده؛ اگر نصب قدیمی دارید، با `kancheckerctl update` (یا اجرای دوباره‌ی `install.sh`) به همین نسخه می‌رسید و فقط باید اطلاعات اتصال cPanel را وارد کنید.

## قابلیت‌ها

**سطح حساب (توکن cPanel):**

| بخش | کارها |
| --- | --- |
| 📊 خلاصه | مصرف دیسک، پهنای‌باند، اینود و بقیه‌ی منابع + شمارش ایمیل/دیتابیس/FTP/دامنه |
| ✉️ ایمیل | لیست با مصرف و سهمیه، ساخت، حذف، تغییر رمز، تغییر سهمیه |
| 🗄 دیتابیس | لیست دیتابیس‌ها و کاربران، ساخت/حذف دیتابیس، ساخت/حذف کاربر، دادن دسترسی |
| 🌐 دامنه | دامنه‌ی اصلی، ادان‌دامنه، ساب‌دامنه، پارک‌شده‌ها + ساخت/حذف ساب‌دامنه |
| 🧭 DNS | نمایش کامل رکوردهای زون، افزودن رکورد (A/AAAA/CNAME/TXT/MX/NS)، حذف رکورد |
| 📁 FTP | لیست با مصرف، ساخت، حذف |
| ⏰ کران | لیست، افزودن (با اعتبارسنجی زمان‌بندی)، حذف |
| 🔒 SSL | گواهی‌های نصب‌شده، صادرکننده و روزهای باقی‌مانده تا انقضا |
| 🐘 PHP | نسخه‌ی PHP هر دامنه + تغییر نسخه (MultiPHP) |
| 💾 بکاپ | شروع بکاپ کامل در پوشه‌ی هوم |

**سطح سرور (توکن WHM):** لیست حساب‌ها، جزئیات حساب، ساخت حساب، تعلیق/رفع تعلیق، تغییر رمز، **لینک ورود یک‌بارمصرف به cPanel کاربر**، حذف کامل حساب، پلن‌ها، بار سیستم و وضعیت سرویس‌ها. با یک توکن WHM می‌توانید کارهای سطح حساب بالا را هم روی **هر کاربری** از همان سرور انجام دهید.

**بقیه:**

- چند سرور هم‌زمان: cPanelهای مختلف و WHM را کنار هم تعریف کنید و بینشان جابه‌جا شوید.
- هر عملیات مخرب (حذف ایمیل/دیتابیس/حساب) یک مرحله‌ی **تأیید صریح** دارد؛ حذف کامل حساب در WHM نیاز به تایپ دوباره‌ی نام کاربری دارد.
- **لاگ عملیات** (`data/audit.log`): چه کسی، کِی، روی کدام سرور، چه کاری کرد — بدون ذخیره‌ی رمزها.
- ساخت رمز قوی با `/gen` در هر مرحله‌ای که رمز می‌خواهد، و پاک‌کردن خودکار پیام حاوی رمز از چت.

## قدم اول: ساخت API Token

**برای یک حساب cPanel:** وارد cPanel شوید → بخش **Security** → **Manage API Tokens** → *Create* → یک اسم بدهید (مثل `kan_checker`) → توکن ساخته‌شده را کپی کنید (فقط همان یک‌بار نمایش داده می‌شود).

**برای کل سرور (WHM):** وارد WHM شوید → **Development** → **Manage API Tokens** → *Generate Token*. توکن‌های WHM می‌توانند به ACLهای مشخص محدود شوند؛ فقط دسترسی‌هایی را بدهید که لازم دارید (مثلاً برای فقط دیدن حساب‌ها، `list-accts`).

پورت‌های پیش‌فرض: cPanel روی `2083` و WHM روی `2087`. مطمئن شوید فایروال سرور اجازه‌ی اتصال از ماشینی که ربات روی آن اجرا می‌شود را می‌دهد.

## تنظیمات (`.env`)

```env
TELEGRAM_BOT_TOKEN=123456:ABC-...
ADMIN_ID=123456789          # آیدی عددی تلگرام شما (از @userinfobot)
ADMIN_IDS=                  # اختیاری: چند ادمین، جدا شده با کاما

CPANEL_HOST=server.example.com
CPANEL_PORT=2083
CPANEL_USER=cpaneluser
CPANEL_TOKEN=...
CPANEL_SSL_VERIFY=1         # فقط برای تست روی گواهی خودامضا 0 کنید

WHM_HOST=                   # اختیاری، برای دسترسی سطح سرور
WHM_PORT=2087
WHM_USER=root
WHM_TOKEN=
```

نمونه‌ی کامل در `.env.example` است. سرورهایی که از داخل خود ربات اضافه می‌کنید در `data/servers.json` (با دسترسی `0600`) ذخیره می‌شوند و با آپدیت پاک نمی‌شوند.

⚠️ **بدون `ADMIN_ID` ربات هیچ دستوری را اجرا نمی‌کند.** چون این ربات به سرور شما دسترسی مدیریتی دارد، برخلاف نسخه‌های قبلی «حالت عمومی» ندارد.

## استفاده از ربات

`/start` را بفرستید تا منو بیاید. همه‌چیز با دکمه است؛ هرجا ورودی لازم باشد، ربات قدم‌به‌قدم می‌پرسد (`/cancel` برای لغو، `/skip` برای رد کردن مرحله‌های اختیاری، `/gen` برای ساخت رمز).

دستورهای سریع:

```
/panel     منوی اصلی            /summary   خلاصه‌ی حساب
/emails    ایمیل‌ها              /db        دیتابیس‌ها
/domains   دامنه‌ها              /ftp       حساب‌های FTP
/cron      کران‌جاب‌ها            /dns <زون> رکوردهای DNS
/ssl       گواهی‌ها              /php       نسخه‌ی PHP دامنه‌ها
/whm       منوی سطح سرور        /servers   سرورها
/ping      تست اتصال            /log       آخرین عملیات‌ها
/use <id>  تعویض سرور فعال      /cancel    لغو عملیات جاری
```

روی یک سرور از نوع WHM، قبل از کارهای سطح حساب یک‌بار «🎯 انتخاب کاربر مقصد» را بزنید تا مشخص شود کارها روی کدام حساب انجام شود.

## استفاده از خط فرمان

```bash
php cli.php ping                      # تست اتصال و اعتبار توکن
php cli.php summary                   # خلاصه‌ی منابع حساب
php cli.php email:list
php cli.php email:add info@example.com 'S3cret!' 512
php cli.php db:add user_shop && php cli.php dbuser:add user_admin 'S3cret!'
php cli.php db:grant user_admin user_shop
php cli.php dns:list example.com
php cli.php dns:add example.com www A 203.0.113.10 300
php cli.php cron:add '*/5 * * * *' '/usr/local/bin/php /home/user/cron.php'
php cli.php ssl:list
php cli.php php:set example.com ea-php82
php cli.php backup:full me@example.com
```

سطح سرور (روی سرور نوع `whm`):

```bash
php cli.php whm:accounts
php cli.php whm:summary ali
php cli.php whm:suspend ali 'عدم پرداخت'
php cli.php whm:login ali            # لینک ورود یک‌بارمصرف به cPanel آن حساب
php cli.php whm:status
php cli.php whm:terminate ali --yes  # بدون --yes اجرا نمی‌شود
```

سوییچ‌های سراسری: `--server=<id>` (اجرا روی سرور دیگر)، `--user=<user>` (کاربر مقصد روی WHM)، `--json` (خروجی JSON برای اسکریپت‌نویسی).

```bash
php cli.php email:list --server=env-whm --user=ali --json | jq '.[].email'
```

### تماس مستقیم با API

هر تابعی از cPanel که منو ندارد را می‌توانید مستقیم صدا بزنید — خروجی همیشه JSON خام است:

```bash
php cli.php uapi   Mysql list_databases
php cli.php uapi   Fileman list_files dir=/home/user/public_html
php cli.php whmapi listaccts search=shop searchtype=domain
php cli.php api2   MysqlFE listdbs
```

پارامترها به‌شکل `key=value` داده می‌شوند و کلید تکراری (مثل `add=` در `DNS::mass_edit_zone`) هم پشتیبانی می‌شود.

### مدیریت سرورها از CLI

```bash
php cli.php servers
php cli.php server:add --label='هاست اصلی' --type=cpanel --host=server.example.com --user=cpuser --token=XXXX
php cli.php use <server-id>
php cli.php server:del <server-id>
```

هنگام افزودن، اتصال قبل از ذخیره تست می‌شود تا توکن غلط بی‌سروصدا ذخیره نشود.

## نصب روی سرور (VPS) — با منوی مدیریت

به‌عنوان root، تعاملی (توکن ربات، آیدی ادمین و اتصال cPanel را می‌پرسد):

```bash
bash <(curl -Ls https://raw.githubusercontent.com/kiankan/kan_checker/main/kancheckerctl) install
```

یا کاملاً خودکار در یک خط، بدون هیچ سوالی:

```bash
ssh root@SERVER_IP 'TELEGRAM_BOT_TOKEN=xxx ADMIN_ID=123456789 \
  CPANEL_HOST=server.example.com CPANEL_USER=cpuser CPANEL_TOKEN=xxxx \
  bash <(curl -Ls https://raw.githubusercontent.com/kiankan/kan_checker/main/kancheckerctl) install'
```

بعد از نصب، از هر جای سرور:

```
kancheckerctl            منوی مدیریت
kancheckerctl update     آپدیت
kancheckerctl cpanel     تنظیم/تغییر اتصال cPanel یا WHM
kancheckerctl ping       تست اتصال
kancheckerctl logs       لاگ زنده‌ی سرویس
```

## نصب روی هاست (Docker) — یک دستور، هم نصب هم آپدیت

```bash
bash <(curl -Ls https://raw.githubusercontent.com/kiankan/kan_checker/main/install.sh)
```

اگر Docker نصب نباشد خودش نصبش می‌کند. پوشه‌ی `data/` به‌صورت volume بیرون از کانتینر می‌ماند، پس سرورهای اضافه‌شده و لاگ عملیات با هر rebuild حفظ می‌شوند.

```bash
cd ~/kan_checker && docker compose exec bot php cli.php ping   # تست اتصال
cd ~/kan_checker && docker compose logs -f                     # لاگ زنده
```

## نصب روی خود هاست اشتراکی cPanel (فقط PHP، بدون پروسه‌ی دائمی)

اگر نمی‌توانید پروسه‌ی همیشه‌روشن اجرا کنید، از حالت **webhook** استفاده کنید:

1. فایل‌های پروژه را (به‌همراه `.env`) روی هاست آپلود کنید طوری که `webhook.php` از یک آدرس HTTPS عمومی در دسترس باشد.
2. مطمئن شوید پوشه‌ی `data/` قابل نوشتن است و **از بیرون قابل دسترسی نیست** (بهترین کار: کل پروژه بیرون از `public_html` باشد و فقط `webhook.php` داخلش).
3. یک‌بار وبهوک را ست کنید:

```bash
php cli.php webhook:set https://example.com/kan_checker/webhook.php
php cli.php webhook:delete   # برای قطع کردن
```

## نصب دستی

<details>
<summary>systemd</summary>

```bash
sudo mkdir -p /opt/kan_checker && cd /opt/kan_checker
sudo git clone https://github.com/kiankan/kan_checker.git .
sudo cp .env.example .env    # مقادیر را داخلش پر کنید
sudo cp deploy/kan_checker-bot.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now kan_checker-bot
```

</details>

<details>
<summary>Docker</summary>

```bash
cp .env.example .env         # مقادیر را داخلش پر کنید
docker compose up -d --build
```

روی هاست‌هایی که `Procfile` را می‌فهمند (مثل Railway بدون Docker) همان `worker: php bot.php` استفاده می‌شود؛ کافیست متغیرهای محیطی را در پنل هاست تنظیم کنید.

</details>

## تست‌ها

```bash
php tests/run.php
```

تست‌ها کاملاً آفلاین‌اند: لایه‌ی HTTP با یک transport ساختگی جایگزین می‌شود، پس هیچ تماسی با سرور واقعی گرفته نمی‌شود. پوشش: ساخت درخواست UAPI/WHM/API2، ترجمه‌ی خطاها، نرمال‌سازی پاسخ‌های ناهمگون cPanel، دیکد base64 زون DNS، ذخیره‌ی سرورها، و کامل‌بودن جدول جریان‌های ربات.

## امنیت

- فقط API Token؛ رمز حساب هیچ‌جا ذخیره یا ارسال نمی‌شود.
- `data/` با `0700` و `servers.json`/`state.json`/`audit.log` با `0600` نوشته می‌شوند. `.env` و `data/` در `.gitignore` هستند.
- توکن‌ها هیچ‌وقت کامل نمایش داده نمی‌شوند (`ABCD••••••WXYZ`) و در لاگ عملیات، رمزها با `***` جایگزین می‌شوند.
- پیام‌های حاوی رمز بعد از دریافت از چت تلگرام پاک می‌شوند.
- بررسی گواهی SSL پیش‌فرض روشن است؛ خاموش‌کردنش (`CPANEL_SSL_VERIFY=0`) فقط برای گواهی خودامضا و روی شبکه‌ی مطمئن توصیه می‌شود.
- توکن WHM را با کمترین ACL ممکن بسازید؛ توکن‌های cPanel به تمام قابلیت‌های همان حساب دسترسی دارند.

## ساختار پروژه

```
bot.php               اجرای ربات با long polling
webhook.php           همان ربات، در حالت وبهوک (بدون پروسه‌ی دائمی)
cli.php               رابط خط فرمان + تماس مستقیم با API
src/CpanelClient.php  لایه‌ی API: UAPI، WHM API 1، API2 و احراز هویت با توکن
src/CpanelService.php کارهای واقعی (ایمیل، دیتابیس، DNS، WHM و ...) با خروجی نرمال‌شده
src/ServerStore.php   فهرست سرورها (از .env و از دیسک) و سرور فعال
src/UpdateHandler.php منوها و جریان‌های چندمرحله‌ای تلگرام
src/Render.php        متن‌های فارسی خروجی (HTML تلگرام، و نسخه‌ی ساده برای CLI)
src/State.php         وضعیت گفت‌وگوهای چندمرحله‌ای
src/AuditLog.php      دفترچه‌ی عملیات
tests/run.php         تست‌های آفلاین
```

## نکات فنی

- UAPI روی `https://<host>:2083/execute/<Module>/<function>` با هدر `Authorization: cpanel <user>:<token>` و WHM API 1 روی `https://<host>:2087/json-api/<function>?api.version=1` با هدر `Authorization: whm <user>:<token>` صدا زده می‌شوند. همه‌ی درخواست‌ها POST هستند تا رمزها در URL و لاگ وب‌سرور نیفتند.
- با توکن WHM، فراخوانی‌های سطح حساب از پراکسی `/json-api/cpanel` و با `cpanel_jsonapi_apiversion=3` به‌نام کاربر مقصد اجرا می‌شوند — یعنی یک توکن root برای مدیریت همه‌ی حساب‌ها کافی است.
- پاسخ‌های cPanel بین نسخه‌ها یک‌دست نیستند (گاهی لیست، گاهی دیکشنری، سهمیه گاهی مگابایت و گاهی بایت)؛ همه‌جا نرمال‌سازی می‌شود و ماژول‌هایی که روی سرور نصب نیستند فقط از خروجی حذف می‌شوند، نه اینکه کل دستور را بشکنند.
- رکوردهای DNS از `DNS::parse_zone` خوانده می‌شوند (فیلدها base64 هستند و دیکد می‌شوند) و افزودن/حذف با `DNS::mass_edit_zone` انجام می‌شود که سریال فعلی SOA را لازم دارد؛ سریال خودکار از خود زون خوانده می‌شود.
- پیام‌های بلند (مثل لیست ۲۰۰ ایمیل) خودکار روی مرز خط به چند پیام تقسیم می‌شوند تا به سقف ۴۰۹۶ کاراکتری تلگرام نخورند.
- حالت‌های نیمه‌کاره‌ی گفت‌وگو بعد از ۳۰ دقیقه منقضی می‌شوند.
- پروژه هیچ وابستگی خارجی (Composer) ندارد.
