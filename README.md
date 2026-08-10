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

## دیپلوی روی سرور (VPS) با systemd

```bash
sudo mkdir -p /opt/kan_checker
sudo useradd -r -s /usr/sbin/nologin kan_checker  # اگر از قبل نیست
cd /opt/kan_checker
git clone https://github.com/kiankan/kan_checker.git .
python3 -m venv .venv
.venv/bin/pip install -r requirements.txt
cp .env.example .env   # و توکن رو داخلش بگذارید
sudo chown -R kan_checker:kan_checker /opt/kan_checker

sudo cp deploy/kan_checker-bot.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now kan_checker-bot
sudo systemctl status kan_checker-bot
```

لاگ‌ها: `journalctl -u kan_checker-bot -f`

## دیپلوی با Docker (روی سرور یا هر هاستِ Docker-friendly مثل Railway / Render / Fly.io)

```bash
cp .env.example .env   # و توکن رو داخلش بگذارید
docker compose up -d --build
```

یا با Dockerfile خام:

```bash
docker build -t kan_checker .
docker run -d --restart unless-stopped --env-file .env --name kan_checker kan_checker
```

برای هاست‌هایی که از `Procfile` پشتیبانی می‌کنن (مثل Railway بدون Docker)، همون فایل `Procfile` موجود در ریشه‌ی پروژه استفاده می‌شه (`worker: python bot.py`) — کافیه متغیر محیطی `TELEGRAM_BOT_TOKEN` رو در پنل هاست تنظیم کنید.

## نکات فنی

- داده‌ی مزایده مستقیماً از HTML صفحه‌ی Fragment.com خونده می‌شه (Fragment فعلاً API عمومی رسمی نداره)، پس اگر ساختار سایت عوض بشه ممکنه پارسر نیاز به به‌روزرسانی داشته باشه.
- زمان پایان مزایده از attribute استاندارد `datetime` روی تگ `<time class="tm-countdown-timer">` صفحه‌ی Fragment خونده و به ثانیه‌ی دقیق تبدیل می‌شه.
- برای تراکنش‌های TON از API عمومی و رایگان [tonapi.io](https://tonapi.io) استفاده شده (نیازی به کلید API برای استفاده‌ی سبک نیست).
