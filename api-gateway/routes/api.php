<?php

use App\Http\Controllers\Api\ProxyController;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => response()->json(['service' => 'api-gateway', 'ok' => true]));

Route::any('/auth/{path?}', [ProxyController::class, 'userAuth'])->where('path', '.*');
Route::any('/users/{path?}', [ProxyController::class, 'users'])->where('path', '.*');
Route::any('/orders/{path?}', [ProxyController::class, 'orders'])->where('path', '.*');
Route::any('/reports/{path?}', [ProxyController::class, 'reports'])->where('path', '.*');
