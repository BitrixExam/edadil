<?php

namespace App\Parsing\Contracts;

use App\Parsing\Data\ParsedProduct;

interface ProductParser extends ShopParser
{
    public function parse(string $html, string $url): ParsedProduct;
}
