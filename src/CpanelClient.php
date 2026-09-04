<?php

declare(strict_types=1);

/**
 * کلاینت API سی‌پنل/WHM.
 *
 * سه گویش پشتیبانی می‌شه:
 *   - UAPI   (cPanel 2083): /execute/<Module>/<function>
 *   - WHM 1  (WHM 2087):    /json-api/<function>?api.version=1
 *   - API2   (cPanel 2083): /json-api/cpanel?cpanel_jsonapi_apiversion=2&...
 *
 * احراز هویت فقط با API Token انجام می‌شه (هدر Authorization)، نه پسورد و نه
 * سشن — یعنی رمز حساب هیچ‌وقت جایی ذخیره نمی‌شه.
 *   cPanel:  Authorization: cpanel <user>:<token>
 *   WHM:     Authorization: whm <user>:<token>
 *
 * اگه سرور از نوع WHM باشه، فراخوانی‌های UAPI/API2 از طریق پراکسی
 * /json-api/cpanel روی خود WHM و به‌نام کاربر انتخاب‌شده انجام می‌شن؛ یعنی با
 * یک توکن root می‌شه حساب هر کاربری رو مدیریت کرد.
 */
final class CpanelClient
{
    public const TYPE_CPANEL = 'cpanel';
    public const TYPE_WHM = 'whm';

    private const TIMEOUT = 45;
    private const CONNECT_TIMEOUT = 12;

    /** @var array<string, mixed> */
    private array $server;

    /** @var null|callable(string, string, array<int, string>, bool): array{status: int, body: string, error: string} */
    private $transport;

    private ?string $asUser = null;

    /**
     * @param array<string, mixed> $server
     * @param null|callable(string, string, array<int, string>, bool): array{status: int, body: string, error: string} $transport
     */
    public function __construct(array $server, ?callable $transport = null)
    {
        foreach (['host', 'user', 'token'] as $required) {
            if (trim((string) ($server[$required] ?? '')) === '') {
                throw CpanelError::config("پیکربندی سرور ناقص است: مقدار «{$required}» خالی است.");
            }
        }
        $this->server = $server;
        $this->transport = $transport;
        $this->asUser = self::str($server['as_user'] ?? '') ?: null;
    }

    public function type(): string
    {
        return ($this->server['type'] ?? self::TYPE_CPANEL) === self::TYPE_WHM ? self::TYPE_WHM : self::TYPE_CPANEL;
    }

    public function isWhm(): bool
    {
        return $this->type() === self::TYPE_WHM;
    }

    public function label(): string
    {
        return self::str($this->server['label'] ?? '') ?: self::str($this->server['host'] ?? '');
    }

    public function host(): string
    {
        return self::str($this->server['host'] ?? '');
    }

    public function user(): string
    {
        return self::str($this->server['user'] ?? '');
    }

    /**
     * کاربری که فراخوانی‌های UAPI به‌نام او روی WHM اجرا می‌شن.
     */
    public function targetUser(): string
    {
        return $this->isWhm() ? (string) $this->asUser : $this->user();
    }

    public function withUser(?string $user): self
    {
        $clone = clone $this;
        $clone->asUser = ($user !== null && trim($user) !== '') ? trim($user) : null;

        return $clone;
    }

    /**
     * UAPI — رابط اصلی و مدرن سی‌پنل.
     *
     * @param array<string, mixed> $params
     *
     * @return mixed داده‌ی خام فیلد data
     */
    public function uapi(string $module, string $function, array $params = []): mixed
    {
        if ($this->isWhm()) {
            if ($this->asUser === null) {
                throw CpanelError::config(
                    'برای اجرای دستورهای سطح حساب روی یک سرور WHM، اول باید کاربر مقصد را انتخاب کنید.'
                );
            }

            return $this->jsonApiCpanel(3, $module, $function, $params);
        }

        $url = $this->baseUrl() . '/execute/' . rawurlencode($module) . '/' . rawurlencode($function);

        return $this->unwrapUapi($this->send($url, $params), "$module::$function");
    }

