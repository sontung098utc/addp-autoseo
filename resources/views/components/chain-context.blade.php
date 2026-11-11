@props(['step' => null, 'purpose' => '', 'inputs' => [], 'outputs' => [], 'compact' => false])
{{--
    Vai trò của trang trong chuỗi làm nội dung, GỌN MỘT DÒNG: "Lấy từ … → Kết quả đi tới …" và dấu (i) bấm để đọc trang này để làm gì.
    inputs/outputs: list<array{label:string, url?:?string, note?:?string}>. Mục không có url hiển thị chữ thường.
    compact=true: không hiện dải chữ; chỉ một dấu (i), bấm mới hiện "Lấy từ / Kết quả" (đặt cạnh các (i) khác ở đầu trang).
--}}
@if ($compact)
    <span data-testid="chain-context">
        <x-help title="{{ $step ? $step.': ' : '' }}Trang này nối với đâu">
            @if ($purpose !== '')<p>{{ $purpose }}</p>@endif
            <p><strong>Lấy từ:</strong>
                @forelse ($inputs as $i)@if (! empty($i['url']))<a href="{{ $i['url'] }}" style="text-decoration:underline">{{ $i['label'] }}</a>@else{{ $i['label'] }}@endif@if (! empty($i['note'])) ({{ $i['note'] }})@endif{{ $loop->last ? '' : ' · ' }}@empty bạn nhập trực tiếp @endforelse
            </p>
            <p><strong>Kết quả:</strong>
                @forelse ($outputs as $o)@if (! empty($o['url']))<a href="{{ $o['url'] }}" style="text-decoration:underline">{{ $o['label'] }}</a>@else{{ $o['label'] }}@endif@if (! empty($o['note'])) ({{ $o['note'] }})@endif{{ $loop->last ? '' : ' · ' }}@empty chỉ để xem @endforelse
            </p>
        </x-help>
    </span>
@else
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
@endif
