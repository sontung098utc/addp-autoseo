@props(['step' => null, 'purpose' => '', 'inputs' => [], 'outputs' => []])
{{--
    "Vai trò của trang trong chuỗi làm ra nội dung": một dải gọn ở đầu trang nói thẳng trang này để làm gì, lấy đầu vào từ đâu, kết quả đi tới đâu.
    inputs/outputs: list<array{label:string, url?:?string, note?:?string}>. Mục không có url hiển thị chữ thường (chưa có trang).
    Dùng: <x-chain-context step="Khám phá" purpose="..." :inputs="[...]" :outputs="[...]" />
--}}
<div class="cc-strip" data-testid="chain-context">
    <style>
        .cc-strip{display:flex;flex-wrap:wrap;align-items:stretch;gap:.5rem;margin:0 0 1rem;font-size:.8125rem;line-height:1.35}
        .cc-box{border:1px solid rgba(128,128,128,.28);border-radius:.6rem;padding:.45rem .7rem;background:rgba(128,128,128,.05);min-width:0}
        .cc-box h4{margin:0 0 .2rem;font-size:.6875rem;letter-spacing:.04em;text-transform:uppercase;opacity:.65;font-weight:600}
        .cc-purpose{flex:2 1 18rem}
        .cc-io{flex:1 1 11rem}
        .cc-arrow{align-self:center;opacity:.5}
        .cc-list{margin:0;padding:0;list-style:none}
        .cc-list li{margin:.1rem 0}
        .cc-list a{text-decoration:underline;text-underline-offset:2px}
        .cc-note{opacity:.65}
        .cc-step{display:inline-block;font-size:.6875rem;padding:.05rem .4rem;border-radius:999px;background:rgba(var(--primary-500,16,185,129),.15);margin-right:.35rem}
    </style>
    <div class="cc-box cc-io">
        <h4>Lấy từ</h4>
        <ul class="cc-list">
            @forelse ($inputs as $i)
                <li>@if (! empty($i['url']))<a href="{{ $i['url'] }}">{{ $i['label'] }}</a>@else{{ $i['label'] }}@endif @if (! empty($i['note']))<span class="cc-note">· {{ $i['note'] }}</span>@endif</li>
            @empty
                <li class="cc-note">Bạn nhập trực tiếp</li>
            @endforelse
        </ul>
    </div>
    <div class="cc-arrow">→</div>
    <div class="cc-box cc-purpose">
        <h4>@if ($step)<span class="cc-step">{{ $step }}</span>@endif Trang này để làm gì</h4>
        <div>{{ $purpose }}</div>
    </div>
    <div class="cc-arrow">→</div>
    <div class="cc-box cc-io">
        <h4>Kết quả đi tới</h4>
        <ul class="cc-list">
            @forelse ($outputs as $o)
                <li>@if (! empty($o['url']))<a href="{{ $o['url'] }}">{{ $o['label'] }}</a>@else{{ $o['label'] }}@endif @if (! empty($o['note']))<span class="cc-note">· {{ $o['note'] }}</span>@endif</li>
            @empty
                <li class="cc-note">Chỉ để xem</li>
            @endforelse
        </ul>
    </div>
</div>
