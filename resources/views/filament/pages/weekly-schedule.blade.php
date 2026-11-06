@php
    use App\Filament\Support\Schedule\VnTime;
    use App\Filament\Support\StatusPresenter;
    $card = function ($p) use ($viewUrl) {
        return $viewUrl($p);
    };
@endphp
<x-filament-panels::page>
    <style>
        .ss-sched-bar{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap}
        .ss-sched-grid{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:.5rem}
        @media (max-width:1024px){.ss-sched-grid{grid-template-columns:repeat(2,minmax(0,1fr))}}
        .ss-sched-day{border:1px solid rgba(128,128,128,.25);border-radius:.75rem;padding:.5rem;min-height:8rem}
        .ss-sched-day.is-today{border-color:rgb(var(--primary-600));box-shadow:0 0 0 2px rgba(var(--primary-600),.25)}
        .ss-sched-dayh{font-weight:600;font-size:.8rem;margin-bottom:.4rem}
        .ss-sched-card{display:block;border-left:4px solid #9ca3af;border-radius:.4rem;padding:.3rem .45rem;margin-bottom:.35rem;font-size:.75rem;background:rgba(128,128,128,.08);text-decoration:none;color:inherit}
        .ss-sched-card.ss-sched-c-success{border-color:rgb(var(--success-600))}
        .ss-sched-card.ss-sched-c-danger{border-color:rgb(var(--danger-600))}
        .ss-sched-card.ss-sched-c-warning{border-color:rgb(var(--warning-600))}
        .ss-sched-card.ss-sched-c-info{border-color:rgb(var(--info-600))}
        .ss-sched-day.is-past{opacity:.55;background:repeating-linear-gradient(45deg,transparent,transparent 6px,rgba(128,128,128,.08) 6px,rgba(128,128,128,.08) 12px)}
        .ss-sched-day.is-over{border-color:rgb(var(--primary-600));background:rgba(var(--primary-600),.08)}
        .ss-sched-card[draggable=true]{cursor:grab}
        .ss-sched-card.is-dragging{opacity:.4}
        .ss-sched-card.is-locked{cursor:not-allowed}
        .ss-sched-wrap{position:relative}
        .ss-sched-resched{font-size:.7rem;background:none;border:0;padding:0;text-decoration:underline;cursor:pointer;color:inherit}
        .ss-sched-time{font-weight:600}
        .ss-sched-muted{opacity:.75;font-size:.8rem}
        .ss-sched-box{border:1px solid rgba(128,128,128,.25);border-radius:.75rem;padding:1rem;margin-top:1rem}
        .ss-sched-h{font-weight:600;margin-bottom:.5rem}
    </style>

    <div class="ss-sched-bar">
        <x-filament::button color="gray" wire:click="previousWeek" icon="heroicon-o-chevron-left">Tuần trước</x-filament::button>
        <x-filament::button color="gray" wire:click="currentWeek">Tuần này</x-filament::button>
        <x-filament::button color="gray" wire:click="nextWeek" icon="heroicon-o-chevron-right" icon-position="after">Tuần sau</x-filament::button>
        <strong>{{ $rangeLabel }}</strong>
    </div>

    <div class="ss-sched-grid" x-data="{ over: null, dragId: null }">
        @foreach ($days as $day)
            @php $isPast = $day['key'] < $todayKey; @endphp
            <div class="ss-sched-day {{ $day['isToday'] ? 'is-today' : '' }} {{ $isPast ? 'is-past' : '' }}" data-day="{{ $day['key'] }}"
                @if ($canDrag && ! $isPast)
                    :class="{ 'is-over': over === '{{ $day['key'] }}' }"
                    x-on:dragover.prevent="over = '{{ $day['key'] }}'"
                    x-on:dragleave="if (over === '{{ $day['key'] }}') over = null"
                    x-on:drop.prevent="over = null; if (dragId) { $wire.moveToDay(dragId, '{{ $day['key'] }}'); dragId = null }"
                @endif
                @if ($canDrag && $isPast)
                    {{-- Ngày đã qua: vẫn nhận thả để máy chủ từ chối và nói rõ lý do (không im lặng). --}}
                    x-on:dragover.prevent
                    x-on:drop.prevent="over = null; if (dragId) { $wire.moveToDay(dragId, '{{ $day['key'] }}'); dragId = null }"
                @endif
                @if ($isPast) title="Ngày đã qua: không thể đổi lịch sang ngày này" @endif>
                <div class="ss-sched-dayh">{{ $day['name'] }} {{ $day['label'] }}@if ($day['isToday']) · Hôm nay @endif</div>
                @forelse ($day['items'] as [$p, $at])
                    @php
                        $reason = $reasonFor($p);
                        $movable = $canDrag && $reason === null;
                    @endphp
                    <div class="ss-sched-card ss-sched-c-{{ StatusPresenter::color($p->status, 'publication') }} {{ $movable ? '' : 'is-locked' }}"
                        tabindex="0" data-publication="{{ $p->id }}"
                        draggable="{{ $movable ? 'true' : 'false' }}"
                        title="{{ $movable ? 'Kéo sang ngày khác để đổi ngày (giữ nguyên giờ)' : ($reason ?? StatusPresenter::label($p->status, 'publication')) }}"
                        @if ($movable)
                            x-on:dragstart="dragId = {{ $p->id }}; $el.classList.add('is-dragging')"
                            x-on:dragend="dragId = null; over = null; $el.classList.remove('is-dragging')"
                        @endif>
                        <a href="{{ $viewUrl($p) }}" style="text-decoration:none;color:inherit;display:block" draggable="false">
                            <span class="ss-sched-time">{{ VnTime::format($at, 'H:i') }}</span>
                            {{ $p->articleVersion?->article?->primary_keyword ?? '—' }}
                            <div class="ss-sched-muted">{{ $p->website?->name }} · {{ StatusPresenter::label($p->status, 'publication') }}</div>
                        </a>
                        @if ($movable)
                            <button type="button" class="ss-sched-resched" wire:click="mountAction('reschedulePublication', { publication: {{ $p->id }} })">Đổi lịch</button>
                        @endif
                    </div>
                @empty
                    <div class="ss-sched-muted">Không có lượt đăng</div>
                @endforelse
            </div>
        @endforeach
    </div>
    <x-filament-actions::modals />

    <div class="ss-sched-box">
        <div class="ss-sched-h">Hàng đợi hôm nay ({{ $todayQueue->count() }})</div>
        @forelse ($todayQueue as $p)
            <div><a href="{{ $viewUrl($p) }}">{{ VnTime::format($p->scheduled_at_utc ? VnTime::fromRaw($p, 'scheduled_at_utc') : null, 'H:i') }} · {{ $p->articleVersion?->article?->primary_keyword ?? '—' }}</a>
                <span class="ss-sched-muted">{{ $p->website?->name }} · {{ StatusPresenter::label($p->status, 'publication') }}</span></div>
        @empty
            <div class="ss-sched-muted">Hôm nay chưa có lượt đăng nào.</div>
        @endforelse
    </div>

    <div class="ss-sched-box">
        <div class="ss-sched-h">Cần đối soát ({{ $reconcile->count() }})</div>
        <p class="ss-sched-muted">CMS không phản hồi sau khi gửi bài. Mở từng lượt để "Đối soát ngay" - hệ thống không tự đăng lại để tránh trùng bài.</p>
        @forelse ($reconcile as $p)
            <div><a href="{{ $viewUrl($p) }}">{{ $p->articleVersion?->article?->primary_keyword ?? '—' }}</a>
                <span class="ss-sched-muted">{{ $p->website?->name }} · {{ VnTime::format(VnTime::fromRaw($p, 'scheduled_at_utc')) }}</span></div>
        @empty
            <div class="ss-sched-muted">Không có lượt nào chờ đối soát.</div>
        @endforelse
    </div>
</x-filament-panels::page>
