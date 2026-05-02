<?php

namespace Tests\Unit;

use App\Parsing\Parsers\PerekrestokCatalogParser;
use PHPUnit\Framework\TestCase;

class PerekrestokCatalogParserTest extends TestCase
{
    public function test_it_parses_products_from_perekrestok_catalog_fixture(): void
    {
        $html = file_get_contents(__DIR__ . "/../Fixtures/perekrestok_catalog.html");
        $parser = new PerekrestokCatalogParser();

        $products = $parser->parseCatalog(
            $html,
            "https://www.perekrestok.ru/cat/114",
        );

        $this->assertCount(2, $products);
        $this->assertSame("Молоко Первый Вкус топлёное 4%, 930мл", $products[0]->name);
        $this->assertSame(119.99, $products[0]->price);
        $this->assertSame("3935531", $products[0]->internalProductId);
    }
}
