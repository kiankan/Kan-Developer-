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
    preg_match('#^(?:POST|GET) ([^ ]+)#', $head, $pathMatch);
    $path = $pathMatch[1] ?? '';

    if ($logPath !== '') {
        file_put_contents($logPath, json_encode(['path' => $path, 'body' => $body]) . "\n", FILE_APPEND);
    }

    // مسیر قدیمی خروجی دیتابیس، خروجی خام gzip می‌دهد نه JSON.
    if (str_contains($path, '/getsqlbackup/')) {
        if (str_contains($path, 'demo_shop')) {
            $dump = (string) gzencode("-- teamkan test dump\nCREATE TABLE demo (id int);\n");
            fwrite($connection, "HTTP/1.1 200 OK\r\nContent-Type: application/x-gzip\r\nContent-Length: "
                . strlen($dump) . "\r\nConnection: close\r\n\r\n" . $dump);
        } else {
            fwrite($connection, "HTTP/1.1 404 Not Found\r\nContent-Length: 0\r\nConnection: close\r\n\r\n");
        }
        fclose($connection);
        continue;
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
        str_contains($path, 'Variables/get_user_information') && false => [],
        str_contains($path, 'Fileman/list_files') => ['status' => 1, 'data' => [
            ['file' => 'public_html', 'type' => 'dir', 'size' => 4096],
            ['file' => 'logs', 'type' => 'dir', 'size' => 4096],
            ['file' => 'site.zip', 'type' => 'file', 'size' => 2048],
            ['file' => 'zz-notes.txt', 'type' => 'file', 'size' => 120, 'permissions' => '0644'],
        ]],
        // فقط برای سناریوی انتقال فایل؛ بقیه‌ی فایل‌ها خالی برمی‌گردند تا مسیر
        // دانلود خام هم مثل واقعیت تست شود.
        str_contains($path, 'Fileman/get_file_content') && str_contains($body, 'notes.txt')
            => ['status' => 1, 'data' => ['content' => 'salam-teamkan']],
        str_contains($path, 'Fileman/upload_files') => ['status' => 1, 'data' => ['uploads' => [['status' => 1, 'file' => 'ok']]]],
        str_contains($path, 'ResourceUsage/get_usages') => ['status' => 1, 'data' => [
            ['id' => 'disk_usage', 'description' => 'Disk Usage', 'usage' => 5368709120, 'maximum' => 10737418240, 'percent_used' => 50, 'formatter' => 'format_bytes'],
            ['id' => 'inodes', 'description' => 'File Usage', 'usage' => 42000, 'maximum' => 200000, 'percent_used' => 21],
        ]],
        str_contains($path, 'StatsBar/get_stats') => ['status' => 1, 'data' => [
            ['name' => 'hostname', 'value' => 'srv01.example.com'],
            ['name' => 'plan', 'value' => 'Business Pro'],
            ['name' => 'lastlogin', 'value' => '2026-09-03 21:10'],
            ['name' => 'lastlogin_ip', 'value' => '203.0.113.55'],
            ['name' => 'cpanelversion', 'value' => '118.0.12'],
        ]],
        str_contains($path, 'Features/list_features') => ['status' => 1, 'data' => [
            ['id' => 'cron', 'enabled' => 1],
            ['id' => 'ssl', 'enabled' => 1],
            ['id' => 'ssh', 'enabled' => 0],
        ]],
        str_contains($path, 'DomainInfo/domains_data') => ['status' => 1, 'data' => [
            'main_domain' => ['domain' => 'example.com', 'documentroot' => '/home/demo/public_html', 'ip' => '203.0.113.7'],
            'addon_domains' => [['domain' => 'second.com', 'documentroot' => '/home/demo/second.com', 'ip' => '203.0.113.7']],
        ]],
        str_contains($path, 'LangPHP/php_get_vhost_versions') => ['status' => 1, 'data' => [
            ['vhost' => 'example.com', 'version' => 'ea-php82'],
        ]],
        str_contains($path, 'LangPHP/php_ini_get_user_basic_directives') => ['status' => 1, 'data' => [
            ['key' => 'memory_limit', 'value' => '256M'],
            ['key' => 'upload_max_filesize', 'value' => '64M'],
            ['key' => 'max_execution_time', 'value' => '120'],
        ]],
        str_contains($path, 'Email/get_client_settings') => ['status' => 1, 'data' => [
            'imap' => ['server' => 'mail.example.com', 'port' => 993, 'ssl' => 1],
            'smtp' => ['server' => 'mail.example.com', 'port' => 465, 'ssl' => 1],
        ]],
        str_contains($path, 'Mysql/list_databases') => ['status' => 1, 'data' => [
            ['database' => 'demo_shop', 'disk_usage' => 52428800, 'users' => [['user' => 'demo_admin']]],
            ['database' => 'demo_old', 'disk_usage' => 1048576, 'users' => []],
        ]],
        str_contains($path, 'Mysql/list_users') => ['status' => 1, 'data' => [['user' => 'demo_admin']]],
        str_contains($path, 'Mysql/get_server_information') => ['status' => 1, 'data' => ['host' => 'localhost', 'port' => 3306, 'version' => '8.0.36']],
        str_contains($path, 'Mysql/get_restrictions') => ['status' => 1, 'data' => ['prefix' => 'demo_', 'prefixing_enabled' => 1, 'max_database_name_length' => 64]],
        str_contains($path, 'Mime/list_redirects') => ['status' => 1, 'data' => [
            ['sourceurl' => 'old.example.com', 'destination' => 'https://example.com', 'type' => 'permanent'],
        ]],
        str_contains($path, 'Mysql/list_hosts') => ['status' => 1, 'data' => [['host' => '203.0.113.10']]],
        str_contains($path, 'Email/list_auto_responders') => ['status' => 1, 'data' => [
            ['email' => 'info@example.com', 'subject' => 'در مرخصی هستم'],
        ]],
        str_contains($path, 'Ftp/list_sessions') => ['status' => 1, 'data' => [
            ['user' => 'deploy@example.com', 'host' => '203.0.113.9', 'pid' => '2471', 'file' => '/public_html/app.zip'],
        ]],
        str_contains($path, 'Email/list_filters') => ['status' => 1, 'data' => [
            ['filtername' => 'حذف تبلیغات', 'enabled' => 1, 'filter' => 'if $header_subject: contains "viagra"'],
            ['filtername' => 'بایگانی فاکتورها', 'enabled' => 1, 'filter' => 'if $header_from: contains "billing"'],
        ]],
        str_contains($path, 'EmailTrack/search') => ['status' => 1, 'data' => [
            ['sendunixtime' => 1756900000, 'sender' => 'info@example.com', 'recipient' => 'kian@gmail.com', 'type' => 'success', 'message' => ''],
            ['sendunixtime' => 1756900500, 'sender' => 'info@example.com', 'recipient' => 'bad@nowhere.tld', 'type' => 'failure', 'message' => 'host not found'],
        ]],
        str_contains($path, 'SSH/list_keys') => ['status' => 1, 'data' => [
            ['name' => 'laptop', 'key_type' => 'ssh-ed25519', 'authorized' => 1, 'fingerprint' => 'SHA256:abcdef'],
        ]],
        str_contains($path, 'Email/list_forwarders') => ['status' => 1, 'data' => [
            ['dest' => 'info@example.com', 'forward' => 'kian@gmail.com'],
        ]],
        str_contains($path, 'Backup/list_backups') => ['status' => 1, 'data' => [
            ['file' => 'backup-9.3.2026_00-00-00_demo.tar.gz', 'size' => 10485760, 'time' => 1756900000],
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
