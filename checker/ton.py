"""Look up a TON blockchain transaction by hash via the public tonapi.io API."""

import re
from datetime import datetime, timezone

import requests

API_BASE = "https://tonapi.io/v2"
REQUEST_TIMEOUT = 15
NANOTON = 10**9

HEX_HASH_RE = re.compile(r"^[0-9a-fA-F]{64}$")
BASE64_HASH_RE = re.compile(r"^[A-Za-z0-9+/_-]{43,44}={0,2}$")


class TonError(Exception):
    pass


class TonTxNotFoundError(TonError):
    pass


def looks_like_tx_hash(value: str) -> bool:
    value = value.strip()
    return bool(HEX_HASH_RE.match(value) or BASE64_HASH_RE.match(value))


def fetch_transaction(tx_hash: str) -> dict:
    tx_hash = tx_hash.strip()

    try:
        resp = requests.get(f"{API_BASE}/blockchain/transactions/{tx_hash}", timeout=REQUEST_TIMEOUT)
    except requests.RequestException as exc:
        raise TonError(f"خطا در اتصال به TonAPI: {exc}") from exc

    if resp.status_code == 404:
        raise TonTxNotFoundError(f"تراکنشی با این هش پیدا نشد: {tx_hash}")
    if resp.status_code != 200:
        raise TonError(f"TonAPI پاسخ غیرمنتظره داد (HTTP {resp.status_code}): {resp.text[:200]}")

    data = resp.json()
    compute_phase = data.get("compute_phase") or {}
    account = data.get("account") or {}

    return {
        "hash": data.get("hash", tx_hash),
        "success": bool(data.get("success")),
        "aborted": bool(data.get("aborted")),
        "utime": datetime.fromtimestamp(data["utime"], tz=timezone.utc) if data.get("utime") else None,
        "account_address": account.get("address"),
        "account_name": account.get("name"),
        "total_fees_ton": data.get("total_fees", 0) / NANOTON,
        "exit_code_description": compute_phase.get("exit_code_description"),
        "transaction_type": data.get("transaction_type"),
    }
