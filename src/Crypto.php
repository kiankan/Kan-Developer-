<?php

declare(strict_types=1);

/**
 * رمزنگاری اطلاعات حساس (رمز عبور/توکن هاست‌های cPanel) قبل از ذخیره روی دیسک،
 * با AES-256-GCM. کلید از APP_KEY (متغیر محیطی، base64 یِ ۳۲ بایت) خوانده
 * می‌شود؛ اگر تنظیم نشده باشد یک کلید تصادفی ساخته و در data/app.key نگه
 * می‌داریم تا بین اجراهای بعدی هم قابل رمزگشایی بماند.
 */
final class Crypto
{
    private const CIPHER = 'aes-256-gcm';

    public static function encrypt(string $plaintext): string
    {
        $key = self::key();
        $iv = random_bytes(12);
        $tag = '';
        $cipherText = openssl_encrypt($plaintext, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag, '', 16);
        if ($cipherText === false) {
            throw new RuntimeException('رمزنگاری اطلاعات ناموفق بود.');
        }

        return base64_encode($iv . $tag . $cipherText);
    }

    public static function decrypt(string $payload): string
    {
        $key = self::key();
        $raw = base64_decode($payload, true);
        if ($raw === false || strlen($raw) < 28) {
            throw new RuntimeException('داده‌ی رمزشده نامعتبر است.');
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipherText = substr($raw, 28);
        $plaintext = openssl_decrypt($cipherText, self::CIPHER, $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($plaintext === false) {
            throw new RuntimeException('رمزگشایی اطلاعات ناموفق بود (احتمالاً APP_KEY عوض شده).');
        }

        return $plaintext;
    }

    private static function key(): string
    {
        $fromEnv = getenv('APP_KEY');
        if (is_string($fromEnv) && trim($fromEnv) !== '') {
            $decoded = base64_decode(trim($fromEnv), true);
            if ($decoded !== false && strlen($decoded) === 32) {
                return $decoded;
            }
            fwrite(STDERR, "APP_KEY نامعتبر است (باید base64 مربوط به ۳۲ بایت باشد)؛ از کلید فایل/تصادفی استفاده می‌شود.\n");
        }

        $path = dirname(__DIR__) . '/data/app.key';
        if (is_file($path)) {
            $decoded = base64_decode(trim((string) file_get_contents($path)), true);
            if ($decoded !== false && strlen($decoded) === 32) {
                return $decoded;
            }
        }

        $newKey = random_bytes(32);
        @mkdir(dirname($path), 0755, true);
        file_put_contents($path, base64_encode($newKey));
        @chmod($path, 0600);

        return $newKey;
    }
}
