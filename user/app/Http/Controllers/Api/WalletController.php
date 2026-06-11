<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletOperation;
use App\Support\RedisStreamPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class WalletController extends Controller
{
    public function __construct(private readonly RedisStreamPublisher $events) {}

    public function show(Request $request): JsonResponse
    {
        $userId = $this->userId($request);
        $wallet = $this->walletForUser($userId);

        return response()->json(['wallet' => $wallet]);
    }

    public function topUp(Request $request): JsonResponse
    {
        $data = $request->validate([
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['nullable', 'string', 'size:3'],
            'operation_id' => ['nullable', 'string', 'max:120'],
        ]);

        $result = $this->applyOperation(
            userId: $this->userId($request),
            orderId: null,
            operationId: $data['operation_id'] ?? 'top-up:'.(string) str()->uuid(),
            type: 'top_up',
            amount: $this->money($data['amount']),
            currency: strtoupper($data['currency'] ?? 'USD'),
        );

        return response()->json($result);
    }

    public function charge(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'order_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['required', 'string', 'size:3'],
            'operation_id' => ['required', 'string', 'max:120'],
        ]);

        $result = $this->applyOperation(
            userId: (int) $data['user_id'],
            orderId: (int) $data['order_id'],
            operationId: $data['operation_id'],
            type: 'charge',
            amount: $this->money($data['amount']),
            currency: strtoupper($data['currency']),
        );

        $this->events->publish('payment.reserved', [
            'order_id' => (int) $data['order_id'],
            'user_id' => (int) $data['user_id'],
            'amount' => $this->money($data['amount']),
            'currency' => strtoupper($data['currency']),
            'operation_id' => $data['operation_id'],
            'idempotent' => $result['idempotent'],
        ]);

        return response()->json($result);
    }

    public function refund(Request $request): JsonResponse
    {
        $data = $request->validate([
            'user_id' => ['required', 'integer', 'exists:users,id'],
            'order_id' => ['required', 'integer'],
            'amount' => ['required', 'numeric', 'gt:0'],
            'currency' => ['required', 'string', 'size:3'],
            'operation_id' => ['required', 'string', 'max:120'],
        ]);

        $result = $this->applyOperation(
            userId: (int) $data['user_id'],
            orderId: (int) $data['order_id'],
            operationId: $data['operation_id'],
            type: 'refund',
            amount: $this->money($data['amount']),
            currency: strtoupper($data['currency']),
        );

        return response()->json($result);
    }

    private function applyOperation(
        int $userId,
        ?int $orderId,
        string $operationId,
        string $type,
        string $amount,
        string $currency,
    ): array {
        return DB::transaction(function () use ($userId, $orderId, $operationId, $type, $amount, $currency): array {
            $existing = WalletOperation::query()->where('operation_id', $operationId)->first();

            if ($existing) {
                return [
                    'idempotent' => true,
                    'operation' => $existing,
                    'wallet' => Wallet::query()->findOrFail($existing->wallet_id),
                ];
            }

            $this->walletForUser($userId);
            $wallet = Wallet::query()->where('user_id', $userId)->lockForUpdate()->firstOrFail();

            if ($wallet->currency !== $currency) {
                throw ValidationException::withMessages(['currency' => 'Wallet currency mismatch.']);
            }

            $balance = $wallet->balance;
            $nextBalance = match ($type) {
                'top_up', 'refund' => bcadd($balance, $amount, 2),
                'charge' => bcsub($balance, $amount, 2),
                default => throw ValidationException::withMessages(['type' => 'Unsupported wallet operation.']),
            };

            if (bccomp($nextBalance, '0.00', 2) < 0) {
                throw ValidationException::withMessages(['amount' => 'Insufficient funds.']);
            }

            $wallet->forceFill(['balance' => $nextBalance])->save();

            $operation = WalletOperation::query()->create([
                'wallet_id' => $wallet->id,
                'user_id' => $userId,
                'order_id' => $orderId,
                'operation_id' => $operationId,
                'type' => $type,
                'amount' => $amount,
                'currency' => $currency,
                'status' => 'completed',
                'payload' => ['balance_after' => $nextBalance],
            ]);

            return [
                'idempotent' => false,
                'operation' => $operation,
                'wallet' => $wallet->refresh(),
            ];
        });
    }

    private function walletForUser(int $userId): Wallet
    {
        User::query()->findOrFail($userId);

        return Wallet::query()->firstOrCreate(
            ['user_id' => $userId],
            ['balance' => '0.00', 'currency' => 'USD'],
        );
    }

    private function userId(Request $request): int
    {
        return (int) data_get($request->attributes->get('jwt_payload'), 'user.id');
    }

    private function money(mixed $value): string
    {
        return bcadd((string) $value, '0', 2);
    }
}
