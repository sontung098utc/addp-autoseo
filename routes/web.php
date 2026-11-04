<?php

use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return response()->json([
        'name' => config('app.name'),
        'version' => '1.0.0-dev',
        'status' => 'operational',
        'timestamp' => now()->toIso8601String(),
    ]);
});
