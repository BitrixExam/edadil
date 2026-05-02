<?php

use App\Parsing\Parsers\MagnitCatalogParser;
use App\Parsing\Parsers\MagnitProductParser;
use App\Parsing\Parsers\PerekrestokCatalogParser;
use App\Parsing\Parsers\PyaterochkaCatalogParser;

return [
    "product_parsers" => [
        MagnitProductParser::class,
    ],

    "catalog_parsers" => [
        MagnitCatalogParser::class,
        PyaterochkaCatalogParser::class,
        PerekrestokCatalogParser::class,
    ],

    "http" => [
        "timeout" => env("PARSER_TIMEOUT", 15),
        "retries" => env("PARSER_RETRIES", 2),
        "retry_sleep_ms" => env("PARSER_RETRY_SLEEP_MS", 500),
        "user_agent" => env(
            "PARSER_USER_AGENT",
            "Mozilla/5.0 (Windows NT 10.0; Win64; x64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/135.0.0.0 Safari/537.36",
        ),
    ],
];
