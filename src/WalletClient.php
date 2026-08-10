<?php

declare(strict_types=1);

class WalletError extends RuntimeException
{
}

final class WalletClient
{
    private const TON_API_BASE = 'https://tonapi.io/v2';
    private const NOBITEX_STATS_URL = 'https://api.nobitex.ir/market/stats?srcCurrency=ton&dstCurrency=rls';
    private const TIMEOUT = 15;
    private const RATE_TIMEOUT = 8;
    private const NANOTON = 1_000_000_000;

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
        $toman = self::fetchTomanRate();

        return [
            'address' => $data['address'] ?? $address,
            'balance_ton' => $balanceTon,
            'toman' => $toman !== null ? $balanceTon * $toman : null,
        ];
    }

    private static function fetchTomanRate(): ?float
    {
        $ch = curl_init(self::NOBITEX_STATS_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::RATE_TIMEOUT,
        ]);
        $body = curl_exec($ch);
        $ok = curl_errno($ch) === 0 && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        curl_close($ch);

        if (!$ok) {
            return null;
        }

        $data = json_decode((string) $body, true);
        $rial = $data['stats']['ton-rls']['latest'] ?? null;

        return $rial !== null ? ((float) $rial) / 10 : null;
    }
}
