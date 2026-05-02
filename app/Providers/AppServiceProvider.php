<?php

namespace App\Providers;

use App\Parsing\CatalogParserRegistry;
use App\Parsing\ProductParserRegistry;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->singleton(ProductParserRegistry::class, function ($app) {
            $parsers = array_map(
                static fn (string $parserClass) => $app->make($parserClass),
                config("parsers.product_parsers", []),
            );

            return new ProductParserRegistry($parsers);
        });

        $this->app->singleton(CatalogParserRegistry::class, function ($app) {
            $parsers = array_map(
                static fn (string $parserClass) => $app->make($parserClass),
                config("parsers.catalog_parsers", []),
            );

            return new CatalogParserRegistry($parsers);
        });
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        //
    }
}
