<?php

namespace App\Services;

use App\Models\Product;
use App\Models\Shop;
use App\Parsing\Contracts\ShopParser;
use App\Parsing\Data\ParsedProduct;

class ProductImportService
{
    public function resolveShop(ShopParser $parser): Shop
    {
        return Shop::firstOrCreate(
            ["base_url" => $parser->shopBaseUrl()],
            [
                "name" => $parser->shopName(),
                "parser_config" => [
                    "product_parser" => $parser::class,
                ],
            ],
        );
    }

    /**
     * @return array{product: Product, history_written: bool, created: bool}
     */
    public function persist(Shop $shop, ParsedProduct $parsedProduct, ?string $fallbackUrl = null): array
    {
        $url = $parsedProduct->url ?? $fallbackUrl;

        $lookup = ["shop_id" => $shop->id];
        if ($parsedProduct->internalProductId !== null) {
            $lookup["internal_product_id"] = $parsedProduct->internalProductId;
        } else {
            $lookup["url"] = $url;
        }

        $existingProduct = Product::query()
            ->where($lookup)
            ->first();

        $product = Product::updateOrCreate($lookup, [
            "shop_id" => $shop->id,
            "url" => $url,
            "name" => $parsedProduct->name,
            "internal_product_id" => $parsedProduct->internalProductId,
            "manufacturer_code" => $parsedProduct->manufacturerCode,
        ]);

        $lastPrice = $product->prices()->latest("parsed_at")->first();
        $priceChanged = !$lastPrice
            || abs((float) $lastPrice->price - $parsedProduct->price) > 0.01;
        $stockChanged = !$lastPrice
            || $lastPrice->is_in_stock !== $parsedProduct->isInStock;

        $historyWritten = false;

        if ($priceChanged || $stockChanged) {
            $product->prices()->create([
                "price" => $parsedProduct->price,
                "is_in_stock" => $parsedProduct->isInStock,
                "parsed_at" => now(),
            ]);

            $historyWritten = true;
        }

        return [
            "product" => $product,
            "history_written" => $historyWritten,
            "created" => $existingProduct === null,
        ];
    }
}
