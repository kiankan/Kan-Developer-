#!/usr/bin/env php
<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

function usage(): void
{
    fwrite(STDERR, <<<TXT
        استفاده:
          php cli.php auction <username | link>
          php cli.php gift <t.me/nft link | fragment.com/gift link | slug>
          php cli.php tx <hash>
          php cli.php wallet <address>
          php cli.php price "<amount> <coin>"   (e.g. "1 ton", "5 btc")
          php cli.php check <username | link | hash | address | gift>
          php cli.php webhook:set <url>
          php cli.php webhook:delete

        TXT);
}

$command = $argv[1] ?? null;
$arg = $argv[2] ?? null;

switch ($command) {
    case 'auction':
        if ($arg === null) {
            usage();
            exit(1);
        }
        try {
            echo Render::auction(FragmentClient::fetchAuction($arg)), "\n";
        } catch (FragmentError $e) {
            fwrite(STDERR, 'خطا: ' . $e->getMessage() . "\n");
            exit(1);
        }
        break;

    case 'tx':
        if ($arg === null) {
            usage();
            exit(1);
        }
        try {
            echo Render::tx(TonClient::fetchTransaction($arg)), "\n";
        } catch (TonError $e) {
            fwrite(STDERR, 'خطا: ' . $e->getMessage() . "\n");
            exit(1);
        }
        break;

    case 'wallet':
        if ($arg === null) {
            usage();
            exit(1);
        }
        try {
            echo Render::wallet(WalletClient::fetchBalance($arg)), "\n";
        } catch (WalletError $e) {
            fwrite(STDERR, 'خطا: ' . $e->getMessage() . "\n");
            exit(1);
        }
        break;

    case 'gift':
        if ($arg === null) {
            usage();
            exit(1);
        }
        try {
            echo Render::gift(GiftClient::fetchGift($arg)), "\n";
        } catch (GiftError $e) {
            fwrite(STDERR, 'خطا: ' . $e->getMessage() . "\n");
            exit(1);
        }
        break;

    case 'price':
        if ($arg === null) {
            usage();
            exit(1);
        }
        $parsed = CurrencyClient::parseQuery($arg);
        if ($parsed === null) {
            fwrite(STDERR, "خطا: ورودی رو نفهمیدم. مثال: \"1 ton\"\n");
            exit(1);
        }
        echo Render::price(CurrencyClient::fetchValue($parsed['amount'], $parsed['coin'])), "\n";
        break;

    case 'check':
        if ($arg === null) {
            usage();
            exit(1);
        }
        try {
            $parsed = CurrencyClient::parseQuery($arg);
            if ($parsed !== null) {
                echo Render::price(CurrencyClient::fetchValue($parsed['amount'], $parsed['coin'])), "\n";
            } elseif (GiftClient::extractSlug($arg) !== null) {
                echo Render::gift(GiftClient::fetchGift($arg)), "\n";
            } elseif (TonClient::looksLikeTxHash($arg)) {
                echo Render::tx(TonClient::fetchTransaction($arg)), "\n";
            } elseif (WalletClient::looksLikeAddress($arg)) {
                echo Render::wallet(WalletClient::fetchBalance($arg)), "\n";
            } else {
                echo Render::auction(FragmentClient::fetchAuction($arg)), "\n";
            }
        } catch (TonError|FragmentError|WalletError|GiftError $e) {
            fwrite(STDERR, 'خطا: ' . $e->getMessage() . "\n");
            exit(1);
        }
        break;

    case 'webhook:set':
        if ($arg === null) {
            usage();
            exit(1);
        }
        (new TelegramApi(kanchecker_bot_token()))->setWebhook($arg);
        echo "وبهوک تنظیم شد: $arg\n";
        break;

    case 'webhook:delete':
        (new TelegramApi(kanchecker_bot_token()))->deleteWebhook();
        echo "وبهوک حذف شد.\n";
        break;

    default:
        usage();
        exit(1);
}
