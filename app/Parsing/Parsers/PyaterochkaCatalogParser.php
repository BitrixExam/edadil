<?php

namespace App\Parsing\Parsers;

use App\Parsing\Contracts\CatalogParser;
use App\Parsing\Data\ParsedProduct;
use App\Parsing\Exceptions\ProductParseException;
use DiDom\Document;
use DiDom\Element;

class PyaterochkaCatalogParser implements CatalogParser
{
    public function supports(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $path = (string) parse_url($url, PHP_URL_PATH);

        return str_contains($host, "5ka.ru") && str_contains($path, "/catalog/");
    }

    public function shopName(): string
    {
        return "Пятёрочка";
    }

    public function shopBaseUrl(): string
    {
        return "https://5ka.ru";
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

    public function parseCatalog(string $html, string $url): array
    {
        $document = new Document($html);
        $products = $this->extractProductsFromJsonLd($document);
        if ($products !== []) {
            return $products;
        }

        $products = [];
        $seenUrls = [];

        foreach ($document->find("a[href]") as $link) {
            if (!$link instanceof Element) {
                continue;
            }

            $productUrl = $this->extractProductUrl($link, $url);
            if ($productUrl === null || isset($seenUrls[$productUrl])) {
                continue;
            }

            $text = $this->extractRawText($link);
            $price = $this->extractPriceFromText($text);
            $name = $this->extractName($link, $text);
            $internalProductId = $this->extractProductIdFromUrl($productUrl);

            if ($price === null || $name === null) {
                continue;
            }

            $products[] = new ParsedProduct(
                name: $name,
                price: $price,
                url: $productUrl,
                internalProductId: $internalProductId,
                isInStock: !str_contains(mb_strtolower($text), "нет в наличии"),
            );

            $seenUrls[$productUrl] = true;
        }

        if ($products === []) {
            throw new ProductParseException(
                "Не удалось извлечь товары из каталога Пятёрочки.",
            );
        }

        return $products;
    }

    /**
     * @return array<int, ParsedProduct>
     */
    private function extractProductsFromJsonLd(Document $document): array
    {
        $products = [];
        $seenUrls = [];

        foreach ($document->find('script[type="application/ld+json"]') as $script) {
            if (!$script instanceof Element) {
                continue;
            }

            $decoded = json_decode($script->text(), true);
            if (json_last_error() !== JSON_ERROR_NONE || $decoded === null) {
                continue;
            }

            foreach ($this->flattenNodes($decoded) as $node) {
                if (!is_array($node)) {
                    continue;
                }

                $name = $this->normalizeText((string) ($node["name"] ?? ""));
                $rawUrl = $this->normalizeText((string) ($node["url"] ?? ""));
                $price = $this->extractPriceFromNode($node);

                if ($name === null || $rawUrl === null || $price === null) {
                    continue;
                }

                $productUrl = $this->makeAbsoluteUrl($rawUrl, $this->shopBaseUrl());
                if (!str_contains((string) parse_url($productUrl, PHP_URL_PATH), "/product/")) {
                    continue;
                }

                if (isset($seenUrls[$productUrl])) {
                    continue;
                }

                $products[] = new ParsedProduct(
                    name: $name,
                    price: $price,
                    url: $productUrl,
                    internalProductId: $this->extractProductIdFromUrl($productUrl),
                    manufacturerCode: $this->normalizeText((string) ($node["mpn"] ?? "")),
                    isInStock: !str_contains(
                        mb_strtolower(json_encode($node, JSON_UNESCAPED_UNICODE) ?: ""),
                        "outofstock",
                    ),
                );

                $seenUrls[$productUrl] = true;
            }
        }

        return $products;
    }

    private function extractProductUrl(Element $link, string $catalogUrl): ?string
    {
        $href = trim((string) $link->getAttribute("href"));
        if ($href === "" || str_starts_with($href, "#")) {
            return null;
        }

        $absoluteUrl = $this->makeAbsoluteUrl($href, $catalogUrl);
        $path = (string) parse_url($absoluteUrl, PHP_URL_PATH);

        return str_contains($path, "/product/") ? $absoluteUrl : null;
    }

    private function makeAbsoluteUrl(string $href, string $catalogUrl): string
    {
        if (str_starts_with($href, "http://") || str_starts_with($href, "https://")) {
            return $href;
        }

        if (str_starts_with($href, "/")) {
            return rtrim($this->shopBaseUrl(), "/") . $href;
        }

        return rtrim($catalogUrl, "/") . "/" . ltrim($href, "/");
    }

    private function extractRawText(Element $link): string
    {
        return $this->normalizeText($link->text()) ?? "";
    }

    private function extractName(Element $link, string $text): ?string
    {
        foreach (["aria-label", "title", "data-title"] as $attribute) {
            $value = $this->normalizeText($link->getAttribute($attribute));
            if ($value !== null) {
                return $value;
            }
        }

        $cleaned = preg_replace('/-?\d+%\s*/u', "", $text) ?? $text;
        $cleaned = preg_replace('/\d+\s*\d{2}\s*₽/u', " ", $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\d+(?:[.,]\d{2})?\s*₽/u', " ", $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\b(нет в наличии|выгодно|акция|новинка|хит)\b/ui', " ", $cleaned) ?? $cleaned;
        $cleaned = preg_replace('/\s+/u', " ", $cleaned) ?? $cleaned;

        return $this->normalizeText($cleaned);
    }

    private function extractPriceFromText(string $text): ?float
    {
        preg_match_all('/(\d{1,4})\s*(\d{2})\s*₽/u', $text, $splitMatches, PREG_SET_ORDER);
        if ($splitMatches !== []) {
            $last = end($splitMatches);

            return round((float) ($last[1] . "." . $last[2]), 2);
        }

        preg_match_all('/(\d+(?:[.,]\d{2})?)\s*₽/u', $text, $matches, PREG_SET_ORDER);
        if ($matches === []) {
            return null;
        }

        $last = end($matches);

        return round((float) str_replace(",", ".", $last[1]), 2);
    }

    /**
     * @param  array<string, mixed>  $node
     */
    private function extractPriceFromNode(array $node): ?float
    {
        $candidates = [$node["price"] ?? null];

        if (isset($node["offers"]) && is_array($node["offers"])) {
            $offers = isset($node["offers"][0]) ? $node["offers"] : [$node["offers"]];
            foreach ($offers as $offer) {
                if (is_array($offer)) {
                    $candidates[] = $offer["price"] ?? null;
                    $candidates[] = $offer["lowPrice"] ?? null;
                }
            }
        }

        foreach ($candidates as $candidate) {
            if ($candidate === null || $candidate === "") {
                continue;
            }

            $normalized = str_replace(",", ".", (string) $candidate);
            if (is_numeric($normalized)) {
                return round((float) $normalized, 2);
            }
        }

        return null;
    }

    private function extractProductIdFromUrl(string $url): ?string
    {
        if (preg_match('/--(\d+)\/?$/', (string) parse_url($url, PHP_URL_PATH), $matches) === 1) {
            return $matches[1];
        }

        return null;
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
     * @param  mixed  $node
     * @return array<int, mixed>
     */
    private function flattenNodes(mixed $node): array
    {
        $items = [$node];

        if (!is_array($node)) {
            return $items;
        }

        foreach ($node as $value) {
            if (is_array($value)) {
                array_push($items, ...$this->flattenNodes($value));
            }
        }

        return $items;
    }
}
