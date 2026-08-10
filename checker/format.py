from datetime import datetime, timezone


def humanize_duration(total_seconds: int) -> str:
    """Format seconds as '6 روز 01:34:16' style, Persian day label."""
    total_seconds = max(0, int(total_seconds))
    days, rem = divmod(total_seconds, 86400)
    hours, rem = divmod(rem, 3600)
    minutes, seconds = divmod(rem, 60)

    parts = []
    if days:
        parts.append(f"{days} روز")
    parts.append(f"{hours:02d}:{minutes:02d}:{seconds:02d}")
    return " ".join(parts)


def format_utc(dt: datetime) -> str:
    return dt.astimezone(timezone.utc).strftime("%Y-%m-%d %H:%M:%S UTC")


def seconds_until(dt: datetime) -> int:
    now = datetime.now(timezone.utc)
    return int((dt.astimezone(timezone.utc) - now).total_seconds())


def seconds_since(dt: datetime) -> int:
    now = datetime.now(timezone.utc)
    return int((now - dt.astimezone(timezone.utc)).total_seconds())
