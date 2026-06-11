<?php

namespace App\Support;

use App\Models\SagaInstance;
use App\Models\SagaStep;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Redis;

class OrderSagaManager
{
    public function start(array $payload): SagaInstance
    {
        $saga = SagaInstance::query()->firstOrCreate(
            ['order_id' => $payload['order_id']],
            [
                'status' => 'running',
                'current_step' => 'order_created',
                'payload' => $payload,
            ],
        );

        if ($saga->status === 'completed') {
            return $saga;
        }

        try {
            $this->runStep($saga, 'reserve_stock', fn () => $this->orderPost($payload['order_id'], 'reserve-stock'));
            $saga->forceFill(['current_step' => 'stock_reserved'])->save();

            $this->runStep($saga, 'charge_payment', function () use ($payload): void {
                Http::baseUrl(config('services.user.url'))
                    ->post('/api/internal/wallet/charge', [
                        'user_id' => $payload['user_id'],
                        'order_id' => $payload['order_id'],
                        'amount' => $payload['total_amount'],
                        'currency' => $payload['currency'],
                        'operation_id' => 'charge:order:'.$payload['order_id'],
                    ])
                    ->throw();
            });

            $this->orderPost($payload['order_id'], 'payment-reserved');
            $saga->forceFill(['current_step' => 'payment_reserved'])->save();

            $completedOrder = $this->runStep($saga, 'complete_order', fn () => $this->orderPost($payload['order_id'], 'complete'));
            $receiptPayload = $this->orderPayloadFromResponse($completedOrder, $payload, 'receipt');

            $this->runStep($saga, 'generate_receipt', fn () => $this->generateReport($receiptPayload));
            $saga->forceFill(['status' => 'completed', 'current_step' => 'report_generated', 'last_error' => null])->save();
        } catch (\Throwable $exception) {
            if ($saga->steps()->where('step', 'charge_payment')->where('status', 'failed')->exists()) {
                $this->publish('payment.failed', [
                    'order_id' => $payload['order_id'],
                    'user_id' => $payload['user_id'],
                    'amount' => $payload['total_amount'],
                    'currency' => $payload['currency'],
                    'error' => $exception->getMessage(),
                ]);
            }

            $this->compensate($saga, $payload, $exception->getMessage());
        }

        return $saga->refresh();
    }

    public function compensate(SagaInstance $saga, array $payload, string $reason): SagaInstance
    {
        $saga->forceFill([
            'status' => 'compensating',
            'current_step' => 'compensating',
            'last_error' => $reason,
        ])->save();

        $this->safeStep($saga, 'refund_payment', function () use ($payload): void {
            Http::baseUrl(config('services.user.url'))
                ->post('/api/internal/wallet/refund', [
                    'user_id' => $payload['user_id'],
                    'order_id' => $payload['order_id'],
                    'amount' => $payload['total_amount'],
                    'currency' => $payload['currency'],
                    'operation_id' => 'refund:order:'.$payload['order_id'],
                ])
                ->throw();
        });

        $cancelledOrder = $this->safeStep($saga, 'cancel_order', fn () => $this->orderPost($payload['order_id'], 'cancel'));
        $reportPayload = $this->orderPayloadFromResponse($cancelledOrder, $payload, 'cancellation');

        $this->safeStep($saga, 'generate_cancellation_report', fn () => $this->generateReport($reportPayload));

        $saga->forceFill(['status' => 'cancelled', 'current_step' => 'cancelled'])->save();

        return $saga->refresh();
    }

    private function runStep(SagaInstance $saga, string $step, callable $callback): mixed
    {
        $record = DB::transaction(function () use ($saga, $step): ?SagaStep {
            $record = SagaStep::query()->where('saga_instance_id', $saga->id)->where('step', $step)->lockForUpdate()->first();

            if ($record?->status === 'completed') {
                return null;
            }

            $record ??= SagaStep::query()->create([
                'saga_instance_id' => $saga->id,
                'step' => $step,
                'status' => 'running',
                'idempotency_key' => $step.':order:'.$saga->order_id,
            ]);

            return $record;
        });

        if (! $record) {
            return null;
        }

        try {
            $result = $callback();
            $record->forceFill(['status' => 'completed'])->save();

            return $result;
        } catch (\Throwable $exception) {
            $record->forceFill(['status' => 'failed'])->save();

            throw $exception;
        }
    }

    private function safeStep(SagaInstance $saga, string $step, callable $callback): mixed
    {
        try {
            return $this->runStep($saga, $step, $callback);
        } catch (\Throwable) {
            return null;
        }
    }

    private function orderPost(int $orderId, string $action): array
    {
        return Http::baseUrl(config('services.order.url'))
            ->post("/api/internal/orders/{$orderId}/{$action}")
            ->throw()
            ->json();
    }

    private function generateReport(array $payload): void
    {
        Http::baseUrl(config('services.report.url'))
            ->post('/api/internal/reports/generate', $payload)
            ->throw();
    }

    private function publish(string $stream, array $payload): void
    {
        Redis::connection()->command('xadd', [
            $stream,
            '*',
            'event',
            json_encode($payload, JSON_THROW_ON_ERROR),
        ]);
    }

    private function orderPayloadFromResponse(?array $response, array $fallback, string $type): array
    {
        $order = $response['order'] ?? $fallback;

        return [
            'order_id' => $order['id'] ?? $fallback['order_id'],
            'user_id' => $order['user_id'] ?? $fallback['user_id'],
            'type' => $type,
            'status' => $order['status'] ?? $fallback['status'] ?? $type,
            'total_amount' => $order['total_amount'] ?? $fallback['total_amount'],
            'currency' => $order['currency'] ?? $fallback['currency'],
            'items' => $order['items'] ?? $fallback['items'],
        ];
    }
}
