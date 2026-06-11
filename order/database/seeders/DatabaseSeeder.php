<?php

namespace Database\Seeders;

use App\Models\Product;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        Product::query()->updateOrCreate(
            ['sku' => 'LAPTOP-001'],
            ['name' => 'Laptop', 'price' => '1000.00', 'stock_quantity' => 5, 'reserved_quantity' => 0],
        );

        Product::query()->updateOrCreate(
            ['sku' => 'PHONE-001'],
            ['name' => 'Phone', 'price' => '500.00', 'stock_quantity' => 10, 'reserved_quantity' => 0],
        );

        Product::query()->updateOrCreate(
            ['sku' => 'HEADPHONES-001'],
            ['name' => 'Headphones', 'price' => '100.00', 'stock_quantity' => 20, 'reserved_quantity' => 0],
        );
    }
}
