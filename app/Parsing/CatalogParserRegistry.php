<?php

namespace App\Parsing;

use App\Parsing\Contracts\CatalogParser;
use App\Parsing\Exceptions\ProductParseException;

class CatalogParserRegistry
{
    /**
     * @param  array<int, CatalogParser>  $parsers
     */
    public function __construct(private readonly array $parsers)
    {
    }

    public function forUrl(string $url): CatalogParser
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($url)) {
                return $parser;
            }
        }

        throw new ProductParseException(
            "Для этого URL каталога пока не зарегистрирован парсер.",
        );
    }
}
