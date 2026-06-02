<?php

namespace App\Parsing\Contracts;

interface SeleniumBootstrapAwareParser extends ShopParser
{
    /**
     * @return array<int, string>
     */
    public function seleniumBootstrapUrls(string $url): array;
}
