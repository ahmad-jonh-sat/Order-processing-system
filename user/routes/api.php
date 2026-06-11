<?php

use App\Http\Controllers\Api\AuthController;
use App\Http\Controllers\Api\WalletController;
use App\Http\Middleware\VerifyJwtToken;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => response()->json(['service' => 'user-service', 'ok' => true]));

Route::post('/auth/register', [AuthController::class, 'register']);
Route::post('/auth/login', [AuthController::class, 'login']);
Route::get('/users/me', [AuthController::class, 'me']);

Route::middleware(VerifyJwtToken::class)->group(function (): void {
    Route::get('/shop/wallet', [WalletController::class, 'show']);
    Route::post('/shop/wallet/top-up', [WalletController::class, 'topUp']);
});

Route::post('/internal/wallet/charge', [WalletController::class, 'charge']);
Route::post('/internal/wallet/refund', [WalletController::class, 'refund']);
