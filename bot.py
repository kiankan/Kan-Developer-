#!/usr/bin/env python3
"""Telegram bot: replies on demand (no fixed schedule) with Fragment auction
countdown / status or TON transaction status."""

import asyncio
import logging
import os

from aiogram import Bot, Dispatcher, F
from aiogram.filters import Command, CommandStart
from aiogram.types import Message
from dotenv import load_dotenv

from checker.fragment import FragmentError, fetch_auction
from checker.render import render_auction, render_tx
from checker.ton import TonError, fetch_transaction, looks_like_tx_hash

load_dotenv()

logging.basicConfig(level=logging.INFO)
logger = logging.getLogger("kan_checker")

HELP_TEXT = (
    "سلام! هر وقت خواستید یکی از این دستورها رو بفرستید تا همون لحظه جواب بگیرید:\n\n"
    "/auction <یوزرنیم یا لینک> — زمان باقی‌مانده و وضعیت مزایده Fragment\n"
    "/tx <هش تراکنش> — وضعیت یک تراکنش TON\n"
    "/check <یوزرنیم یا لینک یا هش> — تشخیص خودکار نوع ورودی\n\n"
    "می‌تونید لینک fragment.com، لینک والت (مثل Tonkeeper) یا فقط یوزرنیم رو بفرستید."
)


async def handle_start(message: Message) -> None:
    await message.answer(HELP_TEXT)


async def handle_auction(message: Message) -> None:
    query = message.text.split(maxsplit=1)
    if len(query) < 2:
        await message.answer("لطفاً یوزرنیم یا لینک رو هم بفرستید. مثال:\n/auction esalatjonob")
        return

    await reply_auction(message, query[1])


async def handle_tx(message: Message) -> None:
    parts = message.text.split(maxsplit=1)
    if len(parts) < 2:
        await message.answer("لطفاً هش تراکنش رو هم بفرستید. مثال:\n/tx <hash>")
        return

    await reply_tx(message, parts[1])


async def handle_check(message: Message) -> None:
    parts = message.text.split(maxsplit=1)
    if len(parts) < 2:
        await message.answer("لطفاً یوزرنیم، لینک یا هش تراکنش رو بفرستید. مثال:\n/check esalatjonob")
        return

    query = parts[1].strip()
    if looks_like_tx_hash(query):
        await reply_tx(message, query)
    else:
        await reply_auction(message, query)


async def handle_plain_text(message: Message) -> None:
    text = (message.text or "").strip()
    if not text:
        return
    if looks_like_tx_hash(text):
        await reply_tx(message, text)
    else:
        await reply_auction(message, text)


async def reply_auction(message: Message, query: str) -> None:
    await message.chat.do("typing")
    try:
        data = fetch_auction(query)
    except FragmentError as exc:
        await message.answer(f"⚠️ {exc}")
        return
    await message.answer(render_auction(data))


async def reply_tx(message: Message, tx_hash: str) -> None:
    await message.chat.do("typing")
    try:
        data = fetch_transaction(tx_hash)
    except TonError as exc:
        await message.answer(f"⚠️ {exc}")
        return
    await message.answer(render_tx(data))


async def main() -> None:
    token = os.environ.get("TELEGRAM_BOT_TOKEN")
    if not token:
        raise SystemExit("TELEGRAM_BOT_TOKEN تنظیم نشده است (در .env یا متغیر محیطی قرار دهید).")

    admin_id_raw = os.environ.get("ADMIN_ID")
    if admin_id_raw:
        try:
            admin_id = int(admin_id_raw)
        except ValueError as exc:
            raise SystemExit(f"ADMIN_ID باید یک عدد باشد، مقدار فعلی نامعتبر است: {admin_id_raw!r}") from exc
        logger.info("دسترسی محدود به ADMIN_ID=%s شد.", admin_id)
    else:
        admin_id = None
        logger.warning("ADMIN_ID تنظیم نشده — ربات به پیام هر کاربری در تلگرام پاسخ می‌دهد.")

    bot = Bot(token=token)
    dp = Dispatcher()

    if admin_id is not None:
        dp.message.filter(F.from_user.id == admin_id)

    dp.message.register(handle_start, CommandStart())
    dp.message.register(handle_start, Command("help"))
    dp.message.register(handle_auction, Command("auction"))
    dp.message.register(handle_tx, Command("tx"))
    dp.message.register(handle_check, Command("check"))
    dp.message.register(handle_plain_text)

    logger.info("Bot starting (long polling)...")
    await bot.delete_webhook(drop_pending_updates=True)
    await dp.start_polling(bot)


if __name__ == "__main__":
    asyncio.run(main())
