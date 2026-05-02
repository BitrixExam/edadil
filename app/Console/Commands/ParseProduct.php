<?php

namespace App\Console\Commands;

use App\Models\Product;
use App\Parsing\Exceptions\ProductParseException;
use App\Parsing\ProductPageFetcher;
use App\Parsing\ProductParserRegistry;
use App\Services\ProductImportService;
use Illuminate\Console\Command;

class ParseProduct extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = "parse:product {url}";

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = "Парсит товар по указанному URL и сохраняет цену";

    /**
     * Execute the console command.
     */
    public function handle(
        ProductParserRegistry $parserRegistry,
        ProductPageFetcher $fetcher,
        ProductImportService $productImportService,
    ): int
    {
        $url = $this->argument("url");

        try {
            $parser = $parserRegistry->forUrl($url);
            $shop = $productImportService->resolveShop($parser);

            $this->info("Магазин: {$shop->name}");
            $this->info("Загружаем страницу...");

            $html = $fetcher->fetch($parser, $url);
            $parsedProduct = $parser->parse($html, $url);
        } catch (ProductParseException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        } catch (\Throwable $exception) {
            $this->error("Неожиданная ошибка: {$exception->getMessage()}");

            return self::FAILURE;
        }

        $this->info(
            "Найдено: {$parsedProduct->name}, цена: {$parsedProduct->price} руб.",
        );

        $result = $productImportService->persist($shop, $parsedProduct, $url);

        if ($result["history_written"]) {
            $this->info("Цена сохранена в истории.");
        } else {
            $this->info("Цена и наличие не изменились.");
        }

        return self::SUCCESS;
    }
}
