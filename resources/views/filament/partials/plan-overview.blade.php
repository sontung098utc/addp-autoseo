@php
    use App\Filament\Support\Schedule\VnTime;
    use App\Filament\Support\StatusPresenter;

    $sum = ['planned' => 0, 'writing' => 0, 'review' => 0, 'ready' => 0];
    foreach ($planGroups as $g) { foreach ($sum as $k => $_) { $sum[$k] += $g['counts'][$k]; } }
    $totalAll = array_sum($sum);
    $campaignCount = count(array_filter($planGroups, fn ($g) => $g['campaign']));
    $isApprover = auth()->user()?->isApprover();
    $goTab = fn (string $key) => "document.querySelector('.ss-tab[data-key=\"{$key}\"]')?.click()";
@endphp
<style>
    .po-empty { display: grid; grid-template-columns: repeat(auto-fit, minmax(17rem, 1fr)); gap: 1rem; margin-top: .5rem; }
    .po-cta { display: flex; flex-direction: column; gap: .5rem; align-items: flex-start; padding: 1.2rem; border: 1px solid var(--ss-border); border-radius: .8rem; background: var(--ss-surface); }
    .po-cta h4 { margin: 0; font-size: 1.05rem; font-weight: 650; color: var(--ss-text); }
        .po-stats { display: flex; flex-wrap: wrap; gap: .6rem; align-items: stretch; margin: .2rem 0 1rem; }
    .po-stat { display: flex; flex-direction: column; min-width: 6.2rem; padding: .6rem .9rem; border-radius: .7rem; background: var(--ss-surface); border: 1px solid var(--ss-border); }
    .po-stat b { font-size: 1.35rem; line-height: 1.15; font-weight: 650; color: var(--ss-text); }
    .po-stat span { font-size: var(--ss-fs-xs); color: var(--ss-text-muted); }
    .po-stat.is-hot { border-color: var(--ss-accent-fg); background: var(--ss-accent-bg); }
    .po-stat-act { display: flex; gap: .8rem; align-items: center; margin-left: auto; flex-wrap: wrap; }
    .po-g { border: 1px solid var(--ss-border); border-radius: .7rem; background: var(--ss-surface); margin-bottom: .6rem; }
    .po-g > summary { display: grid; grid-template-columns: minmax(0, 1.6fr) minmax(8rem, 1fr) minmax(7rem, auto) auto; gap: .5rem 1rem; align-items: center; padding: .75rem 1rem; cursor: pointer; list-style: none; }
    .po-g > summary::-webkit-details-marker { display: none; }
    .po-g > summary::before { content: "▸"; position: absolute; margin-left: -.8rem; color: var(--ss-text-muted); }
    .po-g[open] > summary::before { content: "▾"; }
    .po-name { font-weight: 650; color: var(--ss-text); overflow-wrap: anywhere; }
    .po-sub { font-size: var(--ss-fs-xs); color: var(--ss-text-muted); }
    .po-bar { display: flex; height: .45rem; border-radius: 999px; overflow: hidden; background: var(--ss-muted-bg); }
    .po-bar i { display: block; height: 100%; }
    .po-b-ready { background: var(--ss-ok-fg); } .po-b-review { background: var(--ss-accent-fg); } .po-b-writing { background: var(--ss-warn-fg); }
    .po-body { padding: 0 1rem .8rem; }
    .po-tbl { width: 100%; border-collapse: collapse; font-size: var(--ss-fs-sm); }
    .po-tbl td { padding: .4rem .3rem; border-top: 1px solid var(--ss-border); }
    .po-tbl td:last-child { text-align: right; white-space: nowrap; }
    @media (max-width: 720px) { .po-g > summary { grid-template-columns: 1fr; } }
</style>

@if ($planGroups === [] || ($totalAll === 0 && $campaignCount === 0))
    <h3 style="margin:.2rem 0 0;font-size:1.1rem;font-weight:650">Chưa có kế hoạch nào</h3>
    <div class="po-empty">
        @if ($isApprover)
            <div class="po-cta">
                <h4>Tôi đã có file kế hoạch <x-help text="Tải file Excel hoặc CSV lên, AI tự nhận ra cột nào là từ khóa, chiến dịch, ngày đăng. Bạn chỉ cần xem lại rồi bấm tạo." /></h4>
                <x-filament::button icon="heroicon-o-arrow-up-tray" wire:click="openImport">Nhập từ file</x-filament::button>
            </div>
        @endif
        <div class="po-cta">
            <h4>Hãy gợi ý giúp tôi <x-help text="Hệ thống đề xuất bài nên viết dựa trên từ khóa của bạn, và (với người duyệt) cả chiến dịch trọn gói từ một chủ đề." /></h4>
            <x-filament::button color="gray" x-on:click="$dispatch('plan-method', 'goi-y'); {{ $goTab('lap-ke-hoach') }}">Xem gợi ý</x-filament::button>
        </div>
    </div>
