<?php

declare(strict_types=1);

/**
 * بررسی سلامت یک دامنه از بیرونِ هاست.
 *
 * این بخش از API سی‌پنل استفاده نمی‌کند؛ خود ربات دامنه را صدا می‌زند — دقیقاً
 * مثل کاری که مرورگر کاربر می‌کند — تا بشود چیزهایی را دید که از داخل هاست
 * معلوم نیست: زنجیره‌ی ریدایرکت، حلقه‌ی ریدایرکت، کد پاسخ، زمان پاسخ، تفاوت
 * www و بدون‌www، رکورد DNS واقعی و گواهی SSLی که واقعاً سرو می‌شود.
 */
final class SiteCheck
{
    private const TIMEOUT = 12;
    private const MAX_HOPS = 6;

    /**
     * @return array{
     *     domain: string,
     *     dns: array{a: array<int, string>, ns: array<int, string>, matches_server: ?bool},
     *     http: array<string, mixed>,
     *     https: array<string, mixed>,
     *     www: array<string, mixed>,
     *     certificate: array<string, mixed>|null,
     *     problems: array<int, string>
     * }
     */
    public static function run(string $domain, ?string $serverIp = null): array
    {
        $domain = self::cleanDomain($domain);

        $dns = self::resolve($domain);
        if ($serverIp !== null && $serverIp !== '' && $dns['a'] !== []) {
            $dns['matches_server'] = in_array($serverIp, $dns['a'], true);
        }

        $http = self::trace("http://{$domain}/");
        $https = self::trace("https://{$domain}/");
        $www = self::trace('https://www.' . $domain . '/');
        $certificate = self::certificate($domain);

        $problems = self::problems($domain, $dns, $http, $https, $www, $certificate);

        return [
            'domain' => $domain,
            'dns' => $dns,
            'http' => $http,
            'https' => $https,
            'www' => $www,
            'certificate' => $certificate,
            'problems' => $problems,
        ];
    }

    public static function cleanDomain(string $domain): string
    {
        $domain = trim(strtolower($domain));
        $domain = (string) preg_replace('#^[a-z]+://#', '', $domain);
        $domain = (string) preg_replace('#[/?].*$#', '', $domain);

        return trim($domain, '. ');
    }

    /**
     * @return array{a: array<int, string>, ns: array<int, string>, matches_server: ?bool}
     */
    private static function resolve(string $domain): array
    {
        $a = [];
        $ns = [];

        foreach (@dns_get_record($domain, DNS_A) ?: [] as $record) {
            if (isset($record['ip'])) {
                $a[] = (string) $record['ip'];
            }
        }
        foreach (@dns_get_record($domain, DNS_NS) ?: [] as $record) {
            if (isset($record['target'])) {
                $ns[] = (string) $record['target'];
            }
        }

        return ['a' => array_values(array_unique($a)), 'ns' => array_values(array_unique($ns)), 'matches_server' => null];
    }

    /**
     * دنبال‌کردن دستی زنجیره‌ی ریدایرکت تا بتوانیم حلقه را تشخیص بدهیم.
     *
     * @return array{ok: bool, status: int, time_ms: int, chain: array<int, string>, final: string, error: string, loop: bool}
     */
    private static function trace(string $url): array
    {
        $chain = [];
        $seen = [];
        $current = $url;
        $status = 0;
        $error = '';
        $loop = false;
        $started = microtime(true);

        for ($hop = 0; $hop < self::MAX_HOPS; $hop++) {
            if (isset($seen[$current])) {
                $loop = true;
                break;
            }
            $seen[$current] = true;

            $ch = curl_init($current);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_NOBODY => true,
                CURLOPT_FOLLOWLOCATION => false,
                CURLOPT_TIMEOUT => self::TIMEOUT,
                CURLOPT_CONNECTTIMEOUT => 8,
                CURLOPT_SSL_VERIFYPEER => false,
                CURLOPT_SSL_VERIFYHOST => 0,
                CURLOPT_USERAGENT => 'cPanelTeamKan/1.0 (+site-check)',
            ]);
            curl_exec($ch);
            $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $location = (string) curl_getinfo($ch, CURLINFO_REDIRECT_URL);
            $curlError = curl_error($ch);
            curl_close($ch);

            if ($curlError !== '' && $status === 0) {
                $error = $curlError;
                break;
            }

            $chain[] = $current . ' → ' . $status;

