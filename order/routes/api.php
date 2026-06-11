<?php

use App\Http\Middleware\VerifyJwtToken;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => response()->json(['service' => 'order-service', 'ok' => true]));

Route::middleware(VerifyJwtToken::class)->get('/profile', function (Request $request) {
    return response()->json([
        'service' => 'order-service',
        'user' => $request->attributes->get('jwt_payload')['user'] ?? null,
    ]);
});
