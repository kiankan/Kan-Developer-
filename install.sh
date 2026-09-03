#!/usr/bin/env bash
# cPanel TeamKan — نصب یک‌خطی روی سرور.
#
# هر سه شکل زیر کار می‌کند (حتی اگر با لوله یا با sudo اجرا شود):
#   curl -fsSL https://raw.githubusercontent.com/kiankan/cpanel_teamkan/main/install.sh | sudo bash
#   sudo bash -c "$(curl -fsSL https://raw.githubusercontent.com/kiankan/cpanel_teamkan/main/install.sh)"
#   bash <(curl -fsSL https://raw.githubusercontent.com/kiankan/cpanel_teamkan/main/install.sh)
#
# فقط توکن ربات تلگرام را می‌پرسد. بقیه‌ی تنظیمات (سرورهای cPanel/WHM،
# ادمین‌ها و ...) بعد از نصب، از داخل خود ربات انجام می‌شود.
#
# کاملا خودکار، بدون هیچ سوالی (توجه: متغیر باید به bash برسد، نه به curl):
#   curl -fsSL .../install.sh | sudo env TELEGRAM_BOT_TOKEN=xxx bash
#
# همین دستور برای آپدیت هم کار می‌کند.

set -uo pipefail

RAW_URL="${TEAMKAN_RAW_URL:-https://raw.githubusercontent.com/kiankan/cpanel_teamkan/main}"

if [ "$(id -u)" -ne 0 ]; then
  echo "نصب روی سرور نیاز به root دارد. این را بزنید:"
  echo "  curl -fsSL $RAW_URL/install.sh | sudo bash"
  exit 1
fi

if ! command -v curl >/dev/null 2>&1; then
  echo "curl نصب نیست؛ اول نصبش کنید (مثلاً: apt-get install -y curl)."
  exit 1
fi

# اسکریپت اصلی را روی دیسک می‌گیریم و اجرا می‌کنیم. عمداً از <(curl ...)
# استفاده نمی‌کنیم: sudo توصیفگرهای اضافه را می‌بندد و آن شکل با
# «/dev/fd/63: No such file or directory» می‌افتد.
if [ -f "./teamkan" ]; then
  bash ./teamkan install
  exit $?
fi

if [ -x /usr/local/bin/teamkan ]; then
  /usr/local/bin/teamkan update
  exit $?
fi

tmp=$(mktemp /tmp/teamkan.XXXXXX) || exit 1
trap 'rm -f "$tmp"' EXIT

if ! curl -fsSL "$RAW_URL/teamkan" -o "$tmp"; then
  echo "دانلود اسکریپت نصب ناموفق بود؛ اتصال سرور به گیت‌هاب را بررسی کنید."
  exit 1
fi

bash "$tmp" install
