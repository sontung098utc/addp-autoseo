@php
    // Làm sạch lại lần nữa trước khi nhúng; iframe sandbox chặn script và điều hướng khung, chỉ cho mở liên kết ở tab mới. {{ }} escape đúng cho thuộc tính srcdoc.
    $clean = \App\Services\Content\HtmlSanitizer::clean($getRecord()->html_content);
    // Ảnh của thư viện được nhúng data URI chỉ cho khung xem trước (sandbox rỗng không tải được ảnh từ website); không đổi HTML đã lưu.
    $doc = '<!doctype html><meta charset="utf-8"><style>' . \App\Filament\Support\Article\PreviewCss::STYLE . '</style>' . \App\Filament\Support\Article\PreviewNotes::prepare(\App\Filament\Support\Article\PreviewImages::inline($clean));
@endphp
<iframe sandbox="allow-popups allow-popups-to-escape-sandbox" srcdoc="{{ $doc }}" title="Xem trước nội dung bài" class="ss-review-frame" style="width:100%;min-height:480px;height:70vh;"></iframe>
