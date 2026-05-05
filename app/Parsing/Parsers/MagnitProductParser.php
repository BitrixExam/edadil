<?php

namespace App\Parsing\Parsers;

use App\Parsing\Contracts\ProductParser;
use App\Parsing\Data\ParsedProduct;
use App\Parsing\Exceptions\ProductParseException; // не используемый use, так как класс пустой
use DiDom\Document;
use DiDom\Element;

class MagnitProductParser implements ProductParser
{
    public function supports(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);

        return str_contains($host, "magnit.ru");
    }

    public function shopName(): string
    {
        return "Магнит";
    }

    public function shopBaseUrl(): string
    {
        return "https://magnit.ru";
    }

    public function defaultHeaders(): array
    {
        return [
            "User-Agent" => config(
                "parsers.http.user_agent",
                "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36",
            ),
            "Accept" => "text/html,application/xhtml+xml,application/xml;q=0.9,image/webp,*/*;q=0.8",
            "Accept-Language" => "ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7",
            "Referer" => $this->shopBaseUrl() . "/",
        ];
    }

    public function parse(string $html, string $url): ParsedProduct
    {
        $document = new Document($html);

        $jsonLd = $this->extractJsonLdPayload($document);
        $name = $this->extractName($document, $jsonLd);
        $price = $this->extractPrice($document, $jsonLd);
        $internalProductId = $this->extractInternalProductId($jsonLd, $url);
        $manufacturerCode = $this->extractManufacturerCode($jsonLd);

        return new ParsedProduct(
            name: $name,
            price: $price,
            internalProductId: $internalProductId,
            manufacturerCode: $manufacturerCode,
            isInStock: true,
        );
    }

    /**
     * @param  array<int, mixed>  $jsonLd
     */
    private function extractName(Document $document, array $jsonLd): string
    {
        $selectors = [
            'h1[data-test-id="v-product-details-name"]',
            "h1",
            'meta[property="og:title"]',
            "title",
        ];

        foreach ($selectors as $selector) {
            $element = $document->first($selector);

            if (!$element) {
                continue;
            }

            $value = $element->tagName() === "meta"
                ? $element->getAttribute("content")
                : $element->text();

            if ($normalized = $this->normalizeText($value)) {
                return $normalized;
            }
        }

        $jsonLdName = $this->findFirstString($jsonLd, ["name", "headline"]);
        if ($jsonLdName !== null) {
            return $jsonLdName;
        }

        throw new ProductParseException("Не удалось извлечь название товара.");
    }

    /**
     * @param  array<int, mixed>  $jsonLd
     */
    private function extractPrice(Document $document, array $jsonLd): float
    {
        $metaPrice = $document->first('meta[itemprop="price"]');
        if ($metaPrice) {
            $price = $this->parsePriceValue($metaPrice->getAttribute("content"));
            if ($price !== null) {
                return $price;
            }
        }

        $itemPropPrice = $document->first('[itemprop="price"]');
        if ($itemPropPrice) {
            $price = $this->parsePriceValue(
                $itemPropPrice->getAttribute("content") ?: $itemPropPrice->text(),
            );
            if ($price !== null) {
                return $price;
            }
        }

        foreach ($this->flattenJsonLd($jsonLd) as $node) {
            if (!is_array($node)) {
                continue;
            }

            foreach (["price", "lowPrice"] as $field) {
                if (!array_key_exists($field, $node)) {
                    continue;
                }

                $price = $this->parsePriceValue($node[$field]);
                if ($price !== null) {
                    return $price;
                }
            }
        }

        throw new ProductParseException("Не удалось извлечь цену товара.");
    }

    /**
     * @param  array<int, mixed>  $jsonLd
     */
    private function extractInternalProductId(array $jsonLd, string $url): ?string
    {
        $candidate = $this->findFirstScalar($jsonLd, ["sku", "productID", "productId"]);
        if ($candidate !== null) {
            return $candidate;
        }

        if (preg_match("~/(\\d+)(?:/)?(?:\\?.*)?$~", $url, $matches) === 1) {
            return $matches[1];
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $jsonLd
     */
    private function extractManufacturerCode(array $jsonLd): ?string
    {
        return $this->findFirstScalar($jsonLd, ["mpn", "gtin13", "gtin", "gtin14"]);
    }

    /**
     * @return array<int, mixed>
     */
    private function extractJsonLdPayload(Document $document): array
    {
        $payload = [];

        foreach ($document->find('script[type="application/ld+json"]') as $script) {
            if (!$script instanceof Element) {
                continue;
            }

            $decoded = json_decode($script->text(), true);
            if (json_last_error() === JSON_ERROR_NONE && $decoded !== null) {
                $payload[] = $decoded;
            }
        }

        return $payload;
    }

    private function normalizeText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', " ", $value) ?? "");

        return $value !== "" ? $value : null;
    }

    /**
     * @param  mixed  $value
     */
    private function parsePriceValue(mixed $value): ?float
    {
        if ($value === null || $value === "") {
            return null;
        }

        if (is_numeric($value)) {
            return round((float) $value, 2);
        }

        if (!is_string($value)) {
            return null;
        }

        $normalized = str_replace(
            [",", " ", "\u{00A0}", "₽", "руб.", "руб"],
            [".", "", "", "", "", ""],
            trim($value),
        );

        if (!preg_match('/\d+(?:\.\d+)?/', $normalized, $matches)) {
            return null;
        }

        return round((float) $matches[0], 2);
    }

    /**
     * @param  array<int, mixed>  $payload
     * @return array<int, mixed>
     */
    private function flattenJsonLd(array $payload): array
    {
        $items = [];

        foreach ($payload as $node) {
            $this->appendFlattenedNode($items, $node);
        }

        return $items;
    }

    /**
     * @param  array<int, mixed>  $items
     * @param  mixed  $node
     */
    private function appendFlattenedNode(array &$items, mixed $node): void
    {
        $items[] = $node;

        if (!is_array($node)) {
            return;
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                $this->appendFlattenedNode($items, $value);
            }
        }
    }

    /**
     * @param  array<int, mixed>  $payload
     * @param  array<int, string>  $keys
     */
    private function findFirstScalar(array $payload, array $keys): ?string
    {
        foreach ($this->flattenJsonLd($payload) as $node) {
            if (!is_array($node)) {
                continue;
            }

            foreach ($keys as $key) {
                if (!array_key_exists($key, $node) || is_array($node[$key])) {
                    continue;
                }

                $value = $this->normalizeText((string) $node[$key]);
                if ($value !== null) {
                    return $value;
                }
            }
        }

        return null;
    }

    /**
     * @param  array<int, mixed>  $payload
     * @param  array<int, string>  $keys
     */
    private function findFirstString(array $payload, array $keys): ?string
    {
        return $this->findFirstScalar($payload, $keys);
    }
}
