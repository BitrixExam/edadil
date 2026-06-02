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
            "http://selenium:4444/wd/hub/session" => Http::response([
                "value" => [
                    "sessionId" => "session-123",
                ],
            ], 200),
            "http://selenium:4444/wd/hub/session/session-123/goog/cdp/execute" => Http::response([
                "value" => [
                    "identifier" => "script-1",
                ],
            ], 200),
            "http://selenium:4444/wd/hub/session/session-123/url" => Http::sequence()
                ->push(["value" => null], 200)
                ->push(["value" => "https://5ka.ru/catalog/ryba--251C13077/?page=2"], 200),
            "http://selenium:4444/wd/hub/session/session-123/title" => Http::response([
                "value" => "Каталог",
            ], 200),
            "http://selenium:4444/wd/hub/session/session-123/source" => Http::response([
                "value" => "<html><body>catalog page</body></html>",
            ], 200),
            "http://selenium:4444/wd/hub/session/session-123/execute/sync" => Http::sequence()
                ->push([
                    "value" => [
                        "userAgent" => "Mozilla/5.0 Test Browser",
                        "webdriver" => false,
                    ],
                ], 200)
                ->push([
                    "value" => [
                        "matched" => [
                            "url" => "https://5d.5ka.ru/api/catalog/v2/stores/35XY/categories/251C13077/products?mode=delivery&include_restrict=true&limit=12&offset=12",
                            "status" => 200,
                            "contentType" => "application/json",
                            "body" => file_get_contents(base_path("tests/Fixtures/pyaterochka_catalog.json")),
                        ],
                        "responses" => [
                            [
                                "url" => "https://5d.5ka.ru/api/catalog/v2/stores/35XY/categories/251C13077/products?mode=delivery&include_restrict=true&limit=12&offset=12",
                                "status" => 200,
                                "contentType" => "application/json",
                                "bodyPreview" => '{"parent_id":"251C12889"',
                            ],
                        ],
                    ],
                ], 200),
            "http://selenium:4444/wd/hub/session/session-123" => Http::response([], 200),
        ]);

        $this->artisan("parse:catalog", [
            "url" => "https://5ka.ru/catalog/ryba--251C13077/?page=2",
        ])->assertSuccessful();

        $this->assertDatabaseHas("shops", [
            "name" => "Пятёрочка",
            "base_url" => "https://5ka.ru",
        ]);
        $this->assertCount(2, Product::all());
        $this->assertCount(2, ProductPrice::all());
    }

    public function test_it_outputs_pyaterochka_debug_information(): void
    {
        Http::fake([
            "http://selenium:4444/wd/hub/session" => Http::response([
                "value" => [
                    "sessionId" => "session-123",
                ],
            ], 200),
            "http://selenium:4444/wd/hub/session/session-123/goog/cdp/execute" => Http::response([
                "value" => [
                    "identifier" => "script-1",
                ],
            ], 200),
            "http://selenium:4444/wd/hub/session/session-123/url" => Http::sequence()
                ->push(["value" => null], 200)
                ->push(["value" => "https://5ka.ru/catalog/ryba--251C13077/?page=2"], 200),
            "http://selenium:4444/wd/hub/session/session-123/title" => Http::response([
                "value" => "Каталог",
            ], 200),
            "http://selenium:4444/wd/hub/session/session-123/source" => Http::response([
                "value" => "<html><body>catalog page</body></html>",
            ], 200),
            "http://selenium:4444/wd/hub/session/session-123/execute/sync" => Http::sequence()
                ->push([
                    "value" => [
                        "userAgent" => "Mozilla/5.0 Test Browser",
                        "webdriver" => false,
                    ],
                ], 200)
                ->push([
                    "value" => [
                        "matched" => [
                            "url" => "https://5d.5ka.ru/api/catalog/v2/stores/35XY/categories/251C13077/products?mode=delivery&include_restrict=true&limit=12&offset=12",
                            "status" => 200,
                            "contentType" => "application/json",
                            "body" => file_get_contents(base_path("tests/Fixtures/pyaterochka_catalog.json")),
                        ],
                        "responses" => [
                            [
                                "url" => "https://5d.5ka.ru/api/catalog/v2/stores/35XY/categories/251C13077/products?mode=delivery&include_restrict=true&limit=12&offset=12",
                                "status" => 200,
                                "contentType" => "application/json",
                                "bodyPreview" => '{"parent_id":"251C12889"',
                            ],
                        ],
                    ],
                ], 200),
            "http://selenium:4444/wd/hub/session/session-123/screenshot" => Http::response([
                "value" => base64_encode("fake-image"),
            ], 200),
            "http://selenium:4444/wd/hub/session/session-123" => Http::response([], 200),
        ]);

        $this->artisan("parse:catalog", [
            "url" => "https://5ka.ru/catalog/ryba--251C13077/?page=2",
            "--debug-pyaterochka" => true,
        ])
            ->expectsOutput("Открыта страница: https://5ka.ru/catalog/ryba--251C13077/?page=2")
            ->expectsOutput("Title: Каталог")
            ->expectsOutput("User-Agent: Mozilla/5.0 Test Browser")
            ->assertSuccessful();
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
