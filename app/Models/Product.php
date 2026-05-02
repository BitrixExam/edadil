<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Product extends Model
{
    protected $fillable = [
        "name",
        "url",
        "internal_product_id",
        "manufacturer_code",
        "shop_id",
    ];

    public function shop()
    {
        return $this->belongsTo(Shop::class);
    }

    public function prices()
    {
        return $this->hasMany(ProductPrice::class);
    }

    public function latestPrice()
    {
        return $this->hasOne(ProductPrice::class)->latestOfMany("parsed_at");
    }
}
