<?php

use App\Http\Controllers\Api\ProxyController;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => response()->json(['service' => 'api-gateway', 'ok' => true]));

Route::any('/auth/{path?}', [ProxyController::class, 'userAuth'])->where('path', '.*');
Route::any('/users/{path?}', [ProxyController::class, 'users'])->where('path', '.*');
Route::any('/orders/{path?}', [ProxyController::class, 'orders'])->where('path', '.*');
Route::any('/reports/{path?}', [ProxyController::class, 'reports'])->where('path', '.*');

Route::get('/shop/products', [ProxyController::class, 'shopProducts']);
Route::post('/shop/orders', [ProxyController::class, 'shopCreateOrder']);
Route::get('/shop/orders/{id}', [ProxyController::class, 'shopGetOrder']);
Route::post('/shop/orders/{id}/cancel', [ProxyController::class, 'shopCancelOrder']);
Route::get('/shop/reports/{orderId}', [ProxyController::class, 'shopGetReport']);
Route::get('/shop/reports/{orderId}/download', [ProxyController::class, 'shopDownloadReport']);
Route::post('/shop/wallet/top-up', [ProxyController::class, 'shopTopUpWallet']);
Route::get('/shop/wallet', [ProxyController::class, 'shopWallet']);
