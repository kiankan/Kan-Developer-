#!/usr/bin/env bash
# نصب/آپدیت کاملا خودکار ربات مدیریت cPanel روی هاست/سرورهای Docker-friendly.
#
# تعاملی (توکن ربات، آیدی ادمین و اتصال cPanel را می‌پرسد):
#   bash <(curl -Ls https://raw.githubusercontent.com/kiankan/kan_checker/main/install.sh)
#
# کاملا خودکار روی SSH بدون هیچ سوالی:
#   TELEGRAM_BOT_TOKEN=xxx ADMIN_ID=123456789 \
#     CPANEL_HOST=server.example.com CPANEL_USER=cpuser CPANEL_TOKEN=xxxx \
#     bash <(curl -Ls https://raw.githubusercontent.com/kiankan/kan_checker/main/install.sh)
#
# برای آپدیت هم کافیه دقیقا همین دستور رو دوباره اجرا کنید.

set -uo pipefail

REPO_URL="https://github.com/kiankan/kan_checker.git"
INSTALL_DIR="${KAN_CHECKER_DIR:-$HOME/kan_checker}"

if ! command -v docker >/dev/null 2>&1; then
  echo "Docker پیدا نشد؛ در حال نصب خودکار (اسکریپت رسمی get.docker.com) ..."
  curl -fsSL https://get.docker.com | sh
fi

if ! docker compose version >/dev/null 2>&1; then
  echo "پلاگین 'docker compose' پیدا نشد. لطفاً Docker را به‌روزرسانی کنید (نسخه‌های جدید Docker این پلاگین را همراه دارند)."
  exit 1
fi

if [ -d "$INSTALL_DIR/.git" ]; then
  echo "نصب قبلی پیدا شد، در حال به‌روزرسانی در $INSTALL_DIR ..."
  git -C "$INSTALL_DIR" pull --ff-only
else
  echo "دریافت پروژه در $INSTALL_DIR ..."
  git clone "$REPO_URL" "$INSTALL_DIR"
fi

cd "$INSTALL_DIR" || exit 1

# .env کلید‌به‌کلید به‌روز می‌شه تا تنظیمات قبلی (مثلا WHM یا چند ادمین) از
# بین نرن.
env_get() {
  [ -f .env ] || return 0
  grep "^$1=" .env 2>/dev/null | head -1 | cut -d= -f2-
}

env_set() {
  local key="$1" value="$2"
  touch .env
  if grep -q "^${key}=" .env 2>/dev/null; then
    grep -v "^${key}=" .env > .env.tmp && mv -f .env.tmp .env
  fi
  printf '%s=%s\n' "$key" "$value" >> .env
  chmod 600 .env
}

token="${TELEGRAM_BOT_TOKEN:-$(env_get TELEGRAM_BOT_TOKEN)}"
admin="${ADMIN_ID:-$(env_get ADMIN_ID)}"

if [ -z "$token" ] || [ "$token" = "123456:ABC-your-bot-token-from-BotFather" ]; then
  read -rp "توکن ربات تلگرام (از @BotFather) را وارد کنید: " token
fi
if [ -z "$admin" ] || [ "$admin" = "123456789" ]; then
  read -rp "آیدی عددی تلگرام ادمین (از @userinfobot بگیرید، نه یوزرنیم) را وارد کنید: " admin
fi

env_set TELEGRAM_BOT_TOKEN "$token"
env_set ADMIN_ID "$admin"

cp_host="${CPANEL_HOST:-$(env_get CPANEL_HOST)}"
cp_user="${CPANEL_USER:-$(env_get CPANEL_USER)}"
cp_token="${CPANEL_TOKEN:-$(env_get CPANEL_TOKEN)}"
cp_port="${CPANEL_PORT:-$(env_get CPANEL_PORT)}"

if [ -z "$cp_host" ] || [ "$cp_host" = "server.example.com" ]; then
  echo ""
  echo "— اتصال به cPanel (با Enter می‌توانید رد کنید و بعداً از داخل ربات سرور اضافه کنید) —"
  echo "  توکن را از cPanel → Security → Manage API Tokens بسازید."
  read -rp "آدرس سرور cPanel (مثل server.example.com): " cp_host
  if [ -n "$cp_host" ]; then
    read -rp "نام کاربری cPanel: " cp_user
    read -rp "API Token: " cp_token
    read -rp "پورت (Enter = 2083): " cp_port
  fi
fi

if [ -n "$cp_host" ]; then
  env_set CPANEL_HOST "$cp_host"
  env_set CPANEL_USER "$cp_user"
  env_set CPANEL_TOKEN "$cp_token"
  env_set CPANEL_PORT "${cp_port:-2083}"
fi

if [ -n "${WHM_HOST:-}" ]; then
  env_set WHM_HOST "$WHM_HOST"
  env_set WHM_USER "${WHM_USER:-root}"
  env_set WHM_TOKEN "${WHM_TOKEN:-}"
  env_set WHM_PORT "${WHM_PORT:-2087}"
fi

# سرورهایی که از داخل ربات اضافه می‌شن اینجا (بیرون از کانتینر) ذخیره می‌شن.
mkdir -p data
chmod 700 data

echo "ساخت و اجرای کانتینر ..."
docker compose up -d --build

echo ""
echo "نصب/آپدیت کامل شد و ربات در حال اجراست (فقط به آیدی $admin پاسخ می‌ده)."
echo "تست اتصال:  cd $INSTALL_DIR && docker compose exec bot php cli.php ping"
echo "لاگ زنده:   cd $INSTALL_DIR && docker compose logs -f"
echo "آپدیت بعدی: همین دستور نصب را دوباره اجرا کنید."
