<?php

namespace Tests\Feature;

use App\Models\Product;
use App\Models\ProductPrice;
use App\Models\Shop;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ParseCatalogOtherShopsCommandTest extends TestCase
{
    use RefreshDatabase;

    public function test_it_persists_products_from_pyaterochka_catalog(): void
    {
        Http::fake([
            "https://5ka.ru/*" => Http::response(
                file_get_contents(base_path("tests/Fixtures/pyaterochka_catalog.html")),
                200,
            ),
        ]);

        $this->artisan("parse:catalog", [
            "url" => "https://5ka.ru/catalog/moloko-syr-yajtsa--251C11111/",
        ])->assertSuccessful();

        $this->assertDatabaseHas("shops", [
            "name" => "Пятёрочка",
            "base_url" => "https://5ka.ru",
        ]);
        $this->assertCount(2, Product::all());
        $this->assertCount(2, ProductPrice::all());
    }

    public function test_it_persists_products_from_perekrestok_catalog(): void
    {
        Http::fake([
            "https://www.perekrestok.ru/*" => Http::response(
                file_get_contents(base_path("tests/Fixtures/perekrestok_catalog.html")),
                200,
            ),
            "https://promo.perekrestok.ru/*" => Http::response(
                file_get_contents(base_path("tests/Fixtures/perekrestok_catalog.html")),
                200,
            ),
        ]);

        $this->artisan("parse:catalog", [
            "url" => "https://www.perekrestok.ru/cat/114",
        ])->assertSuccessful();

        $this->assertDatabaseHas("shops", [
            "name" => "Перекрёсток",
            "base_url" => "https://www.perekrestok.ru",
        ]);
        $this->assertCount(2, Product::all());
        $this->assertCount(2, ProductPrice::all());

        $shop = Shop::query()->firstOrFail();
        $this->assertSame("Перекрёсток", $shop->name);
    }
}
