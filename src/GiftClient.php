<?php

declare(strict_types=1);

class GiftError extends RuntimeException
{
}

/**
 * چکر گیفت‌های NFT تلگرام: هم از t.me/nft/<slug> (اطلاعات مالک/ویژگی‌ها،
 * مستقیم از خود تلگرام) و هم — در صورت تنظیم GIFT_ASSET_API_KEY — از
 * giftasset.gifts (قیمت کف به تفکیک Model/Backdrop روی Tonnel/Portals/MRKT/
 * GetGems). بدون کلید، برای قیمت از آخرین فروش ثبت‌شده روی Fragment استفاده
 * می‌شه که همیشه در دسترسه.
 */
final class GiftClient
{
    private const USER_AGENT = 'Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 '
        . '(KHTML, like Gecko) Chrome/120.0 Safari/537.36';
    private const GIFT_ASSET_API_BASE = 'https://giftasset.gifts';
    private const TIMEOUT = 15;

    public static function extractSlug(string $input): ?string
    {
        $input = trim($input);

        if (preg_match('#t\.me/nft/([A-Za-z0-9]+-\d+)#i', $input, $m)) {
            return $m[1];
        }
        if (preg_match('#fragment\.com/gift/([A-Za-z0-9]+-\d+)#i', $input, $m)) {
            return $m[1];
        }
        if (preg_match('/^[A-Za-z0-9]+-\d+$/', $input)) {
            return $input;
        }

        return null;
    }

    public static function fetchGift(string $input): array
    {
        $slug = self::extractSlug($input);
        if ($slug === null) {
            throw new GiftError("اسلاگ گیفت نامعتبر است: $input");
        }

        $attrs = self::fetchTelegramAttributes($slug);
        $prices = self::fetchPrices($slug, $attrs['name'], $attrs['model'], $attrs['backdrop']);

        return array_merge($attrs, [
            'slug' => $slug,
            'prices' => self::enrichPrices($prices['groups']),
            'source' => $prices['source'],
        ]);
    }

