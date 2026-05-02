<?php

namespace Tests\Unit;

use App\Parsing\Parsers\PyaterochkaCatalogParser;
use PHPUnit\Framework\TestCase;

class PyaterochkaCatalogParserTest extends TestCase
{
    public function test_it_parses_products_from_pyaterochka_catalog_fixture(): void
    {
        $html = file_get_contents(__DIR__ . "/../Fixtures/pyaterochka_catalog.html");
        $parser = new PyaterochkaCatalogParser();

        $products = $parser->parseCatalog(
            $html,
            "https://5ka.ru/catalog/moloko-syr-yajtsa--251C11111/",
        );

        $this->assertCount(2, $products);
        $this->assertSame(
            "Молоко Станция Молочная пастеризованное 2.5% БЗМЖ 900мл",
            $products[0]->name,
        );
        $this->assertSame(79.99, $products[0]->price);
        $this->assertSame("4066122", $products[0]->internalProductId);
    }
}
