<?php

use App\Http\Controllers\Api\SagaController;
use App\Http\Middleware\VerifyJwtToken;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => response()->json(['service' => 'saga-orchestrator', 'ok' => true]));

Route::middleware(VerifyJwtToken::class)
    ->post('/internal/sagas/orders/{orderId}/cancel', [SagaController::class, 'cancel']);
