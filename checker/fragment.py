"""Fetch and parse auction/status info for a Fragment.com item (username or number)."""

import re
from datetime import datetime
from urllib.parse import unquote

import requests
from bs4 import BeautifulSoup

from .format import seconds_until

USER_AGENT = (
    "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 "
    "(KHTML, like Gecko) Chrome/120.0 Safari/537.36"
)
REQUEST_TIMEOUT = 15


class FragmentError(Exception):
    pass


class FragmentNotFoundError(FragmentError):
    pass


def resolve_fragment_url(raw: str) -> str:
    """Accept a bare username, a fragment.com URL, or a wallet deep-link
    (e.g. Tonkeeper's /dapp/<url-encoded fragment.com link>) and return a
    plain https://fragment.com/... URL."""
    raw = raw.strip()

    if "/dapp/" in raw:
        raw = raw.split("/dapp/", 1)[1]

    raw = unquote(raw)

    if "fragment.com" in raw:
        if not raw.startswith("http"):
            raw = "https://" + raw.lstrip("/")
        return raw

    username = raw.lstrip("@")
    if not re.match(r"^[A-Za-z0-9_]{3,}$", username):
        raise FragmentError(f"ورودی نامعتبر است: {raw!r}")
    return f"https://fragment.com/username/{username}"


def fetch_auction(raw: str) -> dict:
    url = resolve_fragment_url(raw)

    try:
        resp = requests.get(url, headers={"User-Agent": USER_AGENT}, timeout=REQUEST_TIMEOUT)
    except requests.RequestException as exc:
        raise FragmentError(f"خطا در اتصال به Fragment: {exc}") from exc

    if resp.status_code == 404:
        raise FragmentNotFoundError(f"آیتمی با این آدرس روی Fragment پیدا نشد: {url}")
    if resp.status_code != 200:
        raise FragmentError(f"Fragment پاسخ غیرمنتظره داد (HTTP {resp.status_code})")

    soup = BeautifulSoup(resp.text, "html.parser")

    header = soup.select_one("h2.tm-section-header-text")
    if header is None:
        raise FragmentNotFoundError(f"اطلاعاتی برای این آیتم پیدا نشد: {url}")

    domain_el = header.select_one(".tm-section-header-domain")
    name = domain_el.get_text(strip=True) if domain_el else raw

    status_el = header.select_one(".tm-section-header-status")
    status_text = status_el.get_text(strip=True) if status_el else "نامشخص"
    status_slug = None
    if status_el is not None:
        for cls in status_el.get("class", []):
            if cls.startswith("tm-status-"):
                status_slug = cls.replace("tm-status-", "")

    bids = [
        el.get_text(strip=True)
        for el in soup.select(".tm-section-bid-info .table-cell-value.tm-value")
    ]
    highest_bid = bids[0] if len(bids) > 0 else None
    bid_step = bids[1] if len(bids) > 1 else None
    minimum_bid = bids[2] if len(bids) > 2 else None

    ends_at = None
    remaining_seconds = None
    timer_el = soup.select_one("time.tm-countdown-timer")
    if timer_el is not None and timer_el.get("datetime"):
        try:
            ends_at = datetime.fromisoformat(timer_el["datetime"])
            remaining_seconds = max(0, seconds_until(ends_at))
        except ValueError:
            ends_at = None

    return {
        "query": raw,
        "url": url,
        "name": name,
        "status_text": status_text,
        "status_slug": status_slug,
        "highest_bid": highest_bid,
        "bid_step": bid_step,
        "minimum_bid": minimum_bid,
        "ends_at": ends_at,
        "remaining_seconds": remaining_seconds,
    }
