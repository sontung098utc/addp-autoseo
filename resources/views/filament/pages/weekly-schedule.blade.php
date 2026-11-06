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
        <span class="ss-sched-muted">Giờ Việt Nam (UTC+7). Chỉ xem; kéo thả đổi giờ chưa hỗ trợ (dự kiến V1.2).</span>
    </div>

    <div class="ss-sched-grid">
        @foreach ($days as $day)
            <div class="ss-sched-day {{ $day['isToday'] ? 'is-today' : '' }}" data-day="{{ $day['key'] }}">
                <div class="ss-sched-dayh">{{ $day['name'] }} {{ $day['label'] }}@if ($day['isToday']) · Hôm nay @endif</div>
                @forelse ($day['items'] as [$p, $at])
                    <a class="ss-sched-card ss-sched-c-{{ StatusPresenter::color($p->status, 'publication') }}" href="{{ $viewUrl($p) }}" title="{{ StatusPresenter::label($p->status, 'publication') }}">
                        <span class="ss-sched-time">{{ VnTime::format($at, 'H:i') }}</span>
                        {{ $p->articleVersion?->article?->primary_keyword ?? '—' }}
                        <div class="ss-sched-muted">{{ $p->website?->name }} · {{ StatusPresenter::label($p->status, 'publication') }}</div>
                    </a>
                @empty
                    <div class="ss-sched-muted">Không có lượt đăng</div>
                @endforelse
            </div>
        @endforeach
    </div>

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
