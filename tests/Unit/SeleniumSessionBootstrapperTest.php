<?php

namespace Tests\Unit;

use App\Parsing\SeleniumSessionBootstrapper;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class SeleniumSessionBootstrapperTest extends TestCase
{
    public function test_it_collects_cookies_from_selenium_session(): void
    {
        Http::fake([
            "http://selenium:4444/wd/hub/session" => Http::response([
                "value" => [
                    "sessionId" => "session-123",
                ],
            ], 200),
            "http://selenium:4444/wd/hub/session/session-123/url" => Http::response([
                "value" => null,
            ], 200),
            "http://selenium:4444/wd/hub/session/session-123/cookie" => Http::response([
                "value" => [
                    ["name" => "spid", "value" => "cookie-value"],
                    ["name" => "SRV", "value" => "srv-value"],
                ],
            ], 200),
            "http://selenium:4444/wd/hub/session/session-123" => Http::response([], 200),
        ]);

        $bootstrapper = app(SeleniumSessionBootstrapper::class);

        $cookies = $bootstrapper->collectCookies([
            "https://5ka.ru/",
            "https://5ka.ru/catalog/myaso--251C13082/",
        ], "Mozilla/5.0 Test");

        $this->assertSame([
            "spid" => "cookie-value",
            "SRV" => "srv-value",
        ], $cookies);
    }

    public function test_it_captures_network_json_from_browser_session(): void
    {
        config([
            "services.selenium.headless" => false,
            "services.selenium.browser_arguments" => "--lang=ru-RU,--start-maximized",
            "services.selenium.no_sandbox" => false,
        ]);

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
                ->push(["value" => "https://5ka.ru/catalog/myaso--251C13082/"], 200),
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
                            "url" => "https://5d.5ka.ru/api/catalog/v2/stores/35XY/categories/251C13082/products",
                            "status" => 200,
                            "contentType" => "application/json",
                            "body" => '{"products":[]}',
                        ],
                        "responses" => [
                            [
                                "url" => "https://5d.5ka.ru/api/catalog/v2/stores/35XY/categories/251C13082/products",
                                "status" => 200,
                                "contentType" => "application/json",
                                "bodyPreview" => '{"products":[]}',
                            ],
                        ],
                    ],
                ], 200),
            "http://selenium:4444/wd/hub/session/session-123" => Http::response([], 200),
        ]);

        $bootstrapper = app(SeleniumSessionBootstrapper::class);

        $payload = $bootstrapper->captureNetworkJson(
            "https://5ka.ru/catalog/myaso--251C13082/",
            "/api/catalog/",
            5000,
        );

        $this->assertSame('{"products":[]}', $payload);

        $diagnostics = $bootstrapper->lastDiagnostics();
        $this->assertSame("https://5ka.ru/catalog/myaso--251C13082/", $diagnostics["page_url"]);
        $this->assertSame("Каталог", $diagnostics["document_title"]);
        $this->assertSame("https://5d.5ka.ru/api/catalog/v2/stores/35XY/categories/251C13082/products", $diagnostics["matched_api_url"]);
        $this->assertSame("json", $diagnostics["response_kind"]);
        $this->assertFalse($diagnostics["headless"]);
        $this->assertContains("--lang=ru-RU", $diagnostics["chrome_args"]);
        $this->assertContains("--start-maximized", $diagnostics["chrome_args"]);
        $this->assertNotContains("--headless=new", $diagnostics["chrome_args"]);
        $this->assertNotContains("--no-sandbox", $diagnostics["chrome_args"]);

        Http::assertSent(function ($request) {
            if ($request->url() === "http://selenium:4444/wd/hub/session") {
                $data = $request->data();
                $args = $data["capabilities"]["alwaysMatch"]["goog:chromeOptions"]["args"] ?? [];

                return !in_array("--headless=new", $args, true)
                    && !in_array("--no-sandbox", $args, true)
                    && in_array("--lang=ru-RU", $args, true)
                    && in_array("--start-maximized", $args, true);
            }

            if ($request->url() !== "http://selenium:4444/wd/hub/session/session-123/goog/cdp/execute") {
                return true;
            }

            $data = $request->data();
            $source = $data["params"]["source"] ?? "";

            return ($data["cmd"] ?? null) === "Page.addScriptToEvaluateOnNewDocument"
                && is_string($source)
                && str_contains($source, "/api/catalog/");
        });
    }

    public function test_it_throws_page_block_exception_when_block_page_detected(): void
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
                ->push(["value" => "https://5d.5ka.ru/blocked"], 200),
            "http://selenium:4444/wd/hub/session/session-123/title" => Http::response([
                "value" => "Проблемы со связью",
            ], 200),
            "http://selenium:4444/wd/hub/session/session-123/execute/sync" => Http::response([
                "value" => [
                    "userAgent" => "Mozilla/5.0 Test Browser",
                    "webdriver" => true,
                ],
            ], 200),
            "http://selenium:4444/wd/hub/session/session-123/source" => Http::response([
                "value" => "<html><body>Проблемы со связью.<br>Проверьте настройки интернета и VPN</body></html>",
            ], 200),
            "http://selenium:4444/wd/hub/session/session-123" => Http::response([], 200),
        ]);

        $bootstrapper = app(SeleniumSessionBootstrapper::class);

        $this->expectExceptionMessage(
            "Блокируется сама страница 5ka.ru до загрузки каталога.",
        );

        $bootstrapper->captureNetworkJson(
            "https://5ka.ru/catalog/myaso--251C13082/",
            "/api/catalog/",
            500,
        );
    }
}
