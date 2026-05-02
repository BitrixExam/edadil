<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Shop extends Model
{
    protected $fillable = ["name", "base_url", "parser_config"];

    protected $casts = [
        "parser_config" => "array",
    ];

    public function products()
    {
        return $this->hasMany(Product::class);
    }
}
