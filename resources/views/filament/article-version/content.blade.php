@php
    // Làm sạch lại lần nữa trước khi nhúng; iframe sandbox rỗng chặn script. {{ }} escape đúng cho thuộc tính srcdoc.
    $clean = \App\Services\Content\HtmlSanitizer::clean($getRecord()->html_content);
    $doc = '<!doctype html><meta charset="utf-8"><style>body{font-family:sans-serif;line-height:1.6;margin:12px}img{max-width:100%}</style>' . $clean;
@endphp
<iframe sandbox="" srcdoc="{{ $doc }}" title="Xem trước nội dung bài" style="width:100%;min-height:480px;border:1px solid #e5e7eb;border-radius:8px;"></iframe>
