<?php

namespace App\Parsing;

use App\Parsing\Contracts\ShopParser;
use App\Parsing\Exceptions\ProductParseException;
use Illuminate\Support\Facades\Http;

class ProductPageFetcher
{
    public function fetch(ShopParser $parser, string $url): string
    {
        $response = Http::withHeaders($parser->defaultHeaders())
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
}
