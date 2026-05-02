<?php

namespace App\Parsing;

use App\Parsing\Contracts\ProductParser;
use App\Parsing\Exceptions\ProductParseException;

class ProductParserRegistry
{
    /**
     * @param  array<int, ProductParser>  $parsers
     */
    public function __construct(private readonly array $parsers)
    {
    }

    public function forUrl(string $url): ProductParser
    {
        foreach ($this->parsers as $parser) {
            if ($parser->supports($url)) {
                return $parser;
            }
        }

        throw new ProductParseException(
            "Для этого URL пока не зарегистрирован парсер.",
        );
    }
}
