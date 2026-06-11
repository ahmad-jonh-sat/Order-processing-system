<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

#[Fillable(['saga_instance_id', 'step', 'status', 'idempotency_key'])]
class SagaStep extends Model
{
    public function saga(): BelongsTo
    {
        return $this->belongsTo(SagaInstance::class, 'saga_instance_id');
    }
}