    /**
     * API2 — برای معدود قابلیت‌هایی که هنوز معادل UAPI ندارن.
     *
     * @param array<string, mixed> $params
     */
    public function api2(string $module, string $function, array $params = []): mixed
    {
        return $this->jsonApiCpanel(2, $module, $function, $params);
    }

    /**
     * آپلود فایل با UAPI (multipart/form-data).
     *
     * @param array<string, mixed> $params
     */
    public function uploadFile(string $module, string $function, array $params, string $fieldName, string $fileName, string $contents): mixed
    {
        if ($this->isWhm()) {
            throw CpanelError::config(
                'آپلود فایل فقط با توکن خود حساب cPanel کار می‌کند؛ برای این هاست یک سرور از نوع cpanel اضافه کنید.'
            );
        }

        $boundary = '----teamkan' . bin2hex(random_bytes(12));
        $body = '';
        foreach ($params as $key => $value) {
            $body .= "--{$boundary}\r\n"
                . 'Content-Disposition: form-data; name="' . $key . "\"\r\n\r\n"
                . self::scalar($value) . "\r\n";
        }
        $body .= "--{$boundary}\r\n"
            . 'Content-Disposition: form-data; name="' . $fieldName . '"; filename="' . str_replace('"', '', $fileName) . "\"\r\n"
            . "Content-Type: application/octet-stream\r\n\r\n"
            . $contents . "\r\n"
            . "--{$boundary}--\r\n";

        $url = $this->baseUrl() . '/execute/' . rawurlencode($module) . '/' . rawurlencode($function);
        $decoded = $this->sendRaw($url, $body, 'multipart/form-data; boundary=' . $boundary);

        return $this->unwrapUapi($decoded, "{$module}::{$function}");
    }

    /**
     * WHM API نسخه‌ی ۱ (فقط روی سرورهای از نوع WHM).
     *
     * @param array<string, mixed> $params
     */
    public function whm(string $function, array $params = []): mixed
    {
        if (!$this->isWhm()) {
            throw CpanelError::config(
                'این سرور با توکن cPanel تعریف شده و به WHM دسترسی ندارد؛ برای دستورهای WHM یک سرور از نوع whm اضافه کنید.'
            );
        }

        $url = $this->baseUrl() . '/json-api/' . rawurlencode($function);
        $decoded = $this->send($url, ['api.version' => 1] + $params);

        $metadata = is_array($decoded['metadata'] ?? null) ? $decoded['metadata'] : [];
        $result = $metadata['result'] ?? null;
        if ($result !== null && (int) $result !== 1) {
            $reason = self::str($metadata['reason'] ?? '') ?: 'دلیلی برنگشت';
            throw CpanelError::api("WHM دستور «{$function}» را رد کرد: $reason");
        }
        if (isset($decoded['error'])) {
            throw CpanelError::api("WHM دستور «{$function}» را رد کرد: " . self::str($decoded['error']));
        }

        if (array_key_exists('data', $decoded)) {
            return $decoded['data'];
        }
        unset($decoded['metadata']);

        return $decoded;
    }

    /**
     * تست سریع اتصال و اعتبار توکن.
     *
     * @return array<string, mixed>
     */
    public function ping(): array
    {
        if ($this->isWhm()) {
            $version = $this->whm('version');
            $hostname = null;
            try {
                $host = $this->whm('gethostname');
                $hostname = is_array($host) ? self::str($host['hostname'] ?? '') : self::str($host);
            } catch (CpanelError) {
                // اختیاریه؛ نبودش نباید تست اتصال رو شکست بده.
            }

            return [
                'type' => self::TYPE_WHM,
                'host' => $this->host(),
                'user' => $this->user(),
                'version' => is_array($version) ? self::str($version['version'] ?? '') : self::str($version),
                'hostname' => $hostname,
            ];
        }

        $info = [];
        try {
            $raw = $this->uapi('Variables', 'get_user_information');
            if (is_array($raw)) {
                $info = $raw;
            }
        } catch (CpanelError $e) {
            if (in_array($e->kind, [CpanelError::KIND_AUTH, CpanelError::KIND_TRANSPORT], true)) {
                throw $e;
            }
            // بعضی نسخه‌های قدیمی این ماژول رو ندارن؛ با یک فراخوانی همیشه‌موجود
            // مطمئن می‌شیم توکن واقعاً کار می‌کنه.
            $this->uapi('DomainInfo', 'list_domains');
        }

        return [
            'type' => self::TYPE_CPANEL,
            'host' => $this->host(),
            'user' => self::str($info['user'] ?? '') ?: $this->user(),
            'home' => self::str($info['home'] ?? '') ?: null,
            'version' => null,
            'hostname' => null,
        ];
    }