    /**
     * @return array{name: string, number: ?string, owner: ?string, model: ?string,
     *               backdrop: ?string, symbol: ?string, quantity_text: ?string}
     */
    private static function fetchTelegramAttributes(string $slug): array
    {
        $ch = curl_init('https://t.me/nft/' . rawurlencode($slug));
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_HTTPHEADER => ['User-Agent: ' . self::USER_AGENT],
        ]);
        $html = curl_exec($ch);
        $errno = curl_errno($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0 || $status !== 200 || $html === false || $html === '') {
            throw new GiftError("گیفتی با این اسلاگ پیدا نشد: $slug");
        }

        $dom = new DOMDocument();
        libxml_use_internal_errors(true);
        $dom->loadHTML(mb_convert_encoding($html, 'HTML-ENTITIES', 'UTF-8'), LIBXML_NOERROR | LIBXML_NOWARNING);
        libxml_clear_errors();
        $xpath = new DOMXPath($dom);

        $titleNodes = $xpath->query('//meta[@property="og:title"]/@content');
        if ($titleNodes->length === 0) {
            throw new GiftError("گیفتی با این اسلاگ پیدا نشد: $slug");
        }
        $title = trim($titleNodes->item(0)->nodeValue);
        $name = $title;
        $number = null;
        if (preg_match('/^(.*)\s#(\d+)$/u', $title, $m)) {
            $name = trim($m[1]);
            $number = $m[2];
        }

        $fields = [];
        foreach ($xpath->query('//tr[th and td]') as $row) {
            $label = trim(preg_replace('/\s+/u', ' ', $xpath->query('.//th', $row)->item(0)->textContent));
            $value = trim(preg_replace('/\s+/u', ' ', $xpath->query('.//td', $row)->item(0)->textContent));
            if ($label !== '') {
                $fields[$label] = $value;
            }
        }

        return [
            'name' => $name,
            'number' => $number,
            'owner' => $fields['Owner'] ?? null,
            'model' => isset($fields['Model']) ? trim(preg_replace('/\s*\d+(\.\d+)?%$/u', '', $fields['Model'])) : null,
            'backdrop' => isset($fields['Backdrop']) ? trim(preg_replace('/\s*\d+(\.\d+)?%$/u', '', $fields['Backdrop'])) : null,
            'symbol' => isset($fields['Symbol']) ? trim(preg_replace('/\s*\d+(\.\d+)?%$/u', '', $fields['Symbol'])) : null,
            'quantity_text' => $fields['Quantity'] ?? null,
        ];
    }

    /**
     * @return array{groups: list<array{label: string, value: string, ton: float}>, source: string}
     */
    private static function fetchPrices(string $slug, string $name, ?string $model, ?string $backdrop): array
    {
        $apiKey = getenv('GIFT_ASSET_API_KEY');
        if ($apiKey !== false && trim($apiKey) !== '' && $model !== null && $backdrop !== null) {
            $groups = self::fetchGiftAssetFloors(trim($apiKey), $name, $model, $backdrop);
            if ($groups !== null) {
                return ['groups' => $groups, 'source' => 'Tonnel | Portals | MRKT | GetGems'];
            }
        }

        // بدون کلید (یا اگه جستجو نتیجه نداد)، از آخرین قیمت فروش خود Fragment استفاده می‌کنیم.
        try {
            $auction = FragmentClient::fetchAuction("https://fragment.com/gift/$slug");
        } catch (FragmentError $e) {
            throw new GiftError($e->getMessage());
        }
        $groups = [];
        if ($auction['highest_bid'] !== null) {
            $price = (float) str_replace(',', '', $auction['highest_bid']);
            $label = ($model !== null && $backdrop !== null) ? 'Model & Backdrop' : 'Sale Price';
            $value = ($model !== null && $backdrop !== null) ? "$model & $backdrop" : $auction['status_text'];
            $groups[] = ['label' => $label, 'value' => $value, 'ton' => $price];
        }

        return ['groups' => $groups, 'source' => 'Fragment'];
    }

    /**
     * @return list<array{label: string, value: string, ton: float}>|null
     */
    private static function fetchGiftAssetFloors(string $apiKey, string $collectionName, string $model, string $backdrop): ?array
    {
        $url = self::GIFT_ASSET_API_BASE . '/api/v1/gifts/get_unique_gifts_price_list?'
            . http_build_query(['collection_name' => $collectionName]);

        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => self::TIMEOUT,
            CURLOPT_HTTPHEADER => ['X-API-Key: ' . $apiKey, 'Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $ok = curl_errno($ch) === 0 && curl_getinfo($ch, CURLINFO_HTTP_CODE) === 200;
        curl_close($ch);

        if (!$ok) {
            return null;
        }

        $data = json_decode((string) $body, true);
        $floors = $data['collection_floors'] ?? null;
        if (!is_array($floors)) {
            return null;
        }

        $comboKey = $modelKey = $backdropKey = null;
        foreach (array_keys($floors) as $key) {
            $hasModel = mb_stripos((string) $key, $model) !== false;
            $hasBackdrop = mb_stripos((string) $key, $backdrop) !== false;
            if ($hasModel && $hasBackdrop) {
                $comboKey = $key;
            } elseif ($hasModel) {
                $modelKey ??= $key;
            } elseif ($hasBackdrop) {
                $backdropKey ??= $key;
            }
        }

        $groups = [];
        if ($comboKey !== null && ($ton = self::bestFloor($floors[$comboKey])) !== null) {
            $groups[] = ['label' => 'Model & Backdrop', 'value' => "$model & $backdrop", 'ton' => $ton];
        }
        if ($modelKey !== null && ($ton = self::bestFloor($floors[$modelKey])) !== null) {
            $groups[] = ['label' => 'Model', 'value' => $model, 'ton' => $ton];
        }
        if ($backdropKey !== null && ($ton = self::bestFloor($floors[$backdropKey])) !== null) {
            $groups[] = ['label' => 'Backdrop', 'value' => $backdrop, 'ton' => $ton];
        }

        return $groups === [] ? null : $groups;
    }

    /**
     * @param list<array{label: string, value: string, ton: float}> $groups
     * @return list<array{label: string, value: string, ton: float, toman: ?float, usd: ?float, stars: ?int}>
     */
    private static function enrichPrices(array $groups): array
    {
        if ($groups === []) {
            return [];
        }

        $tonRate = WalletClient::fetchRates('TON');
        $starsRate = (float) TextStore::get(TextStore::KEY_STARS_RATE);

        return array_map(static function (array $g) use ($tonRate, $starsRate) {
            $g['toman'] = $tonRate['toman'] !== null ? $g['ton'] * $tonRate['toman'] : null;
            $g['usd'] = $tonRate['usd'] !== null ? $g['ton'] * $tonRate['usd'] : null;
            $g['stars'] = ($starsRate > 0 && $g['usd'] !== null) ? (int) round($g['usd'] / $starsRate) : null;

            return $g;
        }, $groups);
    }

    private static function bestFloor(array $entry): ?float
    {
        $values = array_filter(
            [$entry['tonnel'] ?? null, $entry['portals'] ?? null, $entry['mrkt'] ?? null, $entry['getgems'] ?? null],
            static fn ($v) => is_numeric($v)
        );

        return $values === [] ? null : (float) min($values);
    }
}
