<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['user_id', 'status', 'total_amount', 'currency', 'idempotency_key'])]
class Order extends Model
{
    public const STATUS_PENDING = 'pending';
    public const STATUS_STOCK_RESERVED = 'stock_reserved';
    public const STATUS_PAYMENT_RESERVED = 'payment_reserved';
    public const STATUS_COMPLETED = 'completed';
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_FAILED = 'failed';

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function reservations(): HasMany
    {
        return $this->hasMany(StockReservation::class);
    }
}
