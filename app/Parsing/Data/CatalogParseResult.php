<?php

namespace App\Parsing\Data;

final class CatalogParseResult
{
    /**
     * @param  array<int, ParsedProduct>  $products
     */
    public function __construct(
        public readonly array $products,
    ) {
    }
}
