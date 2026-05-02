<?php

namespace App\Parsing\Data;

final class ParsedProduct
{
    public function __construct(
        public readonly string $name,
        public readonly float $price,
        public readonly ?string $url = null,
        public readonly ?string $internalProductId = null,
        public readonly ?string $manufacturerCode = null,
        public readonly bool $isInStock = true,
    ) {
    }
}
