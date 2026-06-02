<?php

namespace App\Console\Commands;

use App\Parsing\CatalogParserRegistry;
use App\Parsing\Exceptions\ProductParseException;
use App\Parsing\ProductPageFetcher;
use App\Services\ProductImportService;
use Illuminate\Console\Command;

class ParseCatalog extends Command
{
    protected $signature = "parse:catalog {url} {--debug-pyaterochka}";

    protected $description = "Парсит каталог товаров по указанному URL и сохраняет цены";

    public function handle(
        CatalogParserRegistry $parserRegistry,
        ProductPageFetcher $fetcher,
        ProductImportService $productImportService,
    ): int
    {
        $url = $this->argument("url");
        $debugPyaterochka = (bool) $this->option("debug-pyaterochka");

        if ($debugPyaterochka) {
            config(["services.selenium.pyaterochka_debug" => true]);
        }

        try {
            $parser = $parserRegistry->forUrl($url);
            $shop = $productImportService->resolveShop($parser);

            $this->info("Магазин: {$shop->name}");
            $this->info("Загружаем каталог...");

            $payload = $fetcher->fetch($parser, $parser->catalogRequestUrl($url));
            $this->outputPyaterochkaDiagnostics($fetcher, $debugPyaterochka);
            $parsedProducts = $parser->parseCatalog($payload, $url);
        } catch (ProductParseException $exception) {
            $this->outputPyaterochkaDiagnostics($fetcher, $debugPyaterochka);
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (\Throwable $exception) {
            $this->outputPyaterochkaDiagnostics($fetcher, $debugPyaterochka);
            $this->error("Неожиданная ошибка: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $createdProducts = 0;
        $updatedHistory = 0;

        foreach ($parsedProducts as $parsedProduct) {
            $result = $productImportService->persist($shop, $parsedProduct, $url);
            $createdProducts += $result["created"] ? 1 : 0;
            $updatedHistory += $result["history_written"] ? 1 : 0;
        }

        $this->info("Найдено товаров: " . count($parsedProducts));
        $this->info("Создано товаров: {$createdProducts}");
        $this->info("Обновлено записей истории: {$updatedHistory}");

        return self::SUCCESS;
    }

    private function outputPyaterochkaDiagnostics(
        ProductPageFetcher $fetcher,
        bool $forceOutput,
    ): void {
        $diagnostics = $fetcher->lastDiagnostics();

        if ($diagnostics === []) {
            return;
        }

        if (!$forceOutput && !config("services.selenium.pyaterochka_debug", false)) {
            return;
        }

        $this->line("Открыта страница: " . (string) ($diagnostics["page_url"] ?? "-"));
        $this->line("Current URL: " . (string) ($diagnostics["current_url"] ?? "-"));
        $this->line("Title: " . (string) ($diagnostics["document_title"] ?? "-"));
        $this->line("User-Agent: " . (string) ($diagnostics["navigator_user_agent"] ?? "-"));
        $this->line("navigator.webdriver: " . ($diagnostics["navigator_webdriver"] ? "true" : "false"));
        $this->line("Chrome mode: " . (string) ($diagnostics["chrome_mode"] ?? "-"));
        $this->line("raw getenv('SELENIUM_HEADLESS'): " . var_export($diagnostics["raw_getenv_headless"] ?? null, true));
        $this->line("env('SELENIUM_HEADLESS'): " . var_export($diagnostics["env_headless"] ?? null, true));
        $this->line("config('services.selenium.headless'): " . var_export($diagnostics["config_headless"] ?? null, true));
        $chromeArgs = $diagnostics["chrome_args"] ?? [];
        if (is_array($chromeArgs)) {
            $this->line("Chrome args: " . implode(" ", $chromeArgs));
        }
        $this->line("Page status: " . (string) ($diagnostics["page_status"] ?? "-"));
        $this->line("Ответ похож на: " . (string) ($diagnostics["response_kind"] ?? "-"));

        if (($diagnostics["matched_api_url"] ?? null) !== null) {
            $this->line(
                "Найден API response: "
                . (string) $diagnostics["matched_api_url"]
                . " [status "
                . (string) ($diagnostics["matched_api_status"] ?? "-")
                . "]"
            );
            $this->line("Content-Type: " . (string) ($diagnostics["matched_api_content_type"] ?? "-"));
            $this->line("Ответ похож на: " . (string) ($diagnostics["response_kind"] ?? "-"));
            $preview = (string) ($diagnostics["matched_api_body_preview"] ?? "");
            if ($preview !== "") {
                $this->line("Body preview: " . $preview);
            }
        }

        $networkResponses = $diagnostics["network_responses"] ?? [];
        if (is_array($networkResponses) && $networkResponses !== []) {
            foreach ($networkResponses as $response) {
                if (!is_array($response)) {
                    continue;
                }

                $this->line(
                    "Network: "
                    . (string) ($response["url"] ?? "-")
                    . " ["
                    . (string) ($response["status"] ?? "-")
                    . "] "
                    . (string) ($response["contentType"] ?? "")
                );
            }
        }

        $debugFiles = $diagnostics["debug_files"] ?? [];
        if (is_array($debugFiles) && $debugFiles !== []) {
            foreach ($debugFiles as $label => $path) {
                $this->line("Debug file {$label}: {$path}");
            }
        }
    }
}
