<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Wallet;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Redis;
use Tests\TestCase;

class WalletTest extends TestCase
{
    use RefreshDatabase;

    public function test_charge_is_idempotent_and_locks_money_in_transaction(): void
    {
        Redis::shouldReceive('connection')->twice()->andReturnSelf();
        Redis::shouldReceive('command')->twice();

        $user = User::factory()->create();
        Wallet::query()->create(['user_id' => $user->id, 'balance' => '100.00', 'currency' => 'USD']);

        $payload = [
            'user_id' => $user->id,
            'order_id' => 10,
            'amount' => '25.00',
            'currency' => 'USD',
            'operation_id' => 'charge:order:10',
        ];

        $this->postJson('/api/internal/wallet/charge', $payload)->assertOk();
        $this->postJson('/api/internal/wallet/charge', $payload)->assertOk()->assertJsonPath('idempotent', true);

        $this->assertSame('75.00', Wallet::query()->first()->balance);
    }

    public function test_charge_rejects_negative_balance(): void
    {
        Redis::shouldReceive('connection')->never();
        Redis::shouldReceive('command')->never();

        $user = User::factory()->create();
        Wallet::query()->create(['user_id' => $user->id, 'balance' => '10.00', 'currency' => 'USD']);

        $this->postJson('/api/internal/wallet/charge', [
            'user_id' => $user->id,
            'order_id' => 11,
            'amount' => '11.00',
            'currency' => 'USD',
            'operation_id' => 'charge:order:11',
        ])->assertUnprocessable();

        $this->assertSame('10.00', Wallet::query()->first()->balance);
    }
}
