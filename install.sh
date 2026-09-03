#!/usr/bin/env bash
# KanPanel — نصب یک‌خطی روی سرور.
#
#   bash <(curl -Ls https://raw.githubusercontent.com/kiankan/kan_checker/main/install.sh)
#
# فقط توکن ربات تلگرام را می‌پرسد. بقیه‌ی تنظیمات (سرورهای cPanel/WHM،
# ادمین‌ها و ...) بعد از نصب، از داخل خود ربات انجام می‌شود.
#
# کاملا خودکار (بدون هیچ سوالی):
#   TELEGRAM_BOT_TOKEN=xxx bash <(curl -Ls .../install.sh)
#
# همین دستور برای آپدیت هم کار می‌کند.

set -uo pipefail

RAW_URL="${KANPANEL_RAW_URL:-https://raw.githubusercontent.com/kiankan/kan_checker/main}"

if [ "$(id -u)" -ne 0 ]; then
  echo "نصب روی سرور نیاز به root دارد؛ با sudo اجرا کنید:"
  echo "  sudo bash <(curl -Ls $RAW_URL/install.sh)"
  exit 1
fi

if [ -f "./kanpanel" ]; then
  bash ./kanpanel install
elif [ -x /usr/local/bin/kanpanel ]; then
  /usr/local/bin/kanpanel update
else
  bash <(curl -Ls "$RAW_URL/kanpanel") install
fi
