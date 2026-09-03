#!/usr/bin/env bash
# cPanel TeamKan — نصب یک‌خطی روی سرور.
#
#   bash <(curl -Ls https://raw.githubusercontent.com/kiankan/cpanel_teamkan/main/install.sh)
#
# فقط توکن ربات تلگرام را می‌پرسد. بقیه‌ی تنظیمات (سرورهای cPanel/WHM،
# ادمین‌ها و ...) بعد از نصب، از داخل خود ربات انجام می‌شود.
#
# کاملا خودکار (بدون هیچ سوالی):
#   TELEGRAM_BOT_TOKEN=xxx bash <(curl -Ls .../install.sh)
#
# همین دستور برای آپدیت هم کار می‌کند.

set -uo pipefail

RAW_URL="${TEAMKAN_RAW_URL:-https://raw.githubusercontent.com/kiankan/cpanel_teamkan/main}"

if [ "$(id -u)" -ne 0 ]; then
  echo "نصب روی سرور نیاز به root دارد؛ با sudo اجرا کنید:"
  echo "  sudo bash <(curl -Ls $RAW_URL/install.sh)"
  exit 1
fi

if [ -f "./teamkan" ]; then
  bash ./teamkan install
elif [ -x /usr/local/bin/teamkan ]; then
  /usr/local/bin/teamkan update
else
  bash <(curl -Ls "$RAW_URL/teamkan") install
fi
