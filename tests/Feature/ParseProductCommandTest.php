<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ParseProductCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_product_and_price_history(): void
    {
        Http::fake([
            "https://magnit.ru/*" => Http::response(
                file_get_contents(base_path("tests/Fixtures/magnit_product.html")),
                200,
            ),
        ]);

        $this->artisan("parse:product", [
            "url" => "https://magnit.ru/product/123456/",
        ])->assertSuccessful();

        $this->assertDatabaseHas("shops", [
            "name" => "Магнит",
            "base_url" => "https://magnit.ru",
        ]);

        $shop = Shop::query()->firstOrFail();
        $product = Product::query()->firstOrFail();

        $this->assertSame($shop->id, $product->shop_id);
        $this->assertSame("Сок Добрый яблоко, 1 л", $product->name);
        $this->assertSame("123456", $product->internal_product_id);
        $this->assertSame("A1B2C3", $product->manufacturer_code);
        $this->assertCount(1, ProductPrice::all());
    }

    public function test_it_does_not_duplicate_history_when_price_is_unchanged(): void
    {
        Http::fake([
            "https://magnit.ru/*" => Http::response(
                file_get_contents(base_path("tests/Fixtures/magnit_product.html")),
                200,
            ),
        ]);

        $this->artisan("parse:product", [
            "url" => "https://magnit.ru/product/123456/",
        ])->assertSuccessful();

        $this->artisan("parse:product", [
            "url" => "https://magnit.ru/product/123456/",
        ])->assertSuccessful();

        $this->assertCount(1, Product::all());
        $this->assertCount(1, ProductPrice::all());
    }
}
