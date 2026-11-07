<?php

use Illuminate\Support\Facades\Route;

Route::redirect('/admin/doi-ai/ket-noi-ai', '/admin/tich-hop-ai', 301);
Route::redirect('/admin/doi-ai/chinh-sach', '/admin/chinh-sach-tin-cay', 301);

Route::get('/', function () {
    return response()->json([
        'name' => config('app.name'),
        'version' => '1.0.0-dev',
        'status' => 'operational',
        'timestamp' => now()->toIso8601String(),
    ]);
});
