<?php

declare(strict_types=1);

class FragmentError extends RuntimeException
{
}

class FragmentNotFoundError extends FragmentError
{
}

final class FragmentClient
{
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/120.0 Safari/537.36';
    private const TIMEOUT = 15;

    public static function resolveUrl(string $raw): string
    {
        $raw = trim($raw);

        if (str_contains($raw, '/dapp/')) {
            $raw = substr($raw, strpos($raw, '/dapp/') + strlen('/dapp/'));
        }

        $raw = urldecode($raw);

        if (str_contains($raw, 'fragment.com')) {
            if (!str_starts_with($raw, 'http')) {
                $raw = 'https://' . ltrim($raw, '/');
            }

            return $raw;
        }

        // اسلاگ گیفت‌های NFT فرگمنت مثل "durovscap-1142" (کالکشن-شماره) — یوزرنیم
        // تلگرام هیچ‌وقت خط‌تیره نداره، پس این تشخیص با یوزرنیم تداخلی نداره.
        if (preg_match('/^[A-Za-z0-9]+-\d+$/', $raw)) {
            return "https://fragment.com/gift/$raw";
        }

        $username = ltrim($raw, '@');
        if (!preg_match('/^[A-Za-z0-9_]{3,}$/', $username)) {
            throw new FragmentError("ورودی نامعتبر است: $raw");
        }

        return "https://fragment.com/username/$username";
    }

    public static function fetchAuction(string $raw): array
    {
        $url = self::resolveUrl($raw);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_HTTPHEADER => ['User-Agent: ' . self::USER_AGENT],
        ]);
        $html = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = curl_error($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new FragmentError("خطا در اتصال به Fragment: $error");
        }
        if ($status === 404) {
            throw new FragmentNotFoundError("آیتمی با این آدرس روی Fragment پیدا نشد: $url");
        }
        if ($status !== 200) {
            throw new FragmentError("Fragment پاسخ غیرمنتظره داد (HTTP $status)");
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $headerNodes = $xpath->query('//h2' . self::classPredicate('tm-section-header-text'));
        if ($headerNodes->length === 0) {
            throw new FragmentNotFoundError("اطلاعاتی برای این آیتم پیدا نشد: $url");
        }
        $header = $headerNodes->item(0);

        $domainNodes = $xpath->query('.//*' . self::classPredicate('tm-section-header-domain'), $header);
        $name = $domainNodes->length > 0 ? self::textContent($domainNodes->item(0)) : $raw;

        $statusNodes = $xpath->query('.//*' . self::classPredicate('tm-section-header-status'), $header);
        $statusText = $statusNodes->length > 0 ? self::textContent($statusNodes->item(0)) : 'نامشخص';
        $statusSlug = null;
        if ($statusNodes->length > 0) {
            foreach (explode(' ', $statusNodes->item(0)->getAttribute('class')) as $cls) {
                if (str_starts_with($cls, 'tm-status-')) {
                    $statusSlug = substr($cls, strlen('tm-status-'));
                }
            }
        }

        $bidNodes = $xpath->query(
            '//*' . self::classPredicate('tm-section-bid-info')
            . '//div' . self::classPredicate('table-cell-value')
        );
        $bids = [];
        foreach ($bidNodes as $node) {
            if (self::hasClass($node, 'tm-value')) {
                $bids[] = self::textContent($node);
            }
        }
        $highestBid = $bids[0] ?? null;
        $bidStep = $bids[1] ?? null;
        $minimumBid = $bids[2] ?? null;

        // فقط توی صفحه‌ی گیفت‌های NFT وجود داره (Owner/Model/Backdrop/Symbol/Issued)؛
        // برای یوزرنیم/شماره خالی می‌مونه و بی‌اثره.
        $attributes = [];
        $attrRows = $xpath->query(
            '//*' . self::classPredicate('tm-section-auction-info') . '//table//tr'
        );
        foreach ($attrRows as $row) {
            $cells = $xpath->query('.//td', $row);
            if ($cells->length < 2) {
                continue;
            }
            $label = self::textContent($cells->item(0));
            $value = self::textContent($cells->item(1));
            if ($label !== '' && $value !== '') {
                $attributes[$label] = $value;
            }
        }

        $endsAt = null;
        $remainingSeconds = null;
        $timerNodes = $xpath->query('//time' . self::classPredicate('tm-countdown-timer'));
        if ($timerNodes->length > 0) {
            $datetimeAttr = $timerNodes->item(0)->getAttribute('datetime');
            if ($datetimeAttr !== '') {
                try {
                    $endsAt = new DateTimeImmutable($datetimeAttr);
                    $remainingSeconds = max(0, Format::secondsUntil($endsAt));
                } catch (Exception $e) {
                    $endsAt = null;
                }
            }
        }

        return [
            'query' => $raw,
            'url' => $url,
            'name' => $name,
            'status_text' => $statusText,
            'status_slug' => $statusSlug,
            'highest_bid' => $highestBid,
            'bid_step' => $bidStep,
            'minimum_bid' => $minimumBid,
            'attributes' => $attributes,
            'ends_at' => $endsAt,
            'remaining_seconds' => $remainingSeconds,
        ];
    }

    private static function classPredicate(string $class): string
    {
        return "[contains(concat(' ', normalize-space(@class), ' '), ' $class ')]";
    }

    private static function hasClass(DOMElement $node, string $class): bool
    {
        $classes = preg_split('/\s+/', trim($node->getAttribute('class')));

        return in_array($class, $classes, true);
    }

    private static function textContent(DOMNode $node): string
    {
        return trim(preg_replace('/\s+/u', ' ', $node->textContent));
    }
}
