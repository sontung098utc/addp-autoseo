<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::redirect('/admin/doi-ai/ket-noi-ai', '/admin/chi-phi-ai?tab=cau-hinh', 301);
Route::redirect('/admin/ke-hoach-noi-dung', '/admin/doi-ai/ke-hoach', 301); // Kế hoạch nay là tab của cụm Đội AI
Route::redirect('/admin/tich-hop-ai', '/admin/chi-phi-ai?tab=cau-hinh', 301);
Route::redirect('/admin/doi-ai/chinh-sach', '/admin/chinh-sach-tin-cay', 301);
// Z2: Kho trí tuệ là một cụm có tab; URL cũ chuyển hướng về đúng tab (giữ query như ?topic=).
Route::redirect('/admin/kho-kien-thuc', '/admin/kho-tri-tue/tri-tue', 301); // AG-B1: "Kho kiến thức" thành "Kho trí tuệ"
Route::redirect('/admin/kho-tri-tue', '/admin/kho-tri-tue/tri-tue', 301);
Route::get('/admin/xay-kho', fn (Request $r) => redirect('/admin/kho-tri-tue/nap-kien-thuc'.($r->getQueryString() ? '?'.$r->getQueryString() : ''), 301));
Route::redirect('/admin/khoi-tao-website', '/admin/kho-tri-tue/nap-kien-thuc#khoi-tao-nhanh', 301);

Route::get('/', function () {
    return response()->json([
        'name' => config('app.name'),
        'version' => '1.0.0-dev',
        'status' => 'operational',
        'timestamp' => now()->toIso8601String(),
    ]);
});
