<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class ReportGenerationTest extends TestCase
{
    use RefreshDatabase;

    public function test_report_generation_is_idempotent(): void
    {
        Storage::fake('local');
        Redis::shouldReceive('connection')->once()->andReturnSelf();
        Redis::shouldReceive('command')->once();

        $payload = [
            'order_id' => 1,
            'user_id' => 1,
            'type' => 'receipt',
            'status' => 'completed',
            'total_amount' => '100.00',
            'currency' => 'USD',
            'items' => [
                ['product_id' => 1, 'name' => 'Headphones', 'quantity' => 1, 'unit_price' => '100.00', 'total_price' => '100.00'],
            ],
        ];

        $this->postJson('/api/internal/reports/generate', $payload)->assertOk();
        $this->postJson('/api/internal/reports/generate', $payload)->assertOk();

        $this->assertDatabaseCount('reports', 1);
        Storage::disk('local')->assertExists('reports/order-1-receipt.pdf');
    }
}
