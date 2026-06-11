<?php

namespace Tests\Feature;

use App\Models\SagaInstance;
use App\Support\OrderSagaManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class OrderSagaManagerTest extends TestCase
{
    use RefreshDatabase;

    public function test_order_saga_runs_purchase_flow_once(): void
    {
        Http::fake([
            '*/api/internal/orders/1/reserve-stock' => Http::response(['order' => $this->orderPayload('stock_reserved')]),
            '*/api/internal/wallet/charge' => Http::response(['operation' => ['status' => 'completed']]),
            '*/api/internal/orders/1/payment-reserved' => Http::response(['order' => $this->orderPayload('payment_reserved')]),
            '*/api/internal/orders/1/complete' => Http::response(['order' => $this->orderPayload('completed')]),
            '*/api/internal/reports/generate' => Http::response(['report' => ['status' => 'generated']]),
        ]);

        $payload = $this->orderPayload('pending');
        app(OrderSagaManager::class)->start($payload);
        app(OrderSagaManager::class)->start($payload);

        $this->assertDatabaseHas('saga_instances', ['order_id' => 1, 'status' => 'completed']);
        $this->assertDatabaseCount('saga_steps', 4);
        $this->assertSame('completed', SagaInstance::query()->where('order_id', 1)->first()->status);
    }

    private function orderPayload(string $status): array
    {
        return [
            'id' => 1,
            'order_id' => 1,
            'user_id' => 1,
            'status' => $status,
            'total_amount' => '100.00',
            'currency' => 'USD',
            'items' => [
                ['product_id' => 1, 'name' => 'Headphones', 'quantity' => 1, 'unit_price' => '100.00', 'total_price' => '100.00'],
            ],
        ];
    }
}
