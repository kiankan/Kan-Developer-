#!/usr/bin/env bash
# نصب‌کننده‌ی ربات مدیریت cPanel — با منو.
#
#   bash <(curl -Ls https://raw.githubusercontent.com/kiankan/kan_checker/main/install.sh)
#
# سه جور نصب پشتیبانی می‌شود:
#   1) سرور (root + systemd)  — ربات به‌صورت سرویس، به‌علاوه‌ی صفحه‌ی وضعیت روی پورت 2000
#   2) Docker                 — برای هر هاست/VPS با داکر
#   3) هاست اشتراکی cPanel    — بدون root و بدون پروسه‌ی دائمی، با کران هر دقیقه‌ای
#
# بدون منو هم می‌شود مستقیم صدا زد:
#   bash install.sh server | docker | shared | update | status | logs | uninstall
#
# و کاملا خودکار (بدون هیچ سوالی) با متغیرهای محیطی:
#   TELEGRAM_BOT_TOKEN=xxx ADMIN_ID=123 CPANEL_HOST=srv.example.com:2083 \
#     CPANEL_USER=cpuser CPANEL_TOKEN=xxxx bash install.sh docker

set -uo pipefail

REPO_URL="https://github.com/kiankan/kan_checker.git"
RAW_URL="https://raw.githubusercontent.com/kiankan/kan_checker/main"
INSTALL_DIR="${KAN_CHECKER_DIR:-$HOME/kan_checker}"
DEFAULT_LISTEN_PORT=2000

say()  { printf '%s\n' "$*"; }
fail() { printf '%s\n' "خطا: $*" >&2; }

# ---------------------------------------------------------------- کمکی‌ها ---

env_get() {
  [ -f "$INSTALL_DIR/.env" ] || return 0
  grep "^$1=" "$INSTALL_DIR/.env" 2>/dev/null | head -1 | cut -d= -f2-
}

env_set() {
  local key="$1" value="$2" file="$INSTALL_DIR/.env"
  mkdir -p "$INSTALL_DIR"
  touch "$file"
  if grep -q "^${key}=" "$file" 2>/dev/null; then
    grep -v "^${key}=" "$file" > "${file}.tmp" && mv -f "${file}.tmp" "$file"
  fi
  printf '%s=%s\n' "$key" "$value" >> "$file"
  chmod 600 "$file"
}

fetch_project() {
  if [ -f "./bot.php" ] && [ -f "./cli.php" ]; then
    INSTALL_DIR="$(pwd)"
    say "پروژه در همین پوشه پیدا شد: $INSTALL_DIR"
    return 0
  fi
  if [ -d "$INSTALL_DIR/.git" ]; then
    say "نصب قبلی پیدا شد، در حال به‌روزرسانی در $INSTALL_DIR ..."
    git -C "$INSTALL_DIR" pull --ff-only
  else
    say "دریافت پروژه در $INSTALL_DIR ..."
    git clone "$REPO_URL" "$INSTALL_DIR" || return 1
  fi
}

prompt_telegram() {
  local token admin
  token="${TELEGRAM_BOT_TOKEN:-$(env_get TELEGRAM_BOT_TOKEN)}"
  admin="${ADMIN_ID:-$(env_get ADMIN_ID)}"

  if [ -z "$token" ] || [ "$token" = "123456:ABC-your-bot-token-from-BotFather" ]; then
    read -rp "توکن ربات تلگرام (از @BotFather): " token
  fi
  if [ -z "$admin" ] || [ "$admin" = "123456789" ]; then
    read -rp "آیدی عددی تلگرام ادمین (از @userinfobot): " admin
  fi

  env_set TELEGRAM_BOT_TOKEN "$token"
  env_set ADMIN_ID "$admin"
}

prompt_cpanel() {
  local host user token port
  host="${CPANEL_HOST:-$(env_get CPANEL_HOST)}"
  user="${CPANEL_USER:-$(env_get CPANEL_USER)}"
  token="${CPANEL_TOKEN:-$(env_get CPANEL_TOKEN)}"
  port="${CPANEL_PORT:-$(env_get CPANEL_PORT)}"

  if [ -z "$host" ] || [ "$host" = "server.example.com" ]; then
    say ""
    say "— اتصال به cPanel (با Enter می‌توانید رد کنید و بعداً از داخل ربات سرور اضافه کنید) —"
    say "  توکن را از cPanel → Security → Manage API Tokens بسازید."
    say "  آدرس را می‌توانید با پورت بدهید، مثل server.example.com:2083"
    read -rp "آدرس سرور cPanel: " host
    if [ -n "$host" ]; then
      read -rp "نام کاربری cPanel: " user
      read -rp "API Token: " token
      read -rp "پورت (Enter = داخل آدرس یا 2083): " port
    fi
  fi

  if [ -n "$host" ]; then
    env_set CPANEL_HOST "$host"
    env_set CPANEL_USER "$user"
    env_set CPANEL_TOKEN "$token"
    [ -n "$port" ] && env_set CPANEL_PORT "$port"
  fi

  if [ -n "${WHM_HOST:-}" ]; then
    env_set WHM_HOST "$WHM_HOST"
    env_set WHM_USER "${WHM_USER:-root}"
    env_set WHM_TOKEN "${WHM_TOKEN:-}"
    env_set WHM_PORT "${WHM_PORT:-2087}"
  fi
}

