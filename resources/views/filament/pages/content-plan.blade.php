@php
    use App\Filament\Support\Schedule\VnTime;
    use App\Filament\Support\StatusPresenter;
    $canCreate = $detail && count($detail['cov']['free']) > 0 && $site;
    $tones = ['success' => 'ok', 'warning' => 'warn', 'danger' => 'danger', 'info' => 'info', 'gray' => 'muted'];
@endphp
<x-filament-panels::page>
    <style>
        .cp-week{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:.5rem}
        @media (max-width:1024px){.cp-week{grid-template-columns:repeat(2,minmax(0,1fr))}}
        .cp-day{border:1px solid rgba(128,128,128,.25);border-radius:.6rem;padding:.5rem;min-height:10rem}
        .cp-day.is-today{border-color:rgb(var(--primary-600));box-shadow:0 0 0 2px rgba(var(--primary-600),.25)}
        .cp-day.is-past{opacity:.55}
        .cp-day.is-full{border-color:rgb(var(--warning-600))}
        .cp-day__head{display:flex;justify-content:space-between;font-weight:600;margin-bottom:.3rem}
        .cp-card{border-left:4px solid #9ca3af;border-radius:.4rem;padding:.25rem .4rem;margin-bottom:.3rem;background:rgba(128,128,128,.08)}
        .cp-card--success{border-color:rgb(var(--success-600))}.cp-card--info{border-color:rgb(var(--info-600))}.cp-card--warning{border-color:rgb(var(--warning-600))}.cp-card--danger{border-color:rgb(var(--danger-600))}
        .cp-slot{border:1px dashed rgba(128,128,128,.4);border-radius:.4rem;padding:.2rem .4rem;margin-bottom:.3rem}
        .cp-link{text-decoration:underline;background:none;border:0;padding:0;color:inherit;cursor:pointer}
        .cp-list{list-style:none;margin:0;padding:0}
        .cp-list li{display:flex;justify-content:space-between;gap:.5rem;padding:.4rem 0;border-bottom:1px solid rgba(128,128,128,.2)}
        .cp-bar{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-bottom:.75rem}
        .cp-in{border:1px solid rgba(128,128,128,.5);border-radius:.4rem;padding:.3rem .5rem}
    </style>

    <div class="ss-page">
        <div class="cp-bar">
            @if ($site)
                <select class="cp-in" wire:model.live="websiteId" aria-label="Website đích">
                    @foreach ($sites as $s)<option value="{{ $s->id }}" @selected($s->id === $site->id)>{{ $s->name }}</option>@endforeach
                </select>
                <span class="ss-muted ss-text-sm">{{ $total }} bài · tối đa {{ $limit }} bài/ngày · giờ VN</span>
                <span style="flex:1"></span>
                <span @if (! $canCreate) title="Cụm đã có bài cho mọi từ khóa hoặc chưa chọn cụm." @endif>{{ $this->createFromClusterAction }}</span>
            @else
                <div class="ss-alert ss-alert--warn">Chưa có website đang hoạt động. Kết nối website ở màn Website trước.</div>
            @endif
        </div>

        <div class="ss-card" style="margin-bottom:.75rem" data-plan-proposals><div class="ss-card__b">
            <div class="ss-card__h">Đề xuất kế hoạch (theo luật)</div>
            <p class="ss-muted ss-text-sm">Xếp theo luật, không dùng AI: lượt tìm (nếu có), độ phủ cụm, khoảng trống đối thủ. Bạn duyệt kế hoạch trước khi đội AI viết.</p>
            @forelse ($proposals as $p)
                <label class="ss-text-sm" style="display:block;padding:.3rem 0;border-bottom:1px solid rgba(128,128,128,.2)" wire:key="pp-{{ $p['keyword_id'] }}">
                    <input type="checkbox" value="{{ $p['keyword_id'] }}" wire:model="planPick">
                    <strong>{{ $p['keyword'] }}</strong>
                    · cụm: {{ $p['cluster'] ?? 'chưa gom' }}
                    · {{ $p['volume'] !== null ? number_format($p['volume'], 0, ',', '.').' lượt tìm' : 'chưa có số liệu' }}
                    · điểm {{ $p['priority'] }}
                    <span class="ss-muted">({{ implode('; ', $p['reasons']) }})</span>
                    @if ($p['off_topic'])<x-studio.badge tone="warn">Có vẻ lệch chủ đề, sẽ không được thêm</x-studio.badge>@endif
                </label>
            @empty
                <p class="ss-muted ss-text-sm">Không còn từ khóa mới nào chưa có bài.</p>
            @endforelse
            @if ($proposals)
                <div style="margin-top:.5rem"><x-filament::button size="sm" wire:click="addProposed">Thêm vào kế hoạch</x-filament::button></div>
            @endif
        </div></div>

        @if ($lastResults)
            <div class="ss-card" style="margin-bottom:.75rem" role="status"><div class="ss-card__b">
                <div class="ss-card__h">Kết quả tạo bài</div>
                @foreach ($lastResults as $r)
                    <p class="ss-text-sm">{{ $r['status'] === 'created' ? '✓' : '✕' }} <strong>{{ $r['keyword'] }}</strong> — {{ $r['message'] }}</p>
                @endforeach
            </div></div>
        @endif

        <x-studio.tabs persist="ke-hoach-noi-dung" :tabs="[
            ['key' => 'week', 'label' => 'Lịch tuần', 'count' => $weekCount],
            ['key' => 'clusters', 'label' => 'Cụm chủ đề', 'count' => count($rows)],
            ['key' => 'undated', 'label' => 'Chưa có ngày', 'count' => count($undated) ?: null],
            ['key' => 'warn', 'label' => 'Cảnh báo', 'count' => count($allConflicts) ?: null, 'tone' => $allConflicts ? 'danger' : null],
            ['key' => 'rules', 'label' => 'Quy tắc'],
        ]">
            <x-studio.panel key="week">
                <div class="cp-bar">
                    <x-filament::button color="gray" size="sm" wire:click="previousWeek">Tuần trước</x-filament::button>
                    <x-filament::button color="gray" size="sm" wire:click="currentWeek">Tuần này</x-filament::button>
                    <x-filament::button color="gray" size="sm" wire:click="nextWeek">Tuần sau</x-filament::button>
                    <strong class="ss-text-sm">{{ $rangeLabel }}</strong>
                </div>
                <div class="cp-week">
                    @foreach ($days as $d)
                        <div class="cp-day {{ $d['isToday'] ? 'is-today' : '' }} {{ $d['isPast'] ? 'is-past' : '' }} {{ $d['full'] ? 'is-full' : '' }}" data-day="{{ $d['key'] }}">
                            <div class="cp-day__head ss-text-sm"><span>{{ $d['name'] }} {{ $d['label'] }}@if ($d['isToday']) · hôm nay @endif</span>
                                <x-studio.badge :tone="$d['full'] ? 'warn' : 'muted'" title="Giới hạn {{ $limit }} bài/ngày">{{ $d['count'] }}/{{ $limit }}</x-studio.badge></div>
                            @foreach ($d['items'] as $a)
                                <article class="cp-card cp-card--{{ StatusPresenter::color($a['status']) }} ss-text-xs" wire:key="wk-{{ $a['id'] }}">
                                    <strong>{{ $a['at']->format('H:i') }}</strong>
                                    <x-studio.badge :tone="$tones[StatusPresenter::color($a['status'])] ?? 'muted'">{{ StatusPresenter::label($a['status']) }}</x-studio.badge>
                                    <div>{{ $a['title'] }}</div>
                                    @if ($a['status'] === 'planned' && ! $d['isPast'])<button type="button" class="cp-link" wire:click="mountAction('reschedule', { article: {{ $a['id'] }} })">Đổi ngày</button>@endif
                                </article>
                            @endforeach
                            @if ($d['isPast'])<div class="cp-slot ss-muted ss-text-xs">Ngày đã qua</div>
                            @elseif ($d['full'])<div class="cp-slot ss-text-xs">⚠ Đã đủ {{ $limit }} bài</div>
                            @else<div class="cp-slot ss-muted ss-text-xs">Còn {{ $limit - $d['count'] }} ô trống</div>@endif
                        </div>
                    @endforeach
                </div>
            </x-studio.panel>

            <x-studio.panel key="clusters">
                @if (count($rows) === 0)
                    <div class="ss-alert ss-alert--info" role="status">Chưa có cụm chủ đề. Tạo cụm ở trang <a href="{{ \App\Filament\Pages\KeywordsPage::getUrl() }}" style="text-decoration:underline">Từ khóa</a>.</div>
                @else
                    <div class="ss-split">
                        <x-studio.data-table id="plan-clusters" :columns="$clusterColumns" :rows="$clusterRows" :page-size="10" search="Tìm cụm"
                            select-method="selectCluster" :selected-id="$detail['c']['name'] ?? null" />
                        <div class="ss-card" aria-labelledby="det-h"><div class="ss-card__b">
                            @if ($detail)
                                <div class="ss-card__h" id="det-h">{{ $detail['c']['name'] }}</div>
                                <p class="ss-text-sm ss-muted">{{ count($detail['cov']['covered']) }} / {{ count($detail['c']['keywords']) }} từ khóa có bài · {{ count($detail['cov']['free']) }} chưa có bài</p>
                                @if (count($detail['conflicts']))
                                    <div class="ss-alert ss-alert--danger ss-text-sm" style="margin:.5rem 0">⚠ {{ count($detail['conflicts']) }} từ khóa bị trùng bài</div>
                                @endif
                                <div class="ss-card__h" style="margin-top:.75rem">Bài trong cụm ({{ count($detail['posts']) }})</div>
                                <ul class="cp-list ss-text-sm">
                                    @forelse ($detail['posts'] as $a)
                                        @php $dup = isset($detail['conflicts'][$norm($a['keyword'])]); @endphp
                                        <li wire:key="post-{{ $a['id'] }}">
                                            <div><div>{{ $a['title'] }}</div><div class="ss-muted ss-text-xs">{{ $a['keyword'] }} · {{ $a['at'] ? VnTime::format($a['at']) : 'chưa có ngày' }}@if ($dup) · <x-studio.badge tone="danger">Trùng từ khóa</x-studio.badge>@endif</div></div>
                                            <div style="text-align:right"><x-studio.badge :tone="$tones[StatusPresenter::color($a['status'])] ?? 'muted'">{{ StatusPresenter::label($a['status']) }}</x-studio.badge>
                                                @if ($a['status'] === 'planned')<br><button type="button" class="cp-link ss-text-xs" wire:click="mountAction('reschedule', { article: {{ $a['id'] }} })">Đổi ngày</button>@endif
                                            </div>
                                        </li>
                                    @empty
                                        <li><span class="ss-muted">Chưa có bài trong cụm này.</span></li>
                                    @endforelse
                                </ul>
                                <div class="ss-card__h" style="margin-top:.75rem">Từ khóa chưa có bài ({{ count($detail['cov']['free']) }})</div>
                                <ul class="cp-list ss-text-sm">
                                    @forelse (array_slice($detail['cov']['free'], 0, 12) as $k)<li><span>{{ $k }}</span></li>@empty
                                        <li><span class="ss-muted">Mọi từ khóa đã có bài.</span></li>@endforelse
                                </ul>
                                @if (count($detail['cov']['free']) > 12)<p class="ss-muted ss-text-xs">Còn {{ count($detail['cov']['free']) - 12 }} từ khóa nữa.</p>@endif
                                <div style="margin-top:.75rem">
                                    <x-studio.badge tone="accent">AI đề xuất</x-studio.badge>
                                    <x-filament::button color="gray" size="sm" wire:click="suggest" :disabled="! $canCreate">Gợi ý bài cho cụm</x-filament::button>
                                    @if ($suggestions)<div class="ss-muted ss-text-xs" style="margin-top:.3rem">Nguồn: {{ count($detail['cov']['free']) }} từ khóa chưa có bài</div>@endif
                                    @foreach ($suggestions as $s)
                                        <label class="ss-text-sm" style="display:block;margin-top:.4rem"><input type="checkbox" wire:model="suggestPick" value="{{ $s['keyword'] }}"> {{ $s['title'] }} <span class="ss-muted">— {{ $s['keyword'] }}</span></label>
                                    @endforeach
                                    @if ($suggestions)
                                        <x-filament::button color="gray" size="sm" wire:click="createSuggested" style="margin-top:.4rem">Tạo các bài đã chọn</x-filament::button>
                                    @endif
                                </div>
                            @else
                                <p class="ss-muted ss-text-sm">Chọn một cụm trong bảng.</p>
                            @endif
                        </div></div>
                    </div>
                @endif
            </x-studio.panel>

            <x-studio.panel key="undated">
                @if (count($undated) === 0)
                    <div class="ss-alert ss-alert--ok" role="status">Mọi bài đều đã có ngày.</div>
                @else
                    <x-studio.data-table id="plan-undated" :columns="$undatedColumns" :rows="$undatedRows" :page-size="25" search="Tìm bài" />
                @endif
            </x-studio.panel>

            <x-studio.panel key="warn">
                @forelse ($allConflicts as $kwNorm => $ids)
                    <div class="ss-alert ss-alert--danger" role="status" style="margin-bottom:.5rem">
                        <strong>⚠ Ăn thịt từ khóa "{{ $kwNorm }}": {{ count($ids) }} bài</strong>
                        <ul class="cp-list ss-text-sm">
                            @foreach ($ids as $id)
                                @php $a = $byId[$id]; @endphp
                                <li><span>{{ $a['title'] }}</span><x-studio.badge :tone="$tones[StatusPresenter::color($a['status'])] ?? 'muted'">{{ StatusPresenter::label($a['status']) }}</x-studio.badge></li>
                            @endforeach
                        </ul>
                    </div>
                @empty
                    <div class="ss-alert ss-alert--ok">Không có từ khóa nào bị trùng bài.</div>
                @endforelse
            </x-studio.panel>

            <x-studio.panel key="rules">
                <div class="ss-card"><div class="ss-card__b">
                    <ul class="ss-text-sm" style="list-style:disc;padding-left:1.2rem">
                        <li>Biên tập viên trở lên (đang hoạt động) mới xem/tạo bài; máy chủ kiểm lại ở mọi thao tác.</li>
                        <li>Mỗi từ khóa chính chỉ một bài trên mỗi website (không phân biệt hoa/thường). Từ khóa đã có bài bị chặn và báo riêng; tạo thành công một phần được phép.</li>
                        <li>Hai bài cùng từ khóa chính tồn tại từ trước hiện cảnh báo "ăn thịt từ khóa".</li>
                        <li>Độ phủ = từ khóa của cụm đã có bài / tổng từ khóa của cụm: dưới 34% là thấp, 100% là đủ.</li>
                        <li>Tối đa {{ $limit }} bài/ngày; ngày đủ bài thì không đặt thêm.</li>
                        <li>Đổi ngày chỉ cho bài "Đã lên kế hoạch", sang ngày tương lai chưa đủ bài. Bài đã duyệt/xếp lịch/đã đăng xếp lịch ở màn Lịch xuất bản.</li>
                        <li>Giờ hiển thị theo Việt Nam (UTC+7); lịch đăng thật lưu UTC.</li>
                        <li>AI chỉ đề xuất; chỉ khi bạn bấm tạo mới có bài (chưa viết nội dung, chưa tốn token).</li>
                    </ul>
                </div></div>
            </x-studio.panel>
        </x-studio.tabs>
    </div>

    <x-filament-actions::modals />
</x-filament-panels::page>
