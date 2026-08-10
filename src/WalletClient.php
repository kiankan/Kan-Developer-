<?php

declare(strict_types=1);

class WalletError extends RuntimeException
{
}

final class WalletClient
{
    private const TON_API_BASE = 'https://tonapi.io/v2';
    private const WALLEX_MARKETS_URL = 'https://api.wallex.ir/v1/markets';
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/120.0 Safari/537.36';
    private const TIMEOUT = 15;
    private const RATE_TIMEOUT = 8;
    private const NANOTON = 1_000_000_000;

    public static function looksLikeAddress(string $value): bool
    {
        $value = trim($value);

        return (bool) preg_match('/^-?\d+:[0-9a-fA-F]{64}$/', $value)
            || (bool) preg_match('/^[A-Za-z0-9_-]{48}$/', $value);
    }

    public static function fetchBalance(string $address): array
    {
        $address = trim($address);

        $ch = curl_init(self::TON_API_BASE . '/accounts/' . rawurlencode($address));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new WalletError("خطا در اتصال به TonAPI: $error");
        }
        if ($status === 404) {
            throw new WalletError("ولتی با این آدرس پیدا نشد: $address");
        }
        if ($status !== 200) {
            throw new WalletError("TonAPI پاسخ غیرمنتظره داد (HTTP $status)");
        }

        $data = json_decode((string) $body, true);
        if (!isset($data['balance'])) {
            throw new WalletError("آدرس ولت نامعتبر است: $address");
        }

        $balanceTon = ((float) $data['balance']) / self::NANOTON;
        $rates = self::fetchRates();

        return [
            // آدرسی که خود کاربر فرستاده رو برمی‌گردونیم (نه فرمت raw که TonAPI می‌ده)
            // تا با چیزی که پیست کرده یکی باشه.
            'address' => $address,
            'balance_ton' => $balanceTon,
            'toman' => $rates['toman'] !== null ? $balanceTon * $rates['toman'] : null,
            'usd' => $rates['usd'] !== null ? $balanceTon * $rates['usd'] : null,
        ];
    }

    /**
     * @return array{toman: ?float, usd: ?float}
     */
    private static function fetchRates(): array
    {
        $ch = curl_init(self::WALLEX_MARKETS_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => self::RATE_TIMEOUT,
            CURLOPT_HTTPHEADER => [
                'User-Agent: ' . self::USER_AGENT,
                'Accept: application/json',
            ],
        ]);
        $body = curl_exec($ch);
        $ok = curl_errno($ch) === 0 && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        curl_close($ch);

        if (!$ok) {
            return ['toman' => null, 'usd' => null];
        }

        $data = json_decode((string) $body, true);
        $symbols = $data['result']['symbols'] ?? [];

        $toman = isset($symbols['TONTMN']['stats']) ? self::extractPrice($symbols['TONTMN']['stats']) : null;
        $usd = isset($symbols['TONUSDT']['stats']) ? self::extractPrice($symbols['TONUSDT']['stats']) : null;

        return ['toman' => $toman, 'usd' => $usd];
    }

    /**
     * Wallex leaves lastPrice as the literal string "-" when there has been
     * no recent trade; fall back to the bid/ask midpoint in that case.
     */
    private static function extractPrice(array $stats): ?float
    {
        $last = $stats['lastPrice'] ?? null;
        if (is_numeric($last)) {
            return (float) $last;
        }

        $bid = $stats['bidPrice'] ?? null;
        $ask = $stats['askPrice'] ?? null;
        if (is_numeric($bid) && is_numeric($ask)) {
            return ((float) $bid + (float) $ask) / 2;
        }

        return null;
    }
}
