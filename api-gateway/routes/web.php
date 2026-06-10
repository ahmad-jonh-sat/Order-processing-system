<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

Route::get('/api/ping', function () {
    return response()->json([
        'service' => 'api-gateway',
        'ok' => true,
    ]);
});
