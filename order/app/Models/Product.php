<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['sku', 'name', 'price', 'stock_quantity', 'reserved_quantity'])]
class Product extends Model
{
    protected function casts(): array
    {
        return [
            'price' => 'decimal:2',
            'stock_quantity' => 'integer',
            'reserved_quantity' => 'integer',
        ];
    }

    public function availableStock(): int
    {
        return $this->stock_quantity - $this->reserved_quantity;
    }
}