secure_files() {
  mkdir -p "$INSTALL_DIR/data"
  chmod 700 "$INSTALL_DIR/data" 2>/dev/null
  [ -f "$INSTALL_DIR/.env" ] && chmod 600 "$INSTALL_DIR/.env" 2>/dev/null
  return 0
}

php_binary() {
  local candidate
  for candidate in /usr/local/bin/php /usr/bin/php php; do
    if command -v "$candidate" >/dev/null 2>&1; then
      command -v "$candidate"
      return 0
    fi
  done
  return 1
}

test_connection() {
  local php_bin
  php_bin=$(php_binary) || { say "PHP پیدا نشد؛ تست اتصال رد شد."; return 0; }
  say ""
  say "— تست اتصال —"
  ( cd "$INSTALL_DIR" && "$php_bin" cli.php ping )
}

# ------------------------------------------------------------- نصب‌ها ---

install_server() {
  if [ "$(id -u)" -ne 0 ]; then
    fail "نصب روی سرور نیاز به root دارد. با sudo اجرا کنید، یا گزینه‌ی «هاست اشتراکی» را بزنید."
    return 1
  fi

  # منطق نصب سرور کامل در kancheckerctl است (systemd، سرویس وب پورت 2000، منوی مدیریت).
  if [ -f "./kancheckerctl" ]; then
    bash ./kancheckerctl install
  else
    bash <(curl -Ls "$RAW_URL/kancheckerctl") install
  fi
}

install_docker() {
  if ! command -v docker >/dev/null 2>&1; then
    say "Docker پیدا نشد؛ در حال نصب خودکار (اسکریپت رسمی get.docker.com) ..."
    curl -fsSL https://get.docker.com | sh
  fi
  if ! docker compose version >/dev/null 2>&1; then
    fail "پلاگین 'docker compose' پیدا نشد. Docker را به‌روزرسانی کنید."
    return 1
  fi

  fetch_project || return 1
  prompt_telegram
  prompt_cpanel
  local port
  port="${LISTEN_PORT:-$(env_get LISTEN_PORT)}"
  env_set LISTEN_PORT "${port:-$DEFAULT_LISTEN_PORT}"
  secure_files

  say "ساخت و اجرای کانتینرها ..."
  ( cd "$INSTALL_DIR" && docker compose up -d --build )

  say ""
  say "نصب/آپدیت کامل شد."
  say "صفحه‌ی وضعیت: http://<آی‌پی سرور>:$(env_get LISTEN_PORT)/"
  say "تست اتصال:  cd $INSTALL_DIR && docker compose exec bot php cli.php ping"
  say "لاگ زنده:   cd $INSTALL_DIR && docker compose logs -f"
}

install_shared() {
  say "— نصب روی هاست اشتراکی cPanel (بدون root) —"
  fetch_project || return 1
  prompt_telegram
  prompt_cpanel
  secure_files

  local php_bin cron_line
  php_bin=$(php_binary) || { fail "PHP روی این هاست پیدا نشد."; return 1; }
  cron_line="* * * * * $php_bin $INSTALL_DIR/poll.php >/dev/null 2>&1"

  say ""
  say "روی هاست اشتراکی ربات با یک کران هر دقیقه‌ای اجرا می‌شود (نه پروسه‌ی دائمی)."

  if command -v crontab >/dev/null 2>&1; then
    if crontab -l 2>/dev/null | grep -Fq "$INSTALL_DIR/poll.php"; then
      say "✅ کران از قبل تنظیم شده بود."
    else
      ( crontab -l 2>/dev/null; echo "$cron_line" ) | crontab - \
        && say "✅ کران اضافه شد:" \
        || { say "⚠️ نتوانستم خودکار اضافه کنم؛ این خط را از cPanel → Cron Jobs اضافه کنید:"; }
      say "   $cron_line"
    fi
  else
    say "⚠️ دستور crontab در دسترس نیست. این خط را از cPanel → Cron Jobs اضافه کنید:"
    say "   $cron_line"
  fi

  say ""
  say "اگر دامنه‌ی HTTPS دارید، به‌جای کران می‌توانید وبهوک بگذارید (سریع‌تر جواب می‌دهد):"
  say "   $php_bin $INSTALL_DIR/cli.php webhook:set https://example.com/kan/webhook.php"
  say "   (فقط webhook.php باید داخل public_html باشد؛ بقیه‌ی پروژه بیرون از آن بماند.)"

  test_connection
}

