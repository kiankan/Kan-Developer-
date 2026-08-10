"""Shared text rendering for both the Telegram bot and the CLI."""

from .format import format_utc, humanize_duration


def render_auction(data: dict) -> str:
    lines = [f"🎯 {data['name']}", f"وضعیت: {data['status_text']}"]

    if data["highest_bid"] is not None:
        lines.append(f"بالاترین پیشنهاد: {data['highest_bid']} TON")
    if data["bid_step"] is not None:
        lines.append(f"گام مزایده: {data['bid_step']} TON")
    if data["minimum_bid"] is not None:
        lines.append(f"حداقل پیشنهاد بعدی: {data['minimum_bid']} TON")

    if data["ends_at"] is not None:
        remaining = data["remaining_seconds"]
        lines.append("")
        lines.append(f"⏳ زمان باقی‌مانده: {humanize_duration(remaining)} ({remaining} ثانیه)")
        lines.append(f"پایان مزایده: {format_utc(data['ends_at'])}")
    else:
        lines.append("")
        lines.append("⏳ شمارش‌معکوس فعالی برای این آیتم پیدا نشد (مزایده تمام شده یا در وضعیت دیگری است).")

    lines.append("")
    lines.append(data["url"])
    return "\n".join(lines)


def render_tx(data: dict) -> str:
    status = "موفق ✅" if data["success"] and not data["aborted"] else "ناموفق ❌"
    lines = [f"🔗 تراکنش TON", f"هش: {data['hash']}", f"وضعیت: {status}"]

    if data["utime"] is not None:
        lines.append(f"زمان: {format_utc(data['utime'])}")
    if data["account_address"]:
        name = f" ({data['account_name']})" if data["account_name"] else ""
        lines.append(f"حساب: {data['account_address']}{name}")
    if data["exit_code_description"]:
        lines.append(f"نتیجه اجرا: {data['exit_code_description']}")
    lines.append(f"کارمزد کل: {data['total_fees_ton']:.6f} TON")

    return "\n".join(lines)
