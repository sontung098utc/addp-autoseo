<?php

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

Route::redirect('/admin/doi-ai/ket-noi-ai', '/admin/cau-hinh-ai#tab-tich-hop-ai', 301);
Route::redirect('/admin/ke-hoach-noi-dung', '/admin/doi-ai/ke-hoach', 301); // Kế hoạch nay là tab của cụm Đội AI
Route::redirect('/admin/tich-hop-ai', '/admin/cau-hinh-ai#tab-tich-hop-ai', 301);
// "Chi phí AI" đổi tên thành "Cấu hình AI": URL cũ chuyển về trang mới, ?tab=cau-hinh về tab Tích hợp AI.
Route::get('/admin/chi-phi-ai', fn (Request $r) => redirect('/admin/cau-hinh-ai'.($r->query('tab') === 'cau-hinh' ? '#tab-tich-hop-ai' : ''), 301));
Route::redirect('/admin/doi-ai/chinh-sach', '/admin/chinh-sach-tin-cay', 301);
// Z2: Kho trí tuệ là một cụm có tab; URL cũ chuyển hướng về đúng tab (giữ query như ?topic=).
Route::redirect('/admin/kho-kien-thuc', '/admin/kho-tri-tue/tri-tue', 301); // AG-B1: "Kho kiến thức" thành "Kho trí tuệ"
Route::redirect('/admin/kho-tri-tue', '/admin/kho-tri-tue/tri-tue', 301);
Route::get('/admin/xay-kho', fn (Request $r) => redirect('/admin/kho-tri-tue/tao-tri-tue-doanh-nghiep'.($r->getQueryString() ? '?'.$r->getQueryString() : ''), 301));
Route::get('/admin/kho-tri-tue/nap-kien-thuc', fn (Request $r) => redirect('/admin/kho-tri-tue/tao-tri-tue-doanh-nghiep'.($r->getQueryString() ? '?'.$r->getQueryString() : ''), 301));
Route::redirect('/admin/khoi-tao-website', '/admin/kho-tri-tue/tao-tri-tue-doanh-nghiep#khoi-tao-nhanh', 301);

Route::get('/', function () {
    return response()->json([
        'name' => config('app.name'),
        'version' => '1.0.0-dev',
        'status' => 'operational',
        'timestamp' => now()->toIso8601String(),
    ]);
});
