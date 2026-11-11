@props(['title' => null, 'text' => null, 'tone' => 'info', 'align' => 'left', 'label' => 'Xem hướng dẫn'])
{{--
    Hướng dẫn gọn: một biểu tượng (i), BẤM vào mới hiện khung giải thích (đóng bằng bấm ra ngoài hoặc phím Esc).
    Dùng thay cho mọi đoạn chữ hướng dẫn/giải thích nằm sẵn trên trang, để màn hình chỉ còn nghiệp vụ chính.
    Dùng: <x-help title="Tiêu đề ngắn">Nội dung (có thể nhiều đoạn, danh sách)</x-help>  hoặc  <x-help text="Một câu." />
    tone: info | warning (warning dùng cho lưu ý quan trọng nhưng không phải lỗi cần xử lý ngay). align: left | right (mép khung theo biểu tượng).
--}}
@once
    <style>
        .hp{position:relative;display:inline-flex;vertical-align:middle}
        .hp-btn{display:inline-flex;align-items:center;justify-content:center;width:1.25rem;height:1.25rem;padding:0;border:0;background:transparent;border-radius:999px;cursor:pointer;opacity:.6;color:inherit}
        .hp-btn:hover,.hp-btn[aria-expanded=true]{opacity:1;background:rgba(128,128,128,.15)}
        .hp-btn:focus-visible{outline:2px solid currentColor;outline-offset:1px}
        .hp-btn--warning{color:#d97706;opacity:.9}
        .hp-pop{position:absolute;top:1.6rem;z-index:60;width:min(22rem,80vw);padding:.7rem .85rem;border-radius:.6rem;font-size:.8125rem;line-height:1.5;font-weight:400;text-align:left;
            background:#fff;color:#0f172a;border:1px solid rgba(128,128,128,.35);box-shadow:0 8px 24px rgba(0,0,0,.18)}
        .hp-pop--left{left:0}.hp-pop--right{right:0}
        @media (prefers-color-scheme:dark){.hp-pop{background:#1f2937;color:#e5e7eb}}
        :root[data-theme=dark] .hp-pop,.dark .hp-pop{background:#1f2937;color:#e5e7eb}
        .hp-pop b.hp-t{display:block;margin-bottom:.25rem}
        .hp-pop p{margin:.25rem 0}.hp-pop ul{margin:.25rem 0 .25rem 1.1rem;list-style:disc}
    </style>
@endonce
<span class="hp" x-data="{ open: false }" @keydown.escape.window="open = false" @click.outside="open = false" data-testid="help">
    <button type="button" class="hp-btn @if ($tone === 'warning') hp-btn--warning @endif" :aria-expanded="open" aria-haspopup="dialog" aria-label="{{ $label }}" @click.stop="open = ! open">
        <x-filament::icon :icon="$tone === 'warning' ? 'heroicon-o-exclamation-circle' : 'heroicon-o-information-circle'" style="width:1.05rem;height:1.05rem" />
    </button>
    <div class="hp-pop hp-pop--{{ $align === 'right' ? 'right' : 'left' }}" x-show="open" x-cloak x-transition.opacity.duration.120ms role="dialog" @if ($title) aria-label="{{ $title }}" @endif>
        @if ($title)<b class="hp-t">{{ $title }}</b>@endif
        @if ($text !== null)<p>{{ $text }}</p>@endif
        {{ $slot }}
    </div>
</span>
