<?php

declare(strict_types=1);

/**
 * چکر آزاد قیمت ارز: «۱ تون»، «10 btc»، «5 دلار به تومان» و مشابه.
 * فقط اعداد (لاتین یا فارسی، اعشاری) رو می‌شناسه، نه اعداد نوشتاری مثل «پنج».
 */
final class CurrencyClient
{
    private const COIN_ALIASES = [
        'ton' => 'TON', 'toncoin' => 'TON', 'تون' => 'TON', 'تونکوین' => 'TON',
        'btc' => 'BTC', 'bitcoin' => 'BTC', 'بیتکوین' => 'BTC',
        'eth' => 'ETH', 'ethereum' => 'ETH', 'اتریوم' => 'ETH', 'اتر' => 'ETH',
        'trx' => 'TRX', 'tron' => 'TRX', 'ترون' => 'TRX', 'ترکس' => 'TRX',
        'usdt' => 'USDT', 'tether' => 'USDT', 'تتر' => 'USDT',
    ];

    public static function parseQuery(string $text): ?array
    {
        $text = trim(self::toLatinDigits($text));
        if (!preg_match('/^([0-9]+(?:\.[0-9]+)?)\s*([\p{L}\x{200c}]+)$/u', $text, $m)) {
            return null;
        }

        $amount = (float) $m[1];
        $word = mb_strtolower(str_replace("\u{200c}", '', trim($m[2])));
        $coin = self::COIN_ALIASES[$word] ?? null;
        if ($coin === null) {
            return null;
        }

        return ['amount' => $amount, 'coin' => $coin];
    }

    /**
     * @return array{coin: string, amount: float, toman: ?float, usd: ?float}
     */
    public static function fetchValue(float $amount, string $coin): array
    {
        if ($coin === 'USDT') {
            $tomanRate = WalletClient::fetchRates('USDT')['toman'];

            return [
                'coin' => $coin,
                'amount' => $amount,
                'toman' => $tomanRate !== null ? $amount * $tomanRate : null,
                'usd' => $amount,
            ];
        }

        $rates = WalletClient::fetchRates($coin);

        return [
            'coin' => $coin,
            'amount' => $amount,
            'toman' => $rates['toman'] !== null ? $amount * $rates['toman'] : null,
            'usd' => $rates['usd'] !== null ? $amount * $rates['usd'] : null,
        ];
    }

    private static function toLatinDigits(string $text): string
    {
        $persian = ['۰', '۱', '۲', '۳', '۴', '۵', '۶', '۷', '۸', '۹'];
        $arabic = ['٠', '١', '٢', '٣', '٤', '٥', '٦', '٧', '٨', '٩'];
        $latin = ['0', '1', '2', '3', '4', '5', '6', '7', '8', '9'];

        return str_replace($arabic, $latin, str_replace($persian, $latin, $text));
    }
}
