<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductPrice;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ParseCatalogCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_products_from_catalog_page(): void
    {
        Http::fake([
            "https://magnit.ru/*" => Http::response(
                file_get_contents(base_path("tests/Fixtures/magnit_catalog.html")),
                200,
            ),
        ]);

        $this->artisan("parse:catalog", [
            "url" => "https://magnit.ru/catalog/112578-produkty_copy_219?shopCode=992301&shopType=6",
        ])->assertSuccessful();

        $this->assertCount(2, Product::all());
        $this->assertCount(2, ProductPrice::all());
        $this->assertDatabaseHas("products", [
            "internal_product_id" => "123456",
            "name" => "Сок Добрый яблоко, 1 л",
        ]);
    }
}
