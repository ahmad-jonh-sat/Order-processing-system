<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

#[Fillable(['order_id', 'status', 'current_step', 'payload', 'last_error'])]
class SagaInstance extends Model
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }

    public function steps(): HasMany
    {
        return $this->hasMany(SagaStep::class);
    }
}
