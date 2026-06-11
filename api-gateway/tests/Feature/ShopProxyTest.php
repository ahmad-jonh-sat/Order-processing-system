<?php

namespace Tests\Feature;

use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ShopProxyTest extends TestCase
{
    public function test_shop_order_creation_is_proxied_to_order_service(): void
    {
        Http::fake([
            '*' => Http::response(['order' => ['id' => 1]], 201, ['Content-Type' => 'application/json']),
        ]);

        $this->withHeader('Authorization', 'Bearer token')
            ->postJson('/api/shop/orders', [
                'idempotency_key' => 'order-uuid',
                'items' => [['product_id' => 1, 'quantity' => 1]],
            ])
            ->assertCreated()
            ->assertJsonPath('order.id', 1);

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/order/api/shop/orders')
            && $request->hasHeader('Authorization', 'Bearer token'));
    }

    public function test_shop_cancel_is_proxied_to_saga_orchestrator(): void
    {
        Http::fake([
            '*' => Http::response(['saga' => ['status' => 'cancelled']], 200, ['Content-Type' => 'application/json']),
        ]);

        $this->withHeader('Authorization', 'Bearer token')
            ->postJson('/api/shop/orders/1/cancel')
            ->assertOk()
            ->assertJsonPath('saga.status', 'cancelled');

        Http::assertSent(fn ($request): bool => str_ends_with($request->url(), '/saga/api/internal/sagas/orders/1/cancel')
            && $request->hasHeader('Authorization', 'Bearer token'));
    }
}
