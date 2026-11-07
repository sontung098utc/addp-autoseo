@php
    $stepColor = ['ok' => 'success', 'warn' => 'warning', 'blocked' => 'gray', 'failed' => 'danger', 'skipped' => 'gray'];
    $stepLabel = ['ok' => 'Xong', 'warn' => 'Cần chú ý', 'blocked' => 'Bị chặn', 'failed' => 'Lỗi', 'skipped' => 'Bỏ qua'];
    $roles = ['system' => 'Hệ thống', 'strategist' => 'Chiến lược', 'writer' => 'Người viết', 'editor' => 'Biên tập'];
    [$sLabel, $sColor] = $statusMap[$run->status] ?? [$run->status, 'gray'];
    if ($stale) { [$sLabel, $sColor] = ['Có thể bị treo', 'warning']; }
    $byStage = $run->steps->groupBy('stage');
    $timeline = \App\Services\Agent\Run\RunScorer::timeline($run->steps);
    $decisions = $run->steps->where('stage', 'decision');
    $retries = $run->steps->where('stage', 'retry');
@endphp
<x-filament-panels::page>
    <div class="space-y-4" data-testid="agent-run" @if ($live) wire:poll.{{ $pollSeconds }}s @endif>
        @if ($live)
            <div class="ss-live-pill" data-testid="run-live" style="display:flex;border-radius:.6rem;padding:.5rem .8rem">
                <x-studio.running label="Đang chạy" />
                <span>· Đang ở: <strong>{{ $blueprint[$currentStage]['title'] ?? 'hoàn tất, đang đóng phiên' }}</strong> · đã chạy {{ $elapsed }}</span>
            </div>
        @elseif ($stale)
            <div data-testid="run-stale" style="border:1px dashed #d97706;border-radius:.6rem;padding:.5rem .8rem;color:#b45309;font-size:.85rem">
                Có thể bị treo: phiên vẫn ghi là đang chạy nhưng quá {{ \App\Filament\Support\Agent\RunActivity::STALE_MINUTES }} phút không có bước mới. Đã ngừng làm mới tự động; tải lại trang để kiểm tra.
            </div>
        @endif
        <x-filament::section>
            <div style="display:flex;flex-wrap:wrap;gap:.75rem;align-items:center">
                <x-filament::badge :color="$sColor">{{ $sLabel }}</x-filament::badge>
                <x-filament::badge :color="$run->ai_mode === 'real' ? 'success' : 'gray'">{{ $run->ai_mode === 'real' ? 'AI thật' : 'AI giả lập' }}</x-filament::badge>
                <x-filament::badge color="gray">Bậc tin cậy {{ $run->trust_tier }}: chỉ tạo bản nháp</x-filament::badge>
                @if ($run->risk_level)<x-filament::badge :color="['low'=>'success','medium'=>'warning','high'=>'danger'][$run->risk_level] ?? 'gray'">Rủi ro {{ ['low'=>'thấp','medium'=>'vừa','high'=>'cao'][$run->risk_level] ?? $run->risk_level }}</x-filament::badge>@endif
            </div>
            <div class="mt-3 text-sm"><strong>{{ $run->article?->primary_keyword }}</strong> · {{ $run->website?->name }}</div>
            @if ($run->outcome_reason)<div class="mt-1 text-sm">{{ $run->outcome_reason }}</div>@endif
            <div class="mt-2 text-xs" style="opacity:.7">
                {{ $run->repair_rounds }} vòng sửa · {{ number_format($run->tokens_used, 0, ',', '.') }} token · {{ number_format($run->cost_vnd, 0, ',', '.') }} ₫
                @if ($run->finalVersion) · Bản nháp v{{ $run->finalVersion->version }}: <a class="underline" href="{{ \App\Filament\Resources\ArticleVersionResource::getUrl('view', ['record' => $run->finalVersion]) }}">xem bài</a>@endif
            </div>
            @include('filament.agent.cms-draft-line', ['version' => $run->finalVersion])
            @if ($run->ai_mode !== 'real')
                <div class="mt-2 text-xs" style="color:#b45309">Phiên này dùng AI giả lập nên nội dung chỉ để kiểm luồng, không phản ánh chất lượng AI thật.</div>
            @endif
        </x-filament::section>

        <x-filament::section heading="Luồng đã đi qua" description="Mỗi ô là một chặng; màu cho biết chặng đó thực tế đã diễn ra thế nào trong phiên này.">
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(8rem,1fr));gap:.4rem" data-testid="stage-rail">
                @foreach ($blueprint as $code => $b)
                    @php $ran = $byStage->get($code); $worst = $ran?->contains('status', 'failed') ? 'failed' : ($ran?->contains('status', 'blocked') ? 'blocked' : ($ran?->contains('status', 'warn') ? 'warn' : ($ran ? 'ok' : 'skipped'))); @endphp
                    @php $isNow = $live && $currentStage === $code; $isStale = $stale && \App\Filament\Support\Agent\RunActivity::currentStage($run->steps) === $code; @endphp
                    <div @if ($isNow) class="ss-stage-live" data-live-stage="{{ $code }}" @elseif ($isStale) class="ss-stage-stale" @endif style="border:1px solid rgba(128,128,128,.3);border-radius:.5rem;padding:.5rem;{{ ($ran || $isNow) ? '' : 'opacity:.5' }}">
                        <div style="font-size:.75rem;font-weight:600">{{ $b['title'] }}</div>
                        <div style="font-size:.68rem;opacity:.7">{{ $b['role'] }}</div>
                        <div class="mt-1">@if ($isNow)<x-studio.running size="sm" />@else<x-filament::badge size="sm" :color="$stepColor[$worst]">{{ $ran ? $stepLabel[$worst].($ran->count() > 1 ? ' ×'.$ran->count() : '') : 'Không chạy' }}</x-filament::badge>@endif</div>
                    </div>
                @endforeach
            </div>
        </x-filament::section>

        @if ($timeline !== [])
            <x-filament::section heading="Điểm chất lượng qua các vòng" description="Điểm 0-100 suy ra từ kết quả chấm của luật (công thức: 100 trừ 20 mỗi lỗi P0, 5 mỗi P1, 2 mỗi P2). Chỉ để theo dõi cải thiện: đạt hay không do luật P0 quyết định, không do điểm.">
                <div data-testid="score-timeline" style="font-size:1rem;font-weight:600">{{ \App\Services\Agent\Run\RunScorer::format($timeline) }}</div>
                <div class="text-xs" style="opacity:.7">Mốc tham khảo {{ \App\Services\Agent\Run\RunScorer::REFERENCE_THRESHOLD }}/100.@if ($retries->isNotEmpty()) Đã tự thử lại {{ $retries->count() }} lần khi nhà cung cấp AI lỗi tạm thời.@endif</div>
            </x-filament::section>
        @endif

        <x-filament::section heading="Quyết định tự động" description="Những lần hệ thống tự quyết định thay vì chờ người, kèm dữ kiện." data-testid="auto-decisions">
            @forelse ($decisions as $d)
                <div style="padding:.5rem 0;border-bottom:1px solid rgba(128,128,128,.18)">
                    <div style="font-weight:600;font-size:.88rem">{{ $d->title }}</div>
                    <div style="font-size:.82rem;opacity:.85">{{ $d->detail }}</div>
                </div>
            @empty
                <p class="text-sm" style="opacity:.75">Phiên này hệ thống không phải tự quyết định điều gì đặc biệt.</p>
            @endforelse
        </x-filament::section>

        <x-filament::section heading="Nhật ký từng bước" description="Mỗi bước ghi rõ lý do hệ thống làm như vậy.">
            @foreach ($run->steps as $s)
                <div style="display:grid;grid-template-columns:2rem 1fr auto;gap:.75rem;padding:.6rem 0;border-bottom:1px solid rgba(128,128,128,.18)" data-step="{{ $s->stage }}" @if ($live && $loop->last) class="ss-step-new" @endif>
                    <div style="opacity:.6;font-size:.8rem">{{ $s->seq }}</div>
                    <div>
                        <div style="font-weight:600;font-size:.88rem">{{ $s->title }} <span style="font-weight:400;opacity:.6;font-size:.75rem">· {{ $roles[$s->role] ?? $s->role }}@if($s->model) · {{ $s->model }}@endif</span></div>
                        <div data-testid="step-role-skill" style="font-size:.72rem;opacity:.7">Vai trò: {{ $s->data['role_name'] ?? ($roles[$s->role] ?? $s->role) }} · Kỹ năng: {{ $s->data['skill_label'] ?? 'Chỉ dẫn mặc định' }}@if (! empty($s->data['skill'])) · {{ $s->data['skill']['status'] }} · {{ $s->data['skill']['checksum'] }}@endif@if (! empty($s->data['skill_note'])) · {{ $s->data['skill_note'] }}@endif</div>
                        <div style="font-size:.82rem;opacity:.85">{{ $s->detail }}</div>
                        @if (isset($s->data['score']))<div style="font-size:.75rem;opacity:.8">Điểm {{ $s->data['score'] }}/100</div>@endif
                        @if (! empty($s->data['failed']))
                            <ul style="font-size:.75rem;margin:.3rem 0 0 1rem;list-style:disc;opacity:.8">
                                @foreach ($s->data['failed'] as $f)<li><strong>{{ $f['code'] }}</strong> ({{ $f['severity'] }}): {{ $f['message'] }}</li>@endforeach
                            </ul>
                        @endif
                        @if ($s->tokens_used || $s->cost_vnd > 0)<div style="font-size:.7rem;opacity:.55;margin-top:.2rem">{{ number_format($s->tokens_used) }} token · {{ number_format($s->cost_vnd, 0, ',', '.') }} ₫</div>@endif
                    </div>
                    <x-filament::badge :color="$stepColor[$s->status] ?? 'gray'">{{ $stepLabel[$s->status] ?? $s->status }}</x-filament::badge>
                </div>
            @endforeach
        </x-filament::section>

        <x-filament::section heading="Chặng nào đã thật, chặng nào cần Dev1" description="Bản thiết kế sống: cùng một nguồn với trang Cách hoạt động." collapsible collapsed>
            @foreach ($blueprint as $b)
                <div style="padding:.5rem 0;border-bottom:1px solid rgba(128,128,128,.18)">
                    <x-studio.chip domain="readiness" :value="$b['readiness']" /> <strong class="text-sm">{{ $b['title'] }}</strong>
                    <div class="text-xs" style="opacity:.8">{{ $b['what'] }}</div>
                    <div class="text-xs" style="opacity:.8"><strong>Đã có:</strong> {{ $b['built'] }}</div>
                    <div class="text-xs" style="color:#b45309"><strong>Dev1 cần ({{ $b['task'] }}):</strong> {{ $b['dev1'] }}</div>
                </div>
            @endforeach
        </x-filament::section>
    </div>
</x-filament-panels::page>
