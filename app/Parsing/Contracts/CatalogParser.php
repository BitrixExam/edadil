<?php

namespace App\Parsing\Contracts;

use App\Parsing\Data\ParsedProduct;

interface CatalogParser extends ShopParser
{
    /**
     * @return array<int, ParsedProduct>
     */
    public function parseCatalog(string $html, string $url): array;
}
