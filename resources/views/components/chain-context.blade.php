@props(['step' => null, 'purpose' => '', 'inputs' => [], 'outputs' => []])
{{--
    Vai trò của trang trong chuỗi làm nội dung, GỌN MỘT DÒNG: "Lấy từ … → Kết quả đi tới …" và dấu (i) bấm để đọc trang này để làm gì.
    inputs/outputs: list<array{label:string, url?:?string, note?:?string}>. Mục không có url hiển thị chữ thường.
--}}
<div class="cc-line" data-testid="chain-context">
    <style>
        .cc-line{display:flex;flex-wrap:wrap;align-items:center;gap:.35rem .6rem;margin:0 0 .75rem;font-size:.8125rem;opacity:.9}
        .cc-k{font-size:.6875rem;letter-spacing:.04em;text-transform:uppercase;opacity:.6;font-weight:600}
        .cc-line a{text-decoration:underline;text-underline-offset:2px}
        .cc-sep{opacity:.4}
        .cc-grp{display:inline-flex;flex-wrap:wrap;align-items:center;gap:.25rem .5rem}
    </style>
    <span class="cc-grp"><span class="cc-k">Lấy từ</span>
        @forelse ($inputs as $i)
            <span>@if (! empty($i['url']))<a href="{{ $i['url'] }}">{{ $i['label'] }}</a>@else{{ $i['label'] }}@endif</span>@if (! $loop->last)<span class="cc-sep">·</span>@endif
        @empty
            <span style="opacity:.6">bạn nhập trực tiếp</span>
        @endforelse
    </span>
    <span class="cc-sep">→</span>
    <span class="cc-grp"><span class="cc-k">Kết quả</span>
        @forelse ($outputs as $o)
            <span>@if (! empty($o['url']))<a href="{{ $o['url'] }}">{{ $o['label'] }}</a>@else{{ $o['label'] }}@endif</span>@if (! $loop->last)<span class="cc-sep">·</span>@endif
        @empty
            <span style="opacity:.6">chỉ để xem</span>
        @endforelse
    </span>
    @if ($purpose !== '')
        <x-help title="{{ $step ? $step.': ' : '' }}Trang này để làm gì">
            <p>{{ $purpose }}</p>
            @php($notes = collect(array_merge($inputs, $outputs))->filter(fn ($x) => ! empty($x['note']))->map(fn ($x) => $x['label'].': '.$x['note']))
            @if ($notes->isNotEmpty())<ul>@foreach ($notes as $n)<li>{{ $n }}</li>@endforeach</ul>@endif
        </x-help>
    @endif
</div>
