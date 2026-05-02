<?php

namespace App\Parsing\Contracts;

interface ShopParser
{
    public function supports(string $url): bool;

    public function shopName(): string;

    public function shopBaseUrl(): string;

    /**
     * @return array<string, string>
     */
    public function defaultHeaders(): array;
}
