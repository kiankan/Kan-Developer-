<?php

declare(strict_types=1);

class WalletError extends RuntimeException
{
}

final class WalletClient
{
    private const TON_API_BASE = 'https://tonapi.io/v2';
    private const BLOCKSTREAM_API = 'https://blockstream.info/api';
    private const ETH_RPC_URL = 'https://ethereum-rpc.publicnode.com';
    private const TRONGRID_API = 'https://api.trongrid.io';
    private const WALLEX_MARKETS_URL = 'https://api.wallex.ir/v1/markets';
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/120.0 Safari/537.36';
    private const TIMEOUT = 15;
    private const RATE_TIMEOUT = 8;
    private const NANOTON = 1_000_000_000;
    private const SATOSHI = 1e8;
    private const WEI = 1e18;
    private const SUN = 1_000_000;

    public static function looksLikeAddress(string $value): bool
    {
        return self::detectChain(trim($value)) !== null;
    }

    public static function detectChain(string $value): ?string
    {
        $value = trim($value);

        if (preg_match('/^-?\d+:[0-9a-fA-F]{64}$/', $value) || preg_match('/^[A-Za-z0-9_-]{48}$/', $value)) {
            return 'TON';
        }
        if (preg_match('/^0x[0-9a-fA-F]{40}$/', $value)) {
            return 'ETH';
        }
        if (preg_match('/^T[1-9A-HJ-NP-Za-km-z]{33}$/', $value)) {
            return 'TRX';
        }
        if (preg_match('/^(bc1[a-z0-9]{25,89}|[13][1-9A-HJ-NP-Za-km-z]{25,34})$/', $value)) {
            return 'BTC';
        }

        return null;
    }

    public static function fetchBalance(string $address): array
    {
        $address = trim($address);
        $chain = self::detectChain($address);
        if ($chain === null) {
            throw new WalletError("آدرس ولت نامعتبر یا پشتیبانی‌نشده است: $address");
        }

        $balance = match ($chain) {
            'TON' => self::fetchTonBalance($address),
            'BTC' => self::fetchBtcBalance($address),
            'ETH' => self::fetchEthBalance($address),
            'TRX' => self::fetchTrxBalance($address),
        };

        $rates = self::fetchRates($chain);

        return [
            'address' => $address,
            'chain' => $chain,
            'balance' => $balance,
            'toman' => $rates['toman'] !== null ? $balance * $rates['toman'] : null,
            'usd' => $rates['usd'] !== null ? $balance * $rates['usd'] : null,
        ];
    }

    private static function fetchTonBalance(string $address): float
    {
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
            throw new WalletError("آدرس ولت TON نامعتبر است: $address");
        }

        return ((float) $data['balance']) / self::NANOTON;
    }

    private static function fetchBtcBalance(string $address): float
    {
        $ch = curl_init(self::BLOCKSTREAM_API . '/address/' . rawurlencode($address));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_HTTPHEADER => ['User-Agent: ' . self::USER_AGENT],
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new WalletError("خطا در اتصال به Blockstream: $error");
        }
        if ($status === 400 || $status === 404) {
            throw new WalletError("آدرس بیت‌کوین نامعتبر است: $address");
        }
        if ($status !== 200) {
            throw new WalletError("Blockstream پاسخ غیرمنتظره داد (HTTP $status)");
        }

        $data = json_decode((string) $body, true);
        $funded = $data['chain_stats']['funded_txo_sum'] ?? null;
        $spent = $data['chain_stats']['spent_txo_sum'] ?? null;
        if ($funded === null || $spent === null) {
            throw new WalletError("آدرس بیت‌کوین نامعتبر است: $address");
        }

        return ((float) $funded - (float) $spent) / self::SATOSHI;
    }

    private static function fetchEthBalance(string $address): float
    {
        $payload = json_encode([
            'jsonrpc' => '2.0',
            'method' => 'eth_getBalance',
            'params' => [$address, 'latest'],
            'id' => 1,
        ]);

        $ch = curl_init(self::ETH_RPC_URL);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => ['Content-Type: application/json'],
            CURLOPT_TIMEOUT => self::TIMEOUT,
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new WalletError("خطا در اتصال به شبکه اتریوم: $error");
        }
        if ($status !== 200) {
            throw new WalletError("RPC اتریوم پاسخ غیرمنتظره داد (HTTP $status)");
        }

        $data = json_decode((string) $body, true);
        if (!isset($data['result'])) {
            throw new WalletError("آدرس اتریوم نامعتبر است: $address");
        }

        $hex = $data['result'];
        $hex = str_starts_with($hex, '0x') ? substr($hex, 2) : $hex;

        return ((float) hexdec($hex)) / self::WEI;
    }

    private static function fetchTrxBalance(string $address): float
    {
        $ch = curl_init(self::TRONGRID_API . '/v1/accounts/' . rawurlencode($address));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_HTTPHEADER => ['User-Agent: ' . self::USER_AGENT, 'Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new WalletError("خطا در اتصال به TronGrid: $error");
        }
        if ($status !== 200) {
            throw new WalletError("TronGrid پاسخ غیرمنتظره داد (HTTP $status)");
        }

        $data = json_decode((string) $body, true);
        if (!($data['success'] ?? false)) {
            throw new WalletError("آدرس ترون نامعتبر است: $address");
        }

        // اگر ولت هیچ تراکنشی نداشته باشه، TronGrid آرایه‌ی data خالی برمی‌گردونه —
        // یعنی موجودی صفره، نه اینکه آدرس نامعتبره.
        $balanceSun = $data['data'][0]['balance'] ?? 0;

        return ((float) $balanceSun) / self::SUN;
    }

    /**
     * @return array{toman: ?float, usd: ?float}
     */
    private static function fetchRates(string $chain): array
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

        $toman = isset($symbols["{$chain}TMN"]['stats']) ? self::extractPrice($symbols["{$chain}TMN"]['stats']) : null;
        $usd = isset($symbols["{$chain}USDT"]['stats']) ? self::extractPrice($symbols["{$chain}USDT"]['stats']) : null;

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
