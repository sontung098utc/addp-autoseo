@php
    use App\Filament\Support\Schedule\ErrorMasker;
    use App\Filament\Support\Schedule\ReconcileRequest;
    use App\Filament\Support\Schedule\ScheduleExplainer;
    use App\Filament\Support\StatusPresenter;
    use App\Services\Publishing\EmergencyStopService;

    $p = $this->getRecord();
    $explain = ScheduleExplainer::explain($p->status);
    $steps = ScheduleExplainer::timeline($p);
    $reconcileWhy = ReconcileRequest::reasonUnavailable(auth()->user(), $p);

    $emergencyService = app(EmergencyStopService::class);
    $isStopped = $emergencyService->isStopped($p->website_id);
    $stopInfo = $isStopped ? $emergencyService->getStopInfo($p->website_id) : null;
@endphp
<x-filament-panels::page>
    @if ($p->articleVersion?->article_id)
        <p class="ss-sched-muted"><a style="text-decoration:underline" href="{{ \App\Filament\Resources\ArticleResource::getUrl('view', ['record' => $p->articleVersion->article_id]) }}#tab-publish">← Xem bài viết «{{ $p->articleVersion->article?->primary_keyword }}»</a></p>
    @endif
    <style>
        .ss-sched-box{border:1px solid rgba(128,128,128,.25);border-radius:.75rem;padding:1rem;margin-bottom:1rem}
        .ss-sched-h{font-weight:600;margin-bottom:.5rem}
        .ss-sched-tl{list-style:none;margin:0;padding:0}
        .ss-sched-tl li{display:flex;gap:.75rem;padding:.4rem 0}
        .ss-sched-dot{width:.75rem;height:.75rem;border-radius:9999px;margin-top:.3rem;flex:none;background:#9ca3af}
        .ss-sched-dot.is-done{background:rgb(var(--success-600))}
        .ss-sched-dot.is-current{background:rgb(var(--info-600))}
        .ss-sched-dot.is-error{background:rgb(var(--danger-600))}
        .ss-sched-dot.is-warning{background:rgb(var(--warning-600))}
        .ss-sched-muted{opacity:.75;font-size:.875rem}
        .ss-sched-emergency{background:rgba(239,68,68,.1);border-color:rgb(var(--danger-500));color:rgb(var(--danger-500));font-weight:600;padding:.75rem;border-radius:.5rem;margin-top:.75rem}
    </style>

    <div class="ss-sched-box">
        <div class="ss-sched-h">Trạng thái: {{ StatusPresenter::label($p->status, 'publication') }}</div>
        <p><strong>Vì sao ở trạng thái này:</strong> {{ $explain['why'] }}</p>
        <p><strong>Bước tiếp theo:</strong> {{ $explain['next'] }}</p>
        @if ($reconcileWhy)
            <p class="ss-sched-muted">Nút "Đối soát ngay" đang ẩn: {{ $reconcileWhy }}</p>
        @endif
        @if ($isStopped)
            <div class="ss-sched-emergency">
                ⛔ Lịch đăng đang TẠM DỪNG KHẨN CẤP: {{ $stopInfo['reason'] ?? 'Toàn hệ thống đang tạm hoãn xuất bản' }}
            </div>
        @else
            <p class="ss-sched-muted">Nút "Tạm dừng lịch đăng": Quản trị viên/Trưởng nhóm có thể kích hoạt tại trang danh sách lịch đăng.</p>
        @endif
    </div>

    <div class="ss-sched-box">
        <div class="ss-sched-h">Dòng thời gian</div>
        <ol class="ss-sched-tl">
            @foreach ($steps as $s)
                <li>
                    <span class="ss-sched-dot is-{{ $s['state'] }}"></span>
                    <div><div>{{ $s['title'] }}</div><div class="ss-sched-muted">{{ $s['detail'] }}</div></div>
                </li>
            @endforeach
        </ol>
    </div>

    @if ($p->last_error)
        <div class="ss-sched-box">
            <div class="ss-sched-h">Thông tin lỗi (đã che thông tin nhạy cảm)</div>
            <code>{{ ErrorMasker::mask($p->last_error) }}</code>
        </div>
    @endif

    {{ $this->form }}
</x-filament-panels::page>
