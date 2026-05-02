<?php

namespace Tests\Unit;

use App\Parsing\Parsers\MagnitProductParser;
use PHPUnit\Framework\TestCase;

class MagnitProductParserTest extends TestCase
{
    public function test_it_parses_name_price_and_identifiers_from_fixture(): void
    {
        $html = file_get_contents(__DIR__ . "/../Fixtures/magnit_product.html");
        $parser = new MagnitProductParser();

        $parsedProduct = $parser->parse(
            $html,
            "https://magnit.ru/product/123456/",
        );

        $this->assertSame("Сок Добрый яблоко, 1 л", $parsedProduct->name);
        $this->assertSame(129.99, $parsedProduct->price);
        $this->assertSame("123456", $parsedProduct->internalProductId);
        $this->assertSame("A1B2C3", $parsedProduct->manufacturerCode);
        $this->assertTrue($parsedProduct->isInStock);
    }
}
