<?php

namespace Tests\Feature;

use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class StockReservationTest extends TestCase
{
    use RefreshDatabase;

    public function test_stock_reservation_is_idempotent_and_checks_available_stock(): void
    {
        Redis::shouldReceive('connection')->zeroOrMoreTimes()->andReturnSelf();
        Redis::shouldReceive('command')->zeroOrMoreTimes();

        $product = Product::query()->create([
            'sku' => 'PHONE-001',
            'name' => 'Phone',
            'price' => '500.00',
            'stock_quantity' => 10,
            'reserved_quantity' => 0,
        ]);
        $order = Order::query()->create([
            'user_id' => 1,
            'status' => Order::STATUS_PENDING,
            'total_amount' => '1000.00',
            'currency' => 'USD',
            'idempotency_key' => 'order-1',
        ]);
        OrderItem::query()->create([
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'unit_price' => '500.00',
            'total_price' => '1000.00',
        ]);

        $this->postJson("/api/internal/orders/{$order->id}/reserve-stock")->assertOk();
        $this->postJson("/api/internal/orders/{$order->id}/reserve-stock")->assertOk();

        $this->assertSame(2, $product->refresh()->reserved_quantity);
        $this->assertDatabaseHas('stock_reservations', [
            'order_id' => $order->id,
            'product_id' => $product->id,
            'quantity' => 2,
            'status' => 'reserved',
        ]);
    }
}
