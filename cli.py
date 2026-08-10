#!/usr/bin/env python3
"""Command-line checker: run on demand, no scheduling.

Usage:
    python cli.py auction esalatjonob
    python cli.py auction "https://app.tonkeeper.com/dapp/https%3A%2F%2Ffragment.com%2Fusername%2Fesalatjonob"
    python cli.py tx <transaction_hash>
    python cli.py check <username_or_link_or_hash>
"""

import argparse
import sys

from checker.fragment import FragmentError, fetch_auction
from checker.render import render_auction, render_tx
from checker.ton import TonError, fetch_transaction, looks_like_tx_hash


def cmd_auction(args: argparse.Namespace) -> int:
    try:
        data = fetch_auction(args.query)
    except FragmentError as exc:
        print(f"خطا: {exc}", file=sys.stderr)
        return 1
    print(render_auction(data))
    return 0


def cmd_tx(args: argparse.Namespace) -> int:
    try:
        data = fetch_transaction(args.hash)
    except TonError as exc:
        print(f"خطا: {exc}", file=sys.stderr)
        return 1
    print(render_tx(data))
    return 0


def cmd_check(args: argparse.Namespace) -> int:
    if looks_like_tx_hash(args.query):
        return cmd_tx(argparse.Namespace(hash=args.query))
    return cmd_auction(argparse.Namespace(query=args.query))


def main() -> int:
    parser = argparse.ArgumentParser(description="Fragment auction / TON tx checker")
    sub = parser.add_subparsers(dest="command", required=True)

    p_auction = sub.add_parser("auction", help="زمان باقی‌مانده و وضعیت یک آیتم Fragment را نشان می‌دهد")
    p_auction.add_argument("query", help="یوزرنیم، لینک fragment.com یا لینک والت (مثل Tonkeeper)")
    p_auction.set_defaults(func=cmd_auction)

    p_tx = sub.add_parser("tx", help="وضعیت یک تراکنش TON را با هش آن نشان می‌دهد")
    p_tx.add_argument("hash", help="هش تراکنش (hex یا base64)")
    p_tx.set_defaults(func=cmd_tx)

    p_check = sub.add_parser("check", help="تشخیص خودکار نوع ورودی (هش تراکنش یا آیتم Fragment)")
    p_check.add_argument("query")
    p_check.set_defaults(func=cmd_check)

    args = parser.parse_args()
    return args.func(args)


if __name__ == "__main__":
    raise SystemExit(main())
