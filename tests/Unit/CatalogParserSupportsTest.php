<?php

namespace Tests\Unit;

use App\Parsing\Parsers\PerekrestokCatalogParser;
use App\Parsing\Parsers\PyaterochkaCatalogParser;
use PHPUnit\Framework\TestCase;

class CatalogParserSupportsTest extends TestCase
{
    public function test_pyaterochka_parser_supports_real_catalog_url(): void
    {
        $parser = new PyaterochkaCatalogParser();

        $this->assertTrue(
            $parser->supports("https://5ka.ru/catalog/ryba--251C13077/?page=2"),
        );
    }

    public function test_perekrestok_parser_supports_real_catalog_url(): void
    {
        $parser = new PerekrestokCatalogParser();

        $this->assertTrue(
            $parser->supports("https://www.perekrestok.ru/cat/c/32/salaty"),
        );
    }
}
