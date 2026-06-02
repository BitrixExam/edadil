<?php

namespace App\Parsing;

use App\Parsing\Contracts\NetworkJsonCaptureAwareParser;
use App\Parsing\Contracts\RequestAwareShopParser;
use App\Parsing\Contracts\SeleniumBootstrapAwareParser;
use App\Parsing\Contracts\ShopParser;
use App\Parsing\Exceptions\ProductParseException;
use GuzzleHttp\Cookie\CookieJar;
use Illuminate\Support\Facades\Http;

class ProductPageFetcher
{
    public function __construct(
        private readonly SeleniumSessionBootstrapper $seleniumSessionBootstrapper,
    ) {
    }

    public function fetch(ShopParser $parser, string $url): string
    {
        if ($parser instanceof NetworkJsonCaptureAwareParser) {
            return $this->seleniumSessionBootstrapper->captureNetworkJson(
                $url,
                $parser->networkJsonUrlContains($url),
                $parser->networkJsonTimeoutMs(),
            );
        }

        $cookieJar = new CookieJar();
        $seleniumCookies = $this->seleniumBootstrapCookies($parser, $url);

        if ($seleniumCookies !== []) {
            $cookieJar = CookieJar::fromArray(
                $seleniumCookies,
                parse_url($parser->shopBaseUrl(), PHP_URL_HOST) ?: "",
            );
        }

        foreach ($this->bootstrapRequestUrls($parser, $url) as $bootstrapUrl) {
            $bootstrapResponse = Http::withOptions(["cookies" => $cookieJar])
                ->withHeaders($this->headersForRequest($parser, $bootstrapUrl))
                ->timeout((int) config("parsers.http.timeout", 15))
                ->retry(
                    (int) config("parsers.http.retries", 2),
                    (int) config("parsers.http.retry_sleep_ms", 500),
                )
                ->get($bootstrapUrl);

            if (!$bootstrapResponse->successful()) {
                throw new ProductParseException(
                    "Не удалось подготовить сессию магазина: HTTP {$bootstrapResponse->status()}",
                );
            }
        }

        $response = Http::withOptions(["cookies" => $cookieJar])
            ->withHeaders($this->headersForRequest($parser, $url))
            ->timeout((int) config("parsers.http.timeout", 15))
            ->retry(
                (int) config("parsers.http.retries", 2),
                (int) config("parsers.http.retry_sleep_ms", 500),
            )
            ->get($url);

        if (!$response->successful()) {
            throw new ProductParseException(
                "Не удалось загрузить страницу: HTTP {$response->status()}",
            );
        }

        return $response->body();
    }

    /**
     * @return array<string, mixed>
     */
    public function lastDiagnostics(): array
    {
        return $this->seleniumSessionBootstrapper->lastDiagnostics();
    }

    /**
     * @return array<int, string>
     */
    private function bootstrapRequestUrls(ShopParser $parser, string $url): array
    {
        if ($parser instanceof RequestAwareShopParser) {
            return $parser->bootstrapRequestUrls($url);
        }

        return [];
    }

    /**
     * @return array<string, string>
     */
    private function headersForRequest(ShopParser $parser, string $url): array
    {
        if ($parser instanceof RequestAwareShopParser) {
            return $parser->headersForRequest($url);
        }

        return $parser->defaultHeaders();
    }

    /**
     * @return array<string, string>
     */
    private function seleniumBootstrapCookies(ShopParser $parser, string $url): array
    {
        if (!$parser instanceof SeleniumBootstrapAwareParser) {
            return [];
        }

        return $this->seleniumSessionBootstrapper->collectCookies(
            $parser->seleniumBootstrapUrls($url),
            $parser->defaultHeaders()["User-Agent"] ?? "",
        );
    }
}
