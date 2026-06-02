<?php

namespace App\Parsing\Contracts;

interface RequestAwareShopParser extends ShopParser
{
    /**
     * @return array<int, string>
     */
    public function bootstrapRequestUrls(string $url): array;

    /**
     * @return array<string, string>
     */
    public function headersForRequest(string $url): array;
}
