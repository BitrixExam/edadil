<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class ProductPrice extends Model
{
    protected $fillable = ["product_id", "price", "is_in_stock", "parsed_at"];

    protected $casts = [
        "price" => "decimal:2",
        "is_in_stock" => "boolean",
        "parsed_at" => "datetime",
    ];

    public function product()
    {
        return $this->belongsTo(Product::class);
    }
}
