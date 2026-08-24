<?php

declare(strict_types=1);

/**
 * خطای پایه: هر مشکلی که از سمت cPanel یا شبکه پیش بیاد. اگه دقیقاً از
 * زیرکلاس‌های CpanelConnectionError یا CpanelAuthError نباشه، یعنی درخواست
 * واقعاً به cPanel رسیده و با موفقیت احراز هویت شده، ولی خودِ آن تابع/ماژول
 * خاص رد شده (مثلاً چون یوزرنیم/توکن به یک ویژگی محدود شده — مثل توکنی که
 * فقط دسترسی Zone Edit داره). چنین خطایی نشونه‌ی نامعتبربودن اتصال نیست.
 */
class CpanelError extends RuntimeException
{
}

/**
 * اتصال به هاست برقرار نشد (شبکه/DNS/پورت اشتباه/پاسخ غیرمنتظره) — نه
 * مشکل نام‌کاربری/رمز.
 */
final class CpanelConnectionError extends CpanelError
{
}

/**
 * نام‌کاربری/رمز/توکن رد شد (HTTP 401/403).
 */
final class CpanelAuthError extends CpanelError
{
}

/**
 * کلاینت اتصال به هاست اشتراکی cPanel با نام‌کاربری/رمز یا API Token خودِ
 * صاحب هاست (نه WHM/root). دو سبک تماس پشتیبانی می‌شود:
 *  - uapi(): رابط جدید و توصیه‌شده‌ی cPanel (https://host:port/execute/...)
 *  - api2(): رابط قدیمی‌تر ولی هنوز کاملاً پشتیبانی‌شده (json-api/cpanel)،
 *    برای بخش‌هایی (زیردامنه، ویرایشگر DNS، تغییر رمز خودِ اکانت) که هنوز
 *    معادل رسمی در UAPI ندارند.
 */
final class CpanelClient
{
    public function __construct(
        private readonly string $host,
        private readonly int $port,
        private readonly string $username,
        private readonly string $secret,
        private readonly string $authType, // 'password' | 'token'
    ) {
    }

    public static function fromProfile(array $profile): self
    {
        return new self(
            (string) $profile['host'],
            (int) $profile['port'],
            (string) $profile['username'],
            (string) $profile['secret'],
            (string) $profile['auth_type'],
        );
    }

    /**
     * فراخوانی UAPI: /execute/{module}/{function}
     *
     * @return array<mixed>
     */
    public function uapi(string $module, string $function, array $params = []): array
    {
        $url = sprintf('https://%s:%d/execute/%s/%s', $this->host, $this->port, $module, $function);

        return self::parseUapiResponse($this->request($url, $params));
    }

    /**
     * @return array<mixed>
     */
    private static function parseUapiResponse(mixed $decoded): array
    {
        if (!is_array($decoded)) {
            throw new CpanelError('پاسخ نامعتبر از UAPI دریافت شد.');
        }
        if ((int) ($decoded['status'] ?? 0) !== 1) {
            throw new CpanelError(self::extractUapiError($decoded));
        }

        return is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    }

    /**
     * فراخوانی cPanel API 2 (قدیمی): json-api/cpanel?cpanel_jsonapi_module=...
     *
     * @return array<mixed>
     */
    public function api2(string $module, string $function, array $params = []): array
    {
        $url = sprintf('https://%s:%d/json-api/cpanel', $this->host, $this->port);
        $params = array_merge([
            'cpanel_jsonapi_apiversion' => '2',
            'cpanel_jsonapi_module' => $module,
            'cpanel_jsonapi_func' => $function,
        ], $params);

        return self::parseApi2Response($this->request($url, $params));
    }

    /**
     * @return array<mixed>
     */
    private static function parseApi2Response(mixed $decoded): array
    {
        $result = is_array($decoded) ? ($decoded['cpanelresult'] ?? null) : null;
        if (!is_array($result)) {
            throw new CpanelError('پاسخ نامعتبر از cPanel API دریافت شد.');
        }
        if (!empty($result['error'])) {
            throw new CpanelError((string) $result['error']);
        }
        $data = is_array($result['data'] ?? null) ? $result['data'] : [];

        $first = $data[0] ?? null;
        if (is_array($first) && array_key_exists('result', $first) && (int) $first['result'] !== 1) {
            throw new CpanelError((string) ($first['reason'] ?? 'خطای نامشخص از cPanel'));
        }
        $event = $result['event'] ?? null;
        if (is_array($event) && array_key_exists('result', $event) && (int) $event['result'] !== 1) {
            throw new CpanelError((string) ($event['reason'] ?? $result['error'] ?? 'خطای نامشخص از cPanel'));
        }

        return $data;
    }

    private function request(string $url, array $params): mixed
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query($params),
            CURLOPT_HTTPHEADER => ['Authorization: ' . $this->authHeader()],
            CURLOPT_TIMEOUT => 30,
            CURLOPT_SSL_VERIFYPEER => !self::insecureSsl(),
            CURLOPT_SSL_VERIFYHOST => self::insecureSsl() ? 0 : 2,
        ]);
        $body = curl_exec($ch);
        if ($body === false) {
            $error = curl_error($ch);
            curl_close($ch);
            throw new CpanelConnectionError("اتصال به هاست برقرار نشد: $error");
        }
        $httpCode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($httpCode === 401 || $httpCode === 403) {
            throw new CpanelAuthError("نام‌کاربری یا رمز/توکن اشتباه است (HTTP $httpCode).");
        }
        if ($httpCode >= 500) {
            throw new CpanelConnectionError("خطای سمت هاست (HTTP $httpCode).");
        }

        $decoded = json_decode((string) $body, true);
        if (!is_array($decoded)) {
            throw new CpanelConnectionError("پاسخ غیرمنتظره از هاست دریافت شد (HTTP $httpCode). آیا آدرس/پورت درست است؟");
        }

        return $decoded;
    }

    private function authHeader(): string
    {
        return $this->authType === 'token'
            ? sprintf('cpanel %s:%s', $this->username, $this->secret)
            : 'Basic ' . base64_encode($this->username . ':' . $this->secret);
    }

    private static function insecureSsl(): bool
    {
        $raw = getenv('CPANEL_INSECURE_SSL');

        return is_string($raw) && in_array(strtolower(trim($raw)), ['1', 'true', 'yes'], true);
    }

    private static function extractUapiError(array $decoded): string
    {
        $errors = $decoded['errors'] ?? null;
        if (is_array($errors) && $errors !== []) {
            return implode('; ', array_map('strval', $errors));
        }
        if (is_string($errors) && $errors !== '') {
            return $errors;
        }

        return 'خطای نامشخص از cPanel';
    }
}
