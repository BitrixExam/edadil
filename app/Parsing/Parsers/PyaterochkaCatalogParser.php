<?php

namespace App\Parsing\Parsers;

use App\Parsing\Contracts\CatalogParser;
use App\Parsing\Contracts\NetworkJsonCaptureAwareParser;
use App\Parsing\Data\ParsedProduct;
use App\Parsing\Exceptions\ProductParseException;

class PyaterochkaCatalogParser implements CatalogParser, NetworkJsonCaptureAwareParser
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
            "User-Agent" => (string) config(
                "parsers.http.user_agent",
                "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36",
            ),
            "Accept-Language" => "ru-RU,ru;q=0.9,en-US;q=0.8,en;q=0.7",
            "Referer" => $this->shopBaseUrl() . "/",
            "Origin" => $this->shopBaseUrl(),
        ];
    }

    public function catalogRequestUrl(string $url): string
    {
        return $url;
    }

    public function networkJsonUrlContains(string $url): string
    {
        return "/api/catalog/";
    }

    public function networkJsonTimeoutMs(): int
    {
        return (int) env("PYATEROCHKA_NETWORK_TIMEOUT_MS", 30000);
    }

    public function parseCatalog(string $payload, string $url): array
    {
        $decoded = json_decode($payload, true);

        if (json_last_error() !== JSON_ERROR_NONE || !is_array($decoded)) {
            throw new ProductParseException(
                "Каталог Пятёрочки вернул HTML вместо JSON. Возможно, сработала защита магазина.",
            );
        }

        $rawProducts = $decoded["products"] ?? null;

        if (!is_array($rawProducts) || $rawProducts === []) {
            throw new ProductParseException(
                "Каталог Пятёрочки не содержит товаров.",
            );
        }

        $products = [];

        foreach ($rawProducts as $rawProduct) {
            if (!is_array($rawProduct)) {
                continue;
            }

            $name = $this->normalizeText($rawProduct["name"] ?? null);
            $internalProductId = $this->extractInternalProductId($rawProduct);
            $price = $this->extractPrice($rawProduct);

            if ($name === null || $internalProductId === null || $price === null) {
                continue;
            }

            $products[] = new ParsedProduct(
                name: $name,
                price: $price,
                url: null,
                internalProductId: $internalProductId,
                manufacturerCode: null,
                isInStock: (bool) ($rawProduct["is_available"] ?? false),
            );
        }

        if ($products === []) {
            throw new ProductParseException(
                "Не удалось извлечь товары из JSON каталога Пятёрочки.",
            );
        }

        return $products;
    }

    /**
     * @param  array<string, mixed>  $rawProduct
     */
    private function extractInternalProductId(array $rawProduct): ?string
    {
        $plu = $rawProduct["plu"] ?? null;

        if ($plu === null || $plu === "") {
            return null;
        }

        return (string) $plu;
    }

    /**
     * @param  array<string, mixed>  $rawProduct
     */
    private function extractPrice(array $rawProduct): ?float
    {
        $prices = $rawProduct["prices"] ?? null;

        if (!is_array($prices)) {
            return null;
        }

        $candidates = [
            $prices["discount"] ?? null,
            $prices["cpd_promo_price"] ?? null,
            $prices["cpd_promo_price_from_sum_cart"] ?? null,
            $prices["regular"] ?? null,
        ];

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

    private function normalizeText(mixed $value): ?string
    {
        if (!is_string($value) || trim($value) === "") {
            return null;
        }

        $normalized = trim(preg_replace('/\s+/u', " ", $value) ?? "");

        return $normalized !== "" ? $normalized : null;
    }
}
