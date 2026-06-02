<?php

namespace App\Parsing\Contracts;

interface NetworkJsonCaptureAwareParser extends ShopParser
{
    public function networkJsonUrlContains(string $url): string;

    public function networkJsonTimeoutMs(): int;
}