            if ($status < 300 || $status >= 400 || $location === '') {
                break;
            }
            $current = $location;
        }

        return [
            'ok' => $status >= 200 && $status < 400,
            'status' => $status,
            'time_ms' => (int) round((microtime(true) - $started) * 1000),
            'chain' => $chain,
            'final' => $current,
            'error' => $error,
            'loop' => $loop,
        ];
    }

    /**
     * همان بررسی گواهی، برای وقتی که فقط گواهی لازم است و نه کل بررسی سلامت.
     *
     * @return array<string, mixed>|null
     */
    public static function certificateFor(string $domain): ?array
    {
        return self::certificate(self::cleanDomain($domain));
    }

    /**
     * گواهی‌ای که واقعاً روی پورت ۴۴۳ سرو می‌شود (نه آنچه داخل پنل ثبت است).
     *
     * @return array<string, mixed>|null
     */
    private static function certificate(string $domain): ?array
    {
        $context = stream_context_create(['ssl' => [
            'capture_peer_cert' => true,
            'verify_peer' => false,
            'verify_peer_name' => false,
            'SNI_enabled' => true,
            'peer_name' => $domain,
        ]]);

        $client = @stream_socket_client(
            "ssl://{$domain}:443",
            $errno,
            $errstr,
            self::TIMEOUT,
            STREAM_CLIENT_CONNECT,
            $context,
        );
        if ($client === false) {
            return null;
        }

        $params = stream_context_get_params($client);
        fclose($client);

        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        if ($cert === null) {
            return null;
        }

        $parsed = openssl_x509_parse($cert);
        if (!is_array($parsed)) {
            return null;
        }

        $names = [];
        foreach (explode(',', (string) ($parsed['extensions']['subjectAltName'] ?? '')) as $name) {
            $name = trim(str_replace('DNS:', '', $name));
            if ($name !== '') {
                $names[] = $name;
            }
        }

        return [
            'issuer' => (string) ($parsed['issuer']['O'] ?? ($parsed['issuer']['CN'] ?? '')),
            'subject' => (string) ($parsed['subject']['CN'] ?? ''),
            'valid_from' => isset($parsed['validFrom_time_t']) ? (int) $parsed['validFrom_time_t'] : null,
            'valid_to' => isset($parsed['validTo_time_t']) ? (int) $parsed['validTo_time_t'] : null,
            'names' => $names,
            'covers_domain' => self::covers($domain, $names, (string) ($parsed['subject']['CN'] ?? '')),
        ];
    }

    /**
     * @param array<int, string> $names
     */
    public static function covers(string $domain, array $names, string $commonName): bool
    {
        $candidates = $names;
        if ($commonName !== '') {
            $candidates[] = $commonName;
        }

        foreach ($candidates as $candidate) {
            $candidate = strtolower(trim($candidate));
            if ($candidate === $domain) {
                return true;
            }
            if (str_starts_with($candidate, '*.') && str_ends_with($domain, substr($candidate, 1))) {
                // *.example.com فقط یک سطح را پوشش می‌دهد
                $rest = substr($domain, 0, -strlen(substr($candidate, 1)));

                return $rest !== '' && !str_contains($rest, '.');
            }
        }

        return false;
    }

    /**
     * @param array{a: array<int, string>, ns: array<int, string>, matches_server: ?bool} $dns
     * @param array<string, mixed> $http
     * @param array<string, mixed> $https
     * @param array<string, mixed> $www
     * @param array<string, mixed>|null $certificate
     *
     * @return array<int, string>
     */
    private static function problems(string $domain, array $dns, array $http, array $https, array $www, ?array $certificate): array
    {
        $problems = [];

        if ($dns['a'] === []) {
            $problems[] = 'برای این دامنه رکورد A پیدا نشد — یعنی دامنه هنوز به هیچ سروری اشاره نمی‌کند.';
        } elseif ($dns['matches_server'] === false) {
            $problems[] = 'رکورد A دامنه به آی‌پی این هاست اشاره نمی‌کند (' . implode('، ', $dns['a']) . ').';
        }

        if ($dns['ns'] === []) {
            $problems[] = 'نیم‌سرور (NS) دامنه خوانده نشد؛ ممکن است دامنه منقضی یا تنظیم‌نشده باشد.';
        }

        if ($https['loop'] ?? false) {
            $problems[] = 'حلقه‌ی ریدایرکت روی HTTPS — سایت مدام خودش را به خودش می‌فرستد.';
        }
        if ($http['loop'] ?? false) {
            $problems[] = 'حلقه‌ی ریدایرکت روی HTTP.';
        }

        if (($https['status'] ?? 0) === 0) {
            $problems[] = 'HTTPS جواب نداد' . (($https['error'] ?? '') !== '' ? ' (' . $https['error'] . ')' : '') . '.';
        } elseif (($https['status'] ?? 0) >= 500) {
            $problems[] = 'سایت با خطای ' . $https['status'] . ' برمی‌گردد — مشکل از خود برنامه یا PHP است.';
        } elseif (($https['status'] ?? 0) === 403) {
            $problems[] = 'پاسخ ۴۰۳ — معمولاً یعنی دسترسی فایل‌ها یا index اشتباه است.';
        } elseif (($https['status'] ?? 0) === 404) {
            $problems[] = 'پاسخ ۴۰۴ — فایل‌های سایت در پوشه‌ی درست نیستند.';
        }

        if ($certificate === null) {
            $problems[] = 'گواهی SSL خوانده نشد؛ احتمالاً هنوز صادر نشده.';
        } else {
            if (($certificate['covers_domain'] ?? false) === false) {
                $problems[] = 'گواهی SSL برای این دامنه صادر نشده (روی گواهی: ' . ($certificate['subject'] ?: '—') . ').';
            }
            $validTo = $certificate['valid_to'] ?? null;
            if (is_int($validTo)) {
                $days = (int) floor(($validTo - time()) / 86400);
                if ($days < 0) {
                    $problems[] = 'گواهی SSL منقضی شده است.';
                } elseif ($days <= 14) {
                    $problems[] = "گواهی SSL تا {$days} روز دیگر منقضی می‌شود.";
                }
            }
        }

        if (($www['status'] ?? 0) === 0 && ($https['status'] ?? 0) !== 0) {
            $problems[] = 'نسخه‌ی www دامنه جواب نمی‌دهد (رکورد www یا گواهی‌اش کم است).';
        }

        return $problems;
    }
}