    /**
     * پراکسی /json-api/cpanel — هم برای API2 روی خود cPanel، هم برای اجرای
     * UAPI به‌نام یک کاربر از روی WHM.
     *
     * @param array<string, mixed> $params
     */
    private function jsonApiCpanel(int $apiVersion, string $module, string $function, array $params): mixed
    {
        $query = [
            'cpanel_jsonapi_apiversion' => $apiVersion,
            'cpanel_jsonapi_module' => $module,
            'cpanel_jsonapi_func' => $function,
        ];
        if ($this->isWhm()) {
            if ($this->asUser === null) {
                throw CpanelError::config('برای اجرای این دستور روی WHM باید کاربر مقصد مشخص شود.');
            }
            $query['cpanel_jsonapi_user'] = $this->asUser;
        }

        $decoded = $this->send($this->baseUrl() . '/json-api/cpanel', $query + $params);
        $label = "$module::$function";

        // شکل پاسخ بسته به نسخه و مسیر فرق می‌کنه؛ هر سه حالت شناخته‌شده رو
        // به یک ساختار واحد UAPI برمی‌گردونیم.
        if (isset($decoded['cpanelresult']) && is_array($decoded['cpanelresult'])) {
            $envelope = $decoded['cpanelresult'];
            if (self::str($envelope['error'] ?? '') !== '') {
                throw CpanelError::api("cPanel دستور «{$label}» را رد کرد: " . self::str($envelope['error']));
            }
            // API2 نتیجه را در event.result می‌گذارد، نه در status.
            $event = is_array($envelope['event'] ?? null) ? $envelope['event'] : [];
            if (isset($event['result']) && (int) $event['result'] !== 1) {
                $reason = self::str($event['reason'] ?? '') ?: 'دلیلی برنگشت';
                throw CpanelError::api("cPanel دستور «{$label}» را رد کرد: {$reason}");
            }
            if (isset($envelope['result']) && is_array($envelope['result'])) {
                return $this->unwrapUapi($envelope['result'], $label);
            }
            if (array_key_exists('data', $envelope)) {
                return $envelope['data'];
            }
        }
        if (isset($decoded['result']) && is_array($decoded['result'])) {
            return $this->unwrapUapi($decoded['result'], $label);
        }

        return $this->unwrapUapi($decoded, $label);
    }

    /**
     * @param array<string, mixed> $decoded
     */
    private function unwrapUapi(array $decoded, string $label): mixed
    {
        $errors = self::flatten($decoded['errors'] ?? null);
        $status = $decoded['status'] ?? null;

        if ($errors !== [] || ($status !== null && (int) $status !== 1)) {
            $message = $errors !== [] ? implode(' | ', $errors) : 'دلیلی برنگشت';
            throw CpanelError::api("cPanel دستور «{$label}» را رد کرد: $message");
        }

        if (!array_key_exists('data', $decoded)) {
            // بعضی توابع فقط messages برمی‌گردونن (مثلاً حذف موفق).
            return $decoded['messages'] ?? null;
        }

        return $decoded['data'];
    }

    /**
     * @param array<string, mixed> $params
     *
     * @return array<string, mixed>
     */
    private function send(string $url, array $params): array
    {
        return $this->sendRaw($url, self::buildQuery($params), 'application/x-www-form-urlencoded');
    }

