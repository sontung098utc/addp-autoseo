{{-- Đầu vào / Đầu ra của một chặng: nội dung thật (xem App\Filament\Support\Agent\StepIo). Mọi giá trị được escape bằng {{ }}. --}}
@php $io = \App\Filament\Support\Agent\StepIo::for($s, $run); @endphp
@if ($io['in'] || $io['out'])
    <div class="rvx-io" data-testid="step-io">
        @foreach (['in' => 'Đầu vào', 'out' => 'Đầu ra'] as $k => $lab)
            @if ($io[$k])
                <div class="rvx-io__h">{{ $lab }}</div>
                @foreach ($io[$k] as $b)
                    <div class="rvx-io__b">
                        <div class="rvx-io__k">{{ $b['label'] }}</div>
                        @switch($b['type'])
                            @case('list')
                                <ul class="rvx-io__ul">@foreach ((array) $b['value'] as $li)<li>{{ $li }}</li>@endforeach</ul>
                                @break
                            @case('long')
                                <details class="rvx-io__d"><summary>Xem nguyên văn ({{ number_format(mb_strlen((string) $b['value'])) }} ký tự)</summary><pre class="rvx-io__pre">{{ mb_substr((string) $b['value'], 0, 20000) }}</pre></details>
                                @break
                            @case('outline')
                                <ol class="rvx-io__ol">
                                    @foreach ((array) $b['value'] as $o)
                                        @php $o = is_array($o) ? $o : ['heading' => (string) $o]; @endphp
                                        <li>
                                            <strong>{{ strtoupper((string) ($o['level'] ?? '')) }} {{ $o['heading'] ?? $o['title'] ?? '' }}</strong>@if (! empty($o['target_word_count'])) <span class="rvx-note">· ~{{ $o['target_word_count'] }} từ</span>@endif
                                            @if (! empty($o['purpose']))<div>{{ $o['purpose'] }}</div>@endif
                                            @if (! empty($o['key_points']))<ul class="rvx-io__ul">@foreach ((array) $o['key_points'] as $kp)<li>{{ is_scalar($kp) ? $kp : '' }}</li>@endforeach</ul>@endif
                                        </li>
                                    @endforeach
                                </ol>
                                @break
                            @case('plan')
                                @foreach ((array) $b['value'] as $sl)
                                    <div class="rvx-io__slot">
                                        <strong>{{ ($sl['role'] ?? '') === 'featured' ? 'Ảnh bìa' : 'Ảnh sau mục: '.($sl['section_heading'] ?? '') }}</strong>
                                        <div>{{ $sl['why'] ?? '' }}</div>
                                        <div class="rvx-note">Alt: {{ $sl['alt'] ?? '' }}</div>
                                        <details class="rvx-io__d"><summary>Mô tả ảnh (prompt)</summary><pre class="rvx-io__pre">{{ $sl['prompt'] ?? '' }}</pre></details>
                                    </div>
                                @endforeach
                                @break
                            @case('images')
                                <div class="rvx-io__imgs">
                                    @foreach ((array) $b['value'] as $im)
                                        <figure>
                                            @if ($im['url'])<img src="{{ $im['url'] }}" alt="{{ $im['alt'] }}" loading="lazy">@endif
                                            <figcaption>{{ $im['role'] === 'featured' ? 'Ảnh bìa' : 'Ảnh trong bài' }}@if ($im['alt']) — {{ $im['alt'] }}@endif</figcaption>
                                            @if ($im['prompt'])<details class="rvx-io__d"><summary>Prompt đã dùng</summary><pre class="rvx-io__pre">{{ $im['prompt'] }}</pre></details>@endif
                                        </figure>
                                    @endforeach
                                </div>
                                @break
                            @case('placements')
                                <ul class="rvx-io__ul">
                                    @foreach ((array) $b['value'] as $p)
                                        <li><strong>{{ ($p['role'] ?? '') === 'featured' ? 'Ảnh bìa' : 'Ảnh '.($p['slot'] ?? '') }}</strong>: {{ ['inserted' => 'đã chèn', 'featured' => 'gắn làm ảnh bìa', 'unplaced' => 'chưa chèn được', 'missing' => 'thiếu ảnh', 'unused' => 'không dùng'][$p['status'] ?? ''] ?? ($p['status'] ?? '') }} — {{ $p['note'] ?? '' }}</li>
                                    @endforeach
                                </ul>
                                @break
                            @default
                                <div class="rvx-io__t">{{ $b['value'] }}</div>
                        @endswitch
                    </div>
                @endforeach
            @endif
        @endforeach
    </div>
@endif
