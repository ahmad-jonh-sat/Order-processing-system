<?php

use App\Http\Middleware\VerifyJwtToken;
use App\Http\Controllers\Api\ShopOrderController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => response()->json(['service' => 'order-service', 'ok' => true]));

Route::middleware(VerifyJwtToken::class)->get('/profile', function (Request $request) {
    return response()->json([
        'service' => 'order-service',
        'user' => $request->attributes->get('jwt_payload')['user'] ?? null,
    ]);
});

Route::get('/products', [ShopOrderController::class, 'indexProducts']);

Route::middleware(VerifyJwtToken::class)->group(function (): void {
    Route::post('/shop/orders', [ShopOrderController::class, 'store']);
    Route::get('/shop/orders/{id}', [ShopOrderController::class, 'show']);
});

Route::post('/internal/orders/{id}/reserve-stock', [ShopOrderController::class, 'reserveStock']);
Route::get('/internal/orders/{id}', [ShopOrderController::class, 'internalShow']);
Route::post('/internal/orders/{id}/payment-reserved', [ShopOrderController::class, 'markPaymentReserved']);
Route::post('/internal/orders/{id}/complete', [ShopOrderController::class, 'complete']);
Route::post('/internal/orders/{id}/cancel', [ShopOrderController::class, 'cancel']);
