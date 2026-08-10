#!/usr/bin/env bash
# نصب/آپدیت one-shot برای هاست‌های Docker-friendly (Railway/Render/Fly.io/هر VPS با Docker).
# اجرا: bash <(curl -Ls https://raw.githubusercontent.com/kiankan/kan_checker/main/install.sh)
# برای آپدیت هم کافیه دوباره همین دستور رو اجرا کنید.

set -uo pipefail

REPO_URL="https://github.com/kiankan/kan_checker.git"
INSTALL_DIR="${KAN_CHECKER_DIR:-$HOME/kan_checker}"

if ! command -v docker >/dev/null 2>&1; then
  echo "Docker پیدا نشد. ابتدا Docker را نصب کنید، مثلا:"
  echo "  curl -fsSL https://get.docker.com | sh"
  exit 1
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

current=""
if [ -f .env ]; then
  current=$(grep '^TELEGRAM_BOT_TOKEN=' .env 2>/dev/null | cut -d= -f2-)
fi

if [ -z "$current" ] || [ "$current" = "123456:ABC-your-bot-token-from-BotFather" ]; then
  read -rp "توکن ربات تلگرام (از @BotFather) را وارد کنید: " token
  echo "TELEGRAM_BOT_TOKEN=$token" > .env
fi

echo "ساخت و اجرای کانتینر ..."
docker compose up -d --build

echo ""
echo "نصب/آپدیت کامل شد و ربات در حال اجراست."
echo "لاگ زنده:   cd $INSTALL_DIR && docker compose logs -f"
echo "آپدیت بعدی: همین دستور نصب را دوباره اجرا کنید."
