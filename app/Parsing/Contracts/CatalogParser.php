<?php

namespace App\Parsing\Contracts;

use App\Parsing\Data\ParsedProduct;

interface CatalogParser extends ShopParser
{
    public function catalogRequestUrl(string $url): string;

    /**
     * @return array<int, ParsedProduct>
     */
    public function parseCatalog(string $payload, string $url): array;
}
