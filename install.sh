#!/usr/bin/env bash
# نصب/آپدیت کاملا خودکار برای هاست/سرورهای Docker-friendly.
#
# تعاملی (توکن و آیدی ادمین رو می‌پرسه):
#   bash <(curl -Ls https://raw.githubusercontent.com/kiankan/kan_checker/main/install.sh)
#
# کاملا خودکار روی SSH بدون هیچ سوالی (فقط توکن و آیدی ادمین رو می‌دید):
#   TELEGRAM_BOT_TOKEN=xxx ADMIN_ID=123456789 \
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

cd "$INSTALL_DIR"

current_token=""
current_admin=""
if [ -f .env ]; then
  current_token=$(grep '^TELEGRAM_BOT_TOKEN=' .env 2>/dev/null | cut -d= -f2-)
  current_admin=$(grep '^ADMIN_ID=' .env 2>/dev/null | cut -d= -f2-)
fi

# اگر از قبل به‌صورت متغیر محیطی داده شده باشن، هیچ سوالی پرسیده نمی‌شه:
token="${TELEGRAM_BOT_TOKEN:-$current_token}"
admin="${ADMIN_ID:-$current_admin}"

if [ -z "$token" ] || [ "$token" = "123456:ABC-your-bot-token-from-BotFather" ]; then
  read -rp "توکن ربات تلگرام (از @BotFather) را وارد کنید: " token
fi
if [ -z "$admin" ] || [ "$admin" = "123456789" ]; then
  read -rp "آیدی عددی تلگرام ادمین (از @userinfobot بگیرید، نه یوزرنیم) را وارد کنید: " admin
fi

{
  echo "TELEGRAM_BOT_TOKEN=$token"
  echo "ADMIN_ID=$admin"
} > .env

echo "ساخت و اجرای کانتینر ..."
docker compose up -d --build

echo ""
echo "نصب/آپدیت کامل شد و ربات در حال اجراست (فقط به آیدی $admin پاسخ می‌ده)."
echo "لاگ زنده:   cd $INSTALL_DIR && docker compose logs -f"
echo "آپدیت بعدی: همین دستور نصب را دوباره اجرا کنید."
