#!/usr/bin/env php
<?php

declare(strict_types=1);

/**
 * سرور ساختگی cPanel روی TLS برای تست‌های یکپارچه.
 *
 *   php tests/fake_cpanel.php <port> <cert.pem> <log-file>
 *
 * پاسخ‌های ثابتِ چند تابع UAPI را می‌دهد و هر درخواست را لاگ می‌کند، تا بشود
 * بررسی کرد ربات دقیقاً چه چیزی به cPanel فرستاده است.
 */

$port = (int) ($argv[1] ?? 12085);
$certPath = (string) ($argv[2] ?? '');
$logPath = (string) ($argv[3] ?? '');

$context = stream_context_create(['ssl' => [
    'local_cert' => $certPath,
    'allow_self_signed' => true,
    'verify_peer' => false,
]]);

$server = @stream_socket_server(
    "ssl://127.0.0.1:$port",
    $errno,
    $errstr,
    STREAM_SERVER_BIND | STREAM_SERVER_LISTEN,
    $context,
);
if ($server === false) {
    fwrite(STDERR, "fake cpanel failed: $errstr\n");
    exit(1);
}

$b64 = static fn (array $values): array => array_map('base64_encode', $values);
$deadline = time() + 120;

while (time() < $deadline) {
    $connection = @stream_socket_accept($server, 5);
    if ($connection === false) {
        continue;
    }
    stream_set_timeout($connection, 5);

    $head = '';
    while (!str_contains($head, "\r\n\r\n")) {
        $chunk = fread($connection, 1);
        if ($chunk === '' || $chunk === false) {
            break;
        }
        $head .= $chunk;
    }
    preg_match('/Content-Length: (\d+)/i', $head, $lengthMatch);
    $body = isset($lengthMatch[1]) && (int) $lengthMatch[1] > 0 ? (string) fread($connection, (int) $lengthMatch[1]) : '';
    preg_match('#^POST ([^ ]+)#', $head, $pathMatch);
    $path = $pathMatch[1] ?? '';

    if ($logPath !== '') {
        file_put_contents($logPath, json_encode(['path' => $path, 'body' => $body]) . "\n", FILE_APPEND);
    }

    $payload = match (true) {
        str_contains($path, 'Variables/get_user_information') => ['status' => 1, 'data' => ['user' => 'demo', 'home' => '/home/demo']],
        str_contains($path, 'Email/list_pops_with_disk') => ['status' => 1, 'data' => [
            ['email' => 'info@example.com', 'domain' => 'example.com', '_diskused' => 0, '_diskquota' => 0],
            ['email' => 'sales@example.com', 'domain' => 'example.com', '_diskused' => 1048576, '_diskquota' => 52428800],
        ]],
        str_contains($path, 'DomainInfo/list_domains') => ['status' => 1, 'data' => [
            'main_domain' => 'example.com',
            'addon_domains' => ['second.com'],
            'sub_domains' => ['shop.example.com'],
            'parked_domains' => [],
        ]],
        str_contains($path, 'DNS/parse_zone') => ['status' => 1, 'data' => [
            ['type' => 'record', 'line_index' => 8, 'record_type' => 'SOA', 'dname_b64' => base64_encode('example.com.'),
                'data_b64' => $b64(['ns1.example.com.', 'root.example.com.', '2024010101'])],
            ['type' => 'record', 'line_index' => 14, 'record_type' => 'A', 'ttl' => 14400,
                'dname_b64' => base64_encode('www.example.com.'), 'data_b64' => $b64(['203.0.113.10'])],
        ]],
        default => ['status' => 1, 'data' => []],
    };

    $json = (string) json_encode($payload);
    fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/json\r\nContent-Length: " . strlen($json) . "\r\nConnection: close\r\n\r\n$json");
    fclose($connection);
}
