<?php

namespace Tests\Unit;

use App\Parsing\Parsers\PyaterochkaCatalogParser;
use PHPUnit\Framework\TestCase;

class PyaterochkaCatalogParserTest extends TestCase
{
    public function test_it_builds_api_url_from_catalog_url(): void
    {
        $parser = new PyaterochkaCatalogParser();

        $requestUrl = $parser->catalogRequestUrl(
            "https://5ka.ru/catalog/ryba--251C13077/?page=2",
        );

        $this->assertSame(
            "https://5ka.ru/catalog/ryba--251C13077/?page=2",
            $requestUrl,
        );
    }

    public function test_it_parses_products_from_pyaterochka_catalog_json_fixture(): void
    {
        $json = file_get_contents(__DIR__ . "/../Fixtures/pyaterochka_catalog.json");
        $parser = new PyaterochkaCatalogParser();

        $products = $parser->parseCatalog(
            $json,
            "https://5ka.ru/catalog/ryba--251C13077/?page=2",
        );

        $this->assertCount(2, $products);
        $this->assertSame(
            "Стейк говяжий Мираторг Black Angus Рибай без кости охлажденный 320г",
            $products[0]->name,
        );
        $this->assertSame(1499.00, $products[0]->price);
        $this->assertSame("3478153", $products[0]->internalProductId);
        $this->assertTrue($products[0]->isInStock);
    }

}