    /**
     * @return array<string, mixed>
     */
    private function sendRaw(string $url, string $body, string $contentType): array
    {
        $headers = [
            'Authorization: ' . $this->authHeader(),
            'Accept: application/json',
            'Content-Type: ' . $contentType,
        ];

        $response = ($this->transport ?? self::curlTransport(...))($url, $body, $headers, $this->verifySsl());

        if (($response['error'] ?? '') !== '') {
            throw CpanelError::transport((string) $response['error']);
        }

        $status = (int) ($response['status'] ?? 0);
        $raw = (string) ($response['body'] ?? '');

        if ($status === 401 || $status === 403) {
            throw CpanelError::auth($status);
        }
        if ($status < 200 || $status >= 300) {
            throw CpanelError::http($status, $raw);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            throw CpanelError::http($status, $raw === '' ? '(پاسخ خالی بود)' : $raw);
        }

        return $decoded;
    }

    private function authHeader(): string
    {
        $scheme = $this->isWhm() ? 'whm' : 'cpanel';

        return $scheme . ' ' . $this->user() . ':' . self::str($this->server['token'] ?? '');
    }

    private function baseUrl(): string
    {
        // پورت می‌تواند هم جدا ذخیره شده باشد و هم به خود آدرس چسبیده باشد
        // (مثل server.example.com:2096)؛ اگر به آدرس چسبیده، همان اولویت دارد.
        $parsed = Address::parse($this->host());
        $port = $parsed['port'] ?? (int) ($this->server['port'] ?? 0);
        if ($port <= 0) {
            $port = Address::defaultPort($this->type());
        }

        return "https://{$parsed['host']}:{$port}";
    }

    /**
     * آدرسی که واقعاً به آن وصل می‌شویم — برای نمایش در تست اتصال.
     */
    public function endpoint(): string
    {
        return $this->baseUrl();
    }

    private function verifySsl(): bool
    {
        $value = $this->server['ssl_verify'] ?? true;
        if (is_string($value)) {
            return !in_array(strtolower(trim($value)), ['0', 'false', 'no', 'off', ''], true);
        }

        return (bool) $value;
    }

    /**
     * cPanel برای بعضی پارامترها (مثل add توی mass_edit_zone) کلید تکراری
     * می‌خواد، پس نمی‌شه از http_build_query استفاده کرد که آرایه‌ها رو
     * به‌صورت key[0] می‌سازه.
     *
     * @param array<string, mixed> $params
     */
    public static function buildQuery(array $params): string
    {
        $pairs = [];
        foreach ($params as $key => $value) {
            if (is_array($value)) {
                foreach ($value as $item) {
                    $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode(self::scalar($item));
                }
                continue;
            }
            $pairs[] = rawurlencode((string) $key) . '=' . rawurlencode(self::scalar($value));
        }

        return implode('&', $pairs);
    }

    private static function scalar(mixed $value): string
    {
        if (is_bool($value)) {
            return $value ? '1' : '0';
        }
        if ($value === null) {
            return '';
        }

        return (string) $value;
    }

    /**
     * @return array<int, string>
     */
    private static function flatten(mixed $errors): array
    {
        if ($errors === null || $errors === '' || $errors === []) {
            return [];
        }
        if (is_string($errors)) {
            return [$errors];
        }
        $out = [];
        foreach ((array) $errors as $error) {
            if (is_array($error)) {
                $out = array_merge($out, self::flatten($error));
            } elseif (trim((string) $error) !== '') {
                $out[] = trim((string) $error);
            }
        }

        return $out;
    }

    private static function str(mixed $value): string
    {
        return is_scalar($value) ? trim((string) $value) : '';
    }

    /**
     * @param array<int, string> $headers
     *
     * @return array{status: int, body: string, error: string}
     */
    private static function curlTransport(string $url, string $body, array $headers, bool $verifySsl): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $body,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_CONNECTTIMEOUT => self::CONNECT_TIMEOUT,
            CURLOPT_SSL_VERIFYPEER => $verifySsl,
            CURLOPT_SSL_VERIFYHOST => $verifySsl ? 2 : 0,
            CURLOPT_FOLLOWLOCATION => false,
        ]);
        $responseBody = curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        return [
            'status' => $status,
            'body' => $responseBody === false ? '' : (string) $responseBody,
            'error' => $responseBody === false ? ($error !== '' ? $error : 'اتصال ناموفق بود') : '',
        ];
    }
}
