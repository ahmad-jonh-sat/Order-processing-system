<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Model;

#[Fillable(['order_id', 'user_id', 'type', 'status', 'file_path', 'payload'])]
class Report extends Model
{
    protected function casts(): array
    {
        return [
            'payload' => 'array',
        ];
    }
}
