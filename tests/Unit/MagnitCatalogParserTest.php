<?php

namespace Tests\Unit;

use App\Parsing\Parsers\MagnitCatalogParser;
use PHPUnit\Framework\TestCase;

class MagnitCatalogParserTest extends TestCase
{
    public function test_it_parses_products_from_catalog_fixture(): void
    {
        $html = file_get_contents(__DIR__ . "/../Fixtures/magnit_catalog.html");
        $parser = new MagnitCatalogParser();

        $products = $parser->parseCatalog(
            $html,
            "https://magnit.ru/catalog/112578-produkty_copy_219?shopCode=992301&shopType=6",
        );

        $this->assertCount(2, $products);
        $this->assertSame("Сок Добрый яблоко, 1 л", $products[0]->name);
        $this->assertSame(129.99, $products[0]->price);
        $this->assertSame("123456", $products[0]->internalProductId);
        $this->assertSame(
            "https://magnit.ru/product/123456-sok-dobryj-yabloko-1l?shopCode=992301&shopType=6",
            $products[0]->url,
        );
    }
}