# --------------------------------------------------------------- عمومی ---

detect_install() {
  if [ -f /etc/systemd/system/kan_checker-bot.service ]; then
    echo server
  elif [ -f "$INSTALL_DIR/docker-compose.yml" ] && command -v docker >/dev/null 2>&1 \
       && docker compose -f "$INSTALL_DIR/docker-compose.yml" ps 2>/dev/null | grep -q .; then
    echo docker
  elif crontab -l 2>/dev/null | grep -Fq "poll.php"; then
    echo shared
  else
    echo none
  fi
}

do_update() {
  case "$(detect_install)" in
    server) install_server ;;
    docker) install_docker ;;
    shared) fetch_project && secure_files && say "پروژه به‌روزرسانی شد؛ کران خودش نسخه‌ی جدید را اجرا می‌کند." ;;
    *)      say "نصب فعالی پیدا نشد. اول یکی از گزینه‌های نصب را بزنید." ;;
  esac
}

do_status() {
  case "$(detect_install)" in
    server) systemctl status kan_checker-bot --no-pager; systemctl status kan_checker-web --no-pager ;;
    docker) ( cd "$INSTALL_DIR" && docker compose ps ) ;;
    shared) crontab -l 2>/dev/null | grep poll.php || say "کرانی پیدا نشد." ;;
    *)      say "نصب فعالی پیدا نشد." ;;
  esac
  test_connection
}

do_logs() {
  case "$(detect_install)" in
    server) journalctl -u kan_checker-bot -u kan_checker-web -f -n 100 ;;
    docker) ( cd "$INSTALL_DIR" && docker compose logs -f ) ;;
    *)      say "برای این نوع نصب لاگ زنده‌ای نداریم؛ خروجی کران را از cPanel ببینید." ;;
  esac
}

do_uninstall() {
  local confirm=""
  read -rp "مطمئنید می‌خواهید حذف شود؟ (y/N): " confirm
  [ "$confirm" != "y" ] && [ "$confirm" != "Y" ] && { say "لغو شد."; return; }

  case "$(detect_install)" in
    server) bash "${INSTALL_DIR}/kancheckerctl" uninstall 2>/dev/null || bash <(curl -Ls "$RAW_URL/kancheckerctl") uninstall ;;
    docker) ( cd "$INSTALL_DIR" && docker compose down ) ;;
    shared) crontab -l 2>/dev/null | grep -Fv "poll.php" | crontab - && say "کران حذف شد." ;;
  esac
  say "برای پاک‌کردن کامل فایل‌ها: rm -rf $INSTALL_DIR"
}

show_menu() {
  while true; do
    say ""
    say "==== نصب kan_checker — ربات مدیریت cPanel ===="
    say "1) نصب روی سرور (root + systemd، سرویس دائمی + پورت $DEFAULT_LISTEN_PORT)"
    say "2) نصب با Docker"
    say "3) نصب روی هاست اشتراکی cPanel (بدون root، با کران)"
    say "4) آپدیت"
    say "5) وضعیت"
    say "6) لاگ زنده"
    say "7) حذف"
    say "0) خروج"
    read -rp "انتخاب شما: " choice
    case "$choice" in
      1) install_server ;;
      2) install_docker ;;
      3) install_shared ;;
      4) do_update ;;
      5) do_status ;;
      6) do_logs ;;
      7) do_uninstall ;;
      0) exit 0 ;;
      *) say "گزینه نامعتبر است." ;;
    esac
  done
}

case "${1:-menu}" in
  server|vps|systemd) install_server ;;
  docker)             install_docker ;;
  shared|cpanel)      install_shared ;;
  update)             do_update ;;
  status)             do_status ;;
  logs)               do_logs ;;
  uninstall|remove)   do_uninstall ;;
  menu|"")            show_menu ;;
  *)
    fail "گزینه نامعتبر: $1"
    say "گزینه‌های معتبر: server, docker, shared, update, status, logs, uninstall, menu"
    exit 1
    ;;
esac
