<?php

declare(strict_types=1);

class TonError extends RuntimeException
{
}

class TonTxNotFoundError extends TonError
{
}

final class TonClient
{
    private const API_BASE = 'https://tonapi.io/v2';
    private const TIMEOUT = 15;
    private const NANOTON = 1_000_000_000;

    public static function looksLikeTxHash(string $value): bool
    {
        $value = trim($value);

        return (bool) preg_match('/^[0-9a-fA-F]{64}$/', $value)
            || (bool) preg_match('/^[A-Za-z0-9+\/_-]{43,44}={0,2}$/', $value);
    }

    public static function fetchTransaction(string $txHash): array
    {
        $txHash = trim($txHash);

        $ch = curl_init(self::API_BASE . '/blockchain/transactions/' . rawurlencode($txHash));
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
            throw new TonError("خطا در اتصال به TonAPI: $error");
        }
        if ($status === 404) {
            throw new TonTxNotFoundError("تراکنشی با این هش پیدا نشد: $txHash");
        }
        if ($status !== 200) {
            throw new TonError("TonAPI پاسخ غیرمنتظره داد (HTTP $status): " . substr((string) $body, 0, 200));
        }

        $data = json_decode((string) $body, true);
        $computePhase = $data['compute_phase'] ?? [];
        $account = $data['account'] ?? [];

        return [
            'hash' => $data['hash'] ?? $txHash,
            'success' => (bool) ($data['success'] ?? false),
            'aborted' => (bool) ($data['aborted'] ?? false),
            'utime' => isset($data['utime'])
                ? (new DateTimeImmutable('@' . $data['utime']))->setTimezone(new DateTimeZone('UTC'))
                : null,
            'account_address' => $account['address'] ?? null,
            'account_name' => $account['name'] ?? null,
            'total_fees_ton' => ($data['total_fees'] ?? 0) / self::NANOTON,
            'exit_code_description' => $computePhase['exit_code_description'] ?? null,
            'transaction_type' => $data['transaction_type'] ?? null,
        ];
    }
}
