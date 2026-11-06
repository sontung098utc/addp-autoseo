@php
    // Làm sạch lại lần nữa trước khi nhúng; iframe sandbox rỗng chặn script. {{ }} escape đúng cho thuộc tính srcdoc.
    $clean = \App\Services\Content\HtmlSanitizer::clean($getRecord()->html_content);
    $doc = '<!doctype html><meta charset="utf-8"><style>body{font-family:sans-serif;line-height:1.6;margin:12px}img{max-width:100%}</style>' . $clean;
@endphp
<iframe sandbox="" srcdoc="{{ $doc }}" title="Xem trước nội dung bài" class="ss-review-frame" style="width:100%;min-height:480px;height:70vh;"></iframe>