@else
    <div class="po-stats">
        @foreach ([['Chiến dịch', $campaignCount, ''], ['Chờ viết', $sum['planned'], ''], ['Đang viết', $sum['writing'], ''], ['Chờ duyệt', $sum['review'], $sum['review'] > 0 ? 'is-hot' : ''], ['Đã duyệt', $sum['ready'], '']] as [$label, $n, $cls])
            <div class="po-stat {{ $cls }}"><b>{{ $n }}</b><span>{{ $label }}</span></div>
        @endforeach
        <div class="po-stat-act">
            @if (count($undated) > 0)<a href="#" class="cp-link" x-on:click.prevent="{{ $goTab('chua-cum') }}">{{ count($undated) }} bài chưa có ngày</a>@endif
            <x-filament::button size="sm" icon="heroicon-o-plus" x-on:click="{{ $goTab('lap-ke-hoach') }}">Thêm kế hoạch</x-filament::button>
        </div>
    </div>

    @foreach ($planGroups as $g)
        @php $t = max(1, $g['total']); $c = $g['counts']; @endphp
        <details class="po-g" @if (count($planGroups) <= 3) open @endif>
            <summary>
                <div>
                    <div class="po-name">{{ $g['name'] }}</div>
                    <div class="po-sub">
                        @if ($g['from'])Dự kiến {{ \App\Filament\Support\ContentPlan\PlanStatus::rangeLabel($g['from'], $g['to']) }}
                        @else Chưa xếp ngày @endif
                    </div>
                </div>
                <div>
                    <div class="po-bar" title="{{ $c['ready'] }} đã duyệt · {{ $c['review'] }} chờ duyệt · {{ $c['writing'] }} đang viết · {{ $c['planned'] }} chờ viết">
                        <i class="po-b-ready" style="width:{{ $c['ready'] / $t * 100 }}%"></i><i class="po-b-review" style="width:{{ $c['review'] / $t * 100 }}%"></i><i class="po-b-writing" style="width:{{ $c['writing'] / $t * 100 }}%"></i>
                    </div>
                    <div class="po-sub">{{ $g['total'] }} bài · {{ $c['planned'] }} chờ viết · {{ $c['ready'] + $c['review'] }} đã có bản viết</div>
                </div>
                <div>@if ($g['state'])<x-studio.badge :tone="['success' => 'ok', 'warning' => 'warn', 'info' => 'info', 'gray' => 'muted'][$g['state']['tone']] ?? 'muted'">{{ $g['state']['label'] }}</x-studio.badge>@endif</div>
                <div>
                    @if ($g['campaign'] && $canOpenCampaign)
                        <a class="cp-link" href="{{ \App\Filament\Resources\CampaignResource::getUrl('view', ['record' => $g['campaign']]) }}" x-on:click.stop>Mở chiến dịch</a>
                    @endif
                </div>
            </summary>
            <div class="po-body">
                @if ($g['items'] === [])
                    <p class="po-sub" style="margin:.4rem 0">Chiến dịch này chưa có bài nào trong kế hoạch.@if ($isApprover) Dùng "Nhập từ file" hoặc "Gợi ý" để thêm.@endif</p>
                @else
                    <table class="po-tbl">
                        @foreach ($g['items'] as $a)
                            <tr>
                                <td><a class="cp-link" href="{{ \App\Filament\Resources\ArticleResource::getUrl('view', ['record' => $a['id']]) }}">{{ $a['keyword'] }}</a></td>
                                <td class="po-sub">{{ $a['at'] ? VnTime::format($a['at']) : 'chưa có ngày' }}</td>
                                <td><x-studio.badge :tone="$tones[StatusPresenter::color($a['status'])] ?? 'muted'">{{ StatusPresenter::label($a['status']) }}</x-studio.badge></td>
                            </tr>
                        @endforeach
                    </table>
                    @if ($g['more'] > 0)<p class="po-sub">…và {{ $g['more'] }} bài nữa @if ($g['campaign'] && $canOpenCampaign)(xem đầy đủ ở "Mở chiến dịch")@endif.</p>@endif
                @endif
            </div>
        </details>
    @endforeach
@endif
