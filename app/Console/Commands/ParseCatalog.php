<?php

namespace App\Console\Commands;

use App\Parsing\CatalogParserRegistry;
use App\Parsing\Exceptions\ProductParseException;
use App\Parsing\ProductPageFetcher;
use App\Services\ProductImportService;
use Illuminate\Console\Command;

class ParseCatalog extends Command
{
    protected $signature = "parse:catalog {url}";

    protected $description = "Парсит каталог товаров по указанному URL и сохраняет цены";

    public function handle(
        CatalogParserRegistry $parserRegistry,
        ProductPageFetcher $fetcher,
        ProductImportService $productImportService,
    ): int
    {
        $url = $this->argument("url");

        try {
            $parser = $parserRegistry->forUrl($url);
            $shop = $productImportService->resolveShop($parser);

            $this->info("Магазин: {$shop->name}");
            $this->info("Загружаем каталог...");

            $html = $fetcher->fetch($parser, $url);
            $parsedProducts = $parser->parseCatalog($html, $url);
        } catch (ProductParseException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (\Throwable $exception) {
            $this->error("Неожиданная ошибка: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $createdProducts = 0;
        $updatedHistory = 0;

        foreach ($parsedProducts as $parsedProduct) {
            $result = $productImportService->persist($shop, $parsedProduct);
            $createdProducts += $result["created"] ? 1 : 0;
            $updatedHistory += $result["history_written"] ? 1 : 0;
        }

        $this->info("Найдено товаров: " . count($parsedProducts));
        $this->info("Создано товаров: {$createdProducts}");
        $this->info("Обновлено записей истории: {$updatedHistory}");

        return self::SUCCESS;
    }
}
