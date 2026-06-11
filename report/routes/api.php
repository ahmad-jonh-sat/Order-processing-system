<?php

use App\Http\Middleware\VerifyJwtToken;
use App\Http\Controllers\Api\ReportController;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::get('/ping', fn () => response()->json(['service' => 'report-service', 'ok' => true]));

Route::middleware(VerifyJwtToken::class)->get('/profile', function (Request $request) {
    return response()->json([
        'service' => 'report-service',
        'user' => $request->attributes->get('jwt_payload')['user'] ?? null,
    ]);
});

Route::middleware(VerifyJwtToken::class)->group(function (): void {
    Route::get('/shop/reports/{orderId}', [ReportController::class, 'show']);
    Route::get('/shop/reports/{orderId}/download', [ReportController::class, 'download']);
});

Route::post('/internal/reports/generate', [ReportController::class, 'generate']);
