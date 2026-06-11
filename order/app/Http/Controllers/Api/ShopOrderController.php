<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Models\OrderItem;
use App\Models\Product;
use App\Models\StockReservation;
use App\Support\RedisStreamPublisher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ShopOrderController extends Controller
{
    public function __construct(private readonly RedisStreamPublisher $events) {}

    public function indexProducts(): JsonResponse
    {
        return response()->json(['products' => Product::query()->orderBy('id')->get()]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'idempotency_key' => ['required', 'string', 'max:120'],
            'items' => ['required', 'array', 'min:1'],
            'items.*.product_id' => ['required', 'integer', 'exists:products,id'],
            'items.*.quantity' => ['required', 'integer', 'min:1'],
        ]);

        $user = data_get($request->attributes->get('jwt_payload'), 'user');
        $created = false;

        $order = DB::transaction(function () use ($data, $user, &$created): Order {
            $existing = Order::query()
                ->with(['items.product', 'reservations'])
                ->where('idempotency_key', $data['idempotency_key'])
                ->first();

            if ($existing) {
                return $existing;
            }

            $order = Order::query()->create([
                'user_id' => (int) $user['id'],
                'status' => Order::STATUS_PENDING,
                'total_amount' => '0.00',
                'currency' => 'USD',
                'idempotency_key' => $data['idempotency_key'],
            ]);

            $total = '0.00';

            foreach ($data['items'] as $item) {
                $product = Product::query()->findOrFail($item['product_id']);
                $quantity = (int) $item['quantity'];
                $lineTotal = bcmul($product->price, (string) $quantity, 2);
                $total = bcadd($total, $lineTotal, 2);

                OrderItem::query()->create([
                    'order_id' => $order->id,
                    'product_id' => $product->id,
                    'quantity' => $quantity,
                    'unit_price' => $product->price,
                    'total_price' => $lineTotal,
                ]);
            }

            if (bccomp($total, '0.00', 2) <= 0) {
                throw ValidationException::withMessages(['items' => 'Order total must be greater than zero.']);
            }

            $order->forceFill(['total_amount' => $total])->save();
            $created = true;

            return $order->load(['items.product', 'reservations']);
        });

        if ($created) {
            $this->events->publish('order.created', $this->orderPayload($order));
        }

        return response()->json(['order' => $order], $created ? 201 : 200);
    }

    public function show(Request $request, int $id): JsonResponse
    {
        $order = Order::query()->with(['items.product', 'reservations'])->findOrFail($id);
        $userId = (int) data_get($request->attributes->get('jwt_payload'), 'user.id');

        abort_if($order->user_id !== $userId, 404);

        return response()->json(['order' => $order]);
    }

    public function internalShow(int $id): JsonResponse
    {
        return response()->json([
            'order' => Order::query()->with(['items.product', 'reservations'])->findOrFail($id),
        ]);
    }

    public function reserveStock(int $id): JsonResponse
    {
        $order = null;

        try {
            $order = DB::transaction(function () use ($id): Order {
                /** @var Order $order */
                $order = Order::query()->with('items')->lockForUpdate()->findOrFail($id);

                if (in_array($order->status, [Order::STATUS_STOCK_RESERVED, Order::STATUS_PAYMENT_RESERVED, Order::STATUS_COMPLETED], true)) {
                    return $order->load(['items.product', 'reservations']);
                }

                if ($order->status !== Order::STATUS_PENDING) {
                    throw ValidationException::withMessages(['order' => 'Order is not reservable.']);
                }

                foreach ($order->items as $item) {
                    $product = Product::query()->lockForUpdate()->findOrFail($item->product_id);
                    $available = $product->stock_quantity - $product->reserved_quantity;

                    if ($item->quantity <= 0 || $available < $item->quantity) {
                        throw ValidationException::withMessages([
                            'stock' => "Insufficient stock for product {$product->id}.",
                        ]);
                    }

                    $product->forceFill([
                        'reserved_quantity' => $product->reserved_quantity + $item->quantity,
                    ])->save();

                    StockReservation::query()->updateOrCreate(
                        ['order_id' => $order->id, 'product_id' => $product->id],
                        ['quantity' => $item->quantity, 'status' => 'reserved'],
                    );
                }

                $order->forceFill(['status' => Order::STATUS_STOCK_RESERVED])->save();

                return $order->load(['items.product', 'reservations']);
            });

            $this->events->publish('stock.reserved', $this->orderPayload($order));

            return response()->json(['order' => $order]);
        } catch (\Throwable $exception) {
            $failedOrder = Order::query()->with(['items.product', 'reservations'])->find($id);

            if ($failedOrder) {
                $failedOrder->forceFill(['status' => Order::STATUS_FAILED])->save();
                $this->events->publish('stock.reservation.failed', $this->orderPayload($failedOrder, $exception->getMessage()));
            }

            throw $exception;
        }
    }

    public function markPaymentReserved(int $id): JsonResponse
    {
        $order = DB::transaction(function () use ($id): Order {
            $order = Order::query()->lockForUpdate()->findOrFail($id);

            if ($order->status === Order::STATUS_STOCK_RESERVED) {
                $order->forceFill(['status' => Order::STATUS_PAYMENT_RESERVED])->save();
            }

            return $order->load(['items.product', 'reservations']);
        });

        return response()->json(['order' => $order]);
    }

    public function complete(int $id): JsonResponse
    {
        $order = DB::transaction(function () use ($id): Order {
            /** @var Order $order */
            $order = Order::query()->with('reservations')->lockForUpdate()->findOrFail($id);

            if ($order->status === Order::STATUS_COMPLETED) {
                return $order->load(['items.product', 'reservations']);
            }

            if (! in_array($order->status, [Order::STATUS_STOCK_RESERVED, Order::STATUS_PAYMENT_RESERVED], true)) {
                throw ValidationException::withMessages(['order' => 'Order is not completable.']);
            }

            foreach ($order->reservations()->where('status', 'reserved')->get() as $reservation) {
                $product = Product::query()->lockForUpdate()->findOrFail($reservation->product_id);
                $product->forceFill([
                    'stock_quantity' => $product->stock_quantity - $reservation->quantity,
                    'reserved_quantity' => $product->reserved_quantity - $reservation->quantity,
                ])->save();

                $reservation->forceFill(['status' => 'committed'])->save();
            }

            $order->forceFill(['status' => Order::STATUS_COMPLETED])->save();

            return $order->load(['items.product', 'reservations']);
        });

        $this->events->publish('order.completed', $this->orderPayload($order));

        return response()->json(['order' => $order]);
    }

    public function cancel(int $id): JsonResponse
    {
        $order = DB::transaction(function () use ($id): Order {
            /** @var Order $order */
            $order = Order::query()->with('reservations')->lockForUpdate()->findOrFail($id);

            if ($order->status === Order::STATUS_CANCELLED) {
                return $order->load(['items.product', 'reservations']);
            }

            foreach ($order->reservations()->whereIn('status', ['reserved', 'committed'])->get() as $reservation) {
                $product = Product::query()->lockForUpdate()->findOrFail($reservation->product_id);

                if ($reservation->status === 'reserved') {
                    $product->forceFill([
                        'reserved_quantity' => max(0, $product->reserved_quantity - $reservation->quantity),
                    ])->save();
                }

                if ($reservation->status === 'committed') {
                    $product->forceFill([
                        'stock_quantity' => $product->stock_quantity + $reservation->quantity,
                    ])->save();
                }

                $reservation->forceFill(['status' => 'released'])->save();
            }

            $order->forceFill(['status' => Order::STATUS_CANCELLED])->save();

            return $order->load(['items.product', 'reservations']);
        });

        $this->events->publish('order.cancelled', $this->orderPayload($order));

        return response()->json(['order' => $order]);
    }

    private function orderPayload(Order $order, ?string $error = null): array
    {
        $order->loadMissing(['items.product', 'reservations']);

        return [
            'order_id' => $order->id,
            'user_id' => $order->user_id,
            'status' => $order->status,
            'total_amount' => $order->total_amount,
            'currency' => $order->currency,
            'items' => $order->items->map(fn ($item): array => [
                'product_id' => $item->product_id,
                'sku' => $item->product?->sku,
                'name' => $item->product?->name,
                'quantity' => $item->quantity,
                'unit_price' => $item->unit_price,
                'total_price' => $item->total_price,
            ])->values()->all(),
            'error' => $error,
        ];
    }
}
