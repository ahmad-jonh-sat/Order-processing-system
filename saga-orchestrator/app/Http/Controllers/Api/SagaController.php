<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SagaInstance;
use App\Support\OrderSagaManager;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;

class SagaController extends Controller
{
    public function cancel(Request $request, int $orderId, OrderSagaManager $manager): JsonResponse
    {
        $order = Http::baseUrl(config('services.order.url'))
            ->get("/api/internal/orders/{$orderId}")
            ->throw()
            ->json('order');

        abort_if((int) $order['user_id'] !== (int) data_get($request->attributes->get('jwt_payload'), 'user.id'), 404);

        $payload = [
            'order_id' => $order['id'],
            'user_id' => $order['user_id'],
            'status' => $order['status'],
            'total_amount' => $order['total_amount'],
            'currency' => $order['currency'],
            'items' => $order['items'],
        ];

        $saga = SagaInstance::query()->firstOrCreate(
            ['order_id' => $orderId],
            ['status' => 'running', 'current_step' => 'manual_cancel', 'payload' => $payload],
        );

        return response()->json(['saga' => $manager->compensate($saga, $payload, 'cancel requested')]);
    }
}
