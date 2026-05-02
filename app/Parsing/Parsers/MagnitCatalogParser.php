<?php

namespace App\Parsing\Parsers;

use App\Parsing\Contracts\CatalogParser;
use App\Parsing\Data\ParsedProduct;
use App\Parsing\Exceptions\ProductParseException;
use DiDom\Document;
use DiDom\Element;

class MagnitCatalogParser implements CatalogParser
{
    public function supports(string $url): bool
    {
        $host = (string) parse_url($url, PHP_URL_HOST);
        $path = (string) parse_url($url, PHP_URL_PATH);

        return str_contains($host, "magnit.ru") && str_contains($path, "/catalog/");
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

    public function parseCatalog(string $html, string $url): array
    {
        $document = new Document($html);
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

            $text = $this->normalizeText($link->text());
            if ($text === null) {
                continue;
            }

            $price = $this->extractPriceFromText($text);
            $name = $this->extractNameFromText($text);
            $internalProductId = $this->extractProductIdFromUrl($productUrl);

            if ($price === null || $name === null) {
                continue;
            }

            $products[] = new ParsedProduct(
                name: $name,
                price: $price,
                url: $productUrl,
                internalProductId: $internalProductId,
                isInStock: true,
            );

            $seenUrls[$productUrl] = true;
        }

        if ($products === []) {
            throw new ProductParseException(
                "Не удалось извлечь товары из страницы каталога.",
            );
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

    private function extractProductIdFromUrl(string $url): ?string
    {
        if (preg_match("~/(\\d+)(?:[/?-].*)?$~", $url, $matches) === 1) {
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

    private function extractPriceFromText(string $text): ?float
    {
        if (!preg_match('/(\d+(?:[.,]\d+)?)\s*₽/u', $text, $matches)) {
            return null;
        }

        return round((float) str_replace(",", ".", $matches[1]), 2);
    }

    private function extractNameFromText(string $text): ?string
    {
        $parts = preg_split('/\d+(?:[.,]\d+)?\s*₽/u', $text, 2);
        if ($parts === false || count($parts) < 2) {
            return null;
        }

        $tail = trim($parts[1]);
        $tail = preg_replace('/-\d+%\s*/u', "", $tail) ?? $tail;
        $tail = preg_replace('/^\d+(?:[.,]\d+)?\s*₽\s*/u', "", $tail) ?? $tail;
        $tail = preg_replace('/\d+(?:[.,]\d+)?\s*[·.]?\s*\d+\s*отзыв\w*.*$/u', "", $tail) ?? $tail;
        $tail = preg_replace('/Нет отзывов.*$/u', "", $tail) ?? $tail;
        $tail = preg_replace('/^(По карте|Товар недели|Новинка|Акция|2 \+ 1 по карте)\s*/u', "", $tail) ?? $tail;
        $tail = trim($tail);

        return $tail !== "" ? $tail : null;
    }
}
