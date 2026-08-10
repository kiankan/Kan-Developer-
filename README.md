# kan_checker

ربات چکر هش تراکنش TON و وضعیت/زمان باقی‌مانده مزایده‌های Fragment.com (یوزرنیم و شماره).

هیچ زمان‌بندی ثابتی نداره — فقط وقتی که پیام بفرستید (on-demand) جواب می‌ده، با شمارش‌معکوس دقیق تا ثانیه.

## قابلیت‌ها

- `/auction <یوزرنیم | لینک fragment.com | لینک والت مثل Tonkeeper>`
  وضعیت مزایده، بالاترین پیشنهاد، حداقل پیشنهاد بعدی و زمان دقیق باقی‌مانده (روز/ساعت/دقیقه/ثانیه) رو برمی‌گردونه.
  لینک‌های دیپ‌لینک والت مثل
  `https://app.tonkeeper.com/dapp/https%3A%2F%2Ffragment.com%2Fusername%2Fesalatjonob`
  هم پشتیبانی می‌شن (به‌صورت خودکار دیکد و به لینک fragment.com واقعی تبدیل می‌شن).

- `/tx <هش تراکنش>` — وضعیت یک تراکنش روی بلاکچین TON (موفق/ناموفق، زمان، حساب، کارمزد) از طریق [tonapi.io](https://tonapi.io).

- `/check <هر ورودی>` — تشخیص خودکار می‌ده که ورودی هش تراکنشه یا آیتم Fragment، و دستور مناسب رو اجرا می‌کنه. همچنین اگه فقط متن رو بدون دستور بفرستید هم همین رفتار انجام می‌شه.

همه‌چیز به‌صورت CLI هم در دسترسه (بدون نیاز به تلگرام):

```bash
python cli.py auction esalatjonob
python cli.py tx <hash>
python cli.py check esalatjonob
```

## نصب و اجرای محلی

```bash
python -m venv .venv
source .venv/bin/activate
pip install -r requirements.txt
cp .env.example .env   # و TELEGRAM_BOT_TOKEN رو از @BotFather بگذارید
python bot.py
```

## نصب روی سرور (VPS) — با منوی مدیریت

نصب اولیه (فقط یک‌بار، به‌عنوان root یا با sudo):

```bash
sudo bash <(curl -Ls https://raw.githubusercontent.com/kiankan/kan_checker/main/kancheckerctl) install
```

این دستور پایتون/venv/گیت رو نصب می‌کنه، پروژه رو در `/opt/kan_checker` کلون می‌کنه، توکن ربات رو ازتون می‌پرسه، سرویس systemd رو می‌سازه و روشنش می‌کنه، و خودش رو به‌صورت دستور `kancheckerctl` در سیستم ثبت می‌کنه.

از این به بعد کافیه فقط بنویسید:

```bash
kancheckerctl
```

و این منو باز می‌شه:

```
==== kan_checker — منوی مدیریت سرور ====
1) نصب
2) آپدیت
3) شروع سرویس
4) توقف سرویس
5) ری‌استارت سرویس
6) وضعیت سرویس
7) نمایش لاگ (زنده)
8) تنظیم/تغییر توکن ربات
9) حذف کامل
0) خروج
```

هر گزینه رو هم می‌شه مستقیم بدون منو صدا زد، مثلاً برای آپدیت:

```bash
sudo kancheckerctl update
```

یا: `kancheckerctl status` / `kancheckerctl logs` / `kancheckerctl restart` / `kancheckerctl set-token` / `kancheckerctl uninstall`

## نصب روی هاست (Docker) — یک دستور، هم نصب هم آپدیت

روی هر هاست/سروری که Docker داره (Railway، Render، Fly.io، یا هر VPS با Docker نصب‌شده):

```bash
bash <(curl -Ls https://raw.githubusercontent.com/kiankan/kan_checker/main/install.sh)
```

بار اول توکن ربات رو ازتون می‌پرسه و کانتینر رو بالا میاره. برای **آپدیت** بعدی، کافیه دقیقاً همین دستور رو دوباره اجرا کنید — پروژه رو pull می‌کنه و کانتینر رو با تغییرات جدید rebuild و ریستارت می‌کنه.

لاگ زنده: `cd ~/kan_checker && docker compose logs -f`

## نصب دستی (اگر نخواستید از اسکریپت‌ها استفاده کنید)

<details>
<summary>systemd دستی</summary>

```bash
sudo mkdir -p /opt/kan_checker
cd /opt/kan_checker
sudo git clone https://github.com/kiankan/kan_checker.git .
sudo python3 -m venv .venv
sudo .venv/bin/pip install -r requirements.txt
sudo cp .env.example .env   # و توکن رو داخلش بگذارید
sudo cp deploy/kan_checker-bot.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now kan_checker-bot
```

</details>

<details>
<summary>Docker دستی</summary>

```bash
cp .env.example .env   # و توکن رو داخلش بگذارید
docker compose up -d --build
```

برای هاست‌هایی که از `Procfile` پشتیبانی می‌کنن (مثل Railway بدون Docker)، همون فایل `Procfile` موجود در ریشه‌ی پروژه استفاده می‌شه (`worker: python bot.py`) — کافیه متغیر محیطی `TELEGRAM_BOT_TOKEN` رو در پنل هاست تنظیم کنید.

</details>

## نکات فنی

- داده‌ی مزایده مستقیماً از HTML صفحه‌ی Fragment.com خونده می‌شه (Fragment فعلاً API عمومی رسمی نداره)، پس اگر ساختار سایت عوض بشه ممکنه پارسر نیاز به به‌روزرسانی داشته باشه.
- زمان پایان مزایده از attribute استاندارد `datetime` روی تگ `<time class="tm-countdown-timer">` صفحه‌ی Fragment خونده و به ثانیه‌ی دقیق تبدیل می‌شه.
- برای تراکنش‌های TON از API عمومی و رایگان [tonapi.io](https://tonapi.io) استفاده شده (نیازی به کلید API برای استفاده‌ی سبک نیست).
