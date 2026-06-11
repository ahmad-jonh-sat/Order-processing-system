<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['wallet_id', 'user_id', 'order_id', 'operation_id', 'type', 'amount', 'currency', 'status', 'payload'])]
class WalletOperation extends Model
{
    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'payload' => 'array',
        ];
    }

    public function wallet(): BelongsTo
    {
        return $this->belongsTo(Wallet::class);
    }
}
