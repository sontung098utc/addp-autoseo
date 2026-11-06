@php
    use App\Filament\Support\Schedule\VnTime;
    use App\Filament\Support\StatusPresenter;
    $intentLabel = \App\Filament\Support\Keywords\KeywordIntent::LABELS;
    $canCreate = $detail && count($detail['cov']['free']) > 0 && $site;
@endphp
<x-filament-panels::page>
    <style>
        .cp-box{border:1px solid rgba(128,128,128,.3);border-radius:.6rem;padding:1rem}
        .cp-h{font-weight:600;margin-bottom:.5rem}
        .cp-muted{opacity:.75;font-size:.8rem}
        .cp-top{display:grid;grid-template-columns:minmax(0,1fr) minmax(0,2fr);gap:1rem;margin-bottom:1rem}
        @media (max-width:1024px){.cp-top{grid-template-columns:1fr}}
        .cp-cl{list-style:none;margin:0;padding:0;display:grid;gap:.4rem}
        .cp-cl button{width:100%;text-align:left;border:1px solid rgba(128,128,128,.3);border-radius:.5rem;padding:.5rem .6rem;background:transparent;color:inherit}
        .cp-cl button[aria-current=true]{border-color:rgb(var(--primary-600));background:rgba(var(--primary-600),.08)}
        .cp-meter{height:6px;border-radius:3px;background:rgba(128,128,128,.25);margin:.3rem 0;overflow:hidden}
        .cp-meter>span{display:block;height:100%;background:rgb(var(--primary-600))}
        .cp-warn{color:rgb(var(--warning-600));font-size:.75rem}
        .cp-banner{border:1px solid rgb(var(--warning-600));border-left-width:4px;border-radius:.5rem;padding:.6rem .8rem;margin-bottom:1rem;font-size:.85rem}
        .cp-tabs{display:flex;gap:.25rem;margin-bottom:.6rem}
        .cp-tabs button{border:0;border-bottom:2px solid transparent;background:transparent;color:inherit;padding:.3rem .6rem}
        .cp-tabs button[aria-selected=true]{border-color:rgb(var(--primary-600));font-weight:600}
        .cp-list{list-style:none;margin:0;padding:0}
        .cp-list li{display:flex;justify-content:space-between;gap:.5rem;padding:.4rem 0;border-bottom:1px solid rgba(128,128,128,.2);font-size:.85rem}
        .cp-list li.is-conflict{background:rgba(var(--warning-600),.08)}
        .cp-badge{display:inline-block;border:1px solid rgba(128,128,128,.5);border-radius:.8rem;padding:0 .5rem;font-size:.72rem;white-space:nowrap}
        .cp-week{display:grid;grid-template-columns:repeat(7,minmax(0,1fr));gap:.4rem}
        @media (max-width:1024px){.cp-week{grid-template-columns:repeat(2,minmax(0,1fr))}}
        .cp-day{border:1px solid rgba(128,128,128,.25);border-radius:.6rem;padding:.4rem;min-height:9rem}
        .cp-day.is-today{border-color:rgb(var(--primary-600));box-shadow:0 0 0 2px rgba(var(--primary-600),.25)}
        .cp-day.is-past{opacity:.55}
        .cp-day__head{display:flex;justify-content:space-between;font-weight:600;font-size:.8rem;margin-bottom:.3rem}
        .cp-card{border-left:4px solid #9ca3af;border-radius:.4rem;padding:.25rem .4rem;margin-bottom:.3rem;font-size:.75rem;background:rgba(128,128,128,.08)}
        .cp-card--success{border-color:rgb(var(--success-600))}.cp-card--info{border-color:rgb(var(--info-600))}.cp-card--warning{border-color:rgb(var(--warning-600))}.cp-card--danger{border-color:rgb(var(--danger-600))}
        .cp-slot{font-size:.72rem;opacity:.7;border:1px dashed rgba(128,128,128,.4);border-radius:.4rem;padding:.2rem .4rem;margin-bottom:.3rem}
        .cp-link{text-decoration:underline;background:none;border:0;padding:0;color:inherit;font-size:.72rem;cursor:pointer}
        .cp-ai{border:1px dashed rgba(128,128,128,.5);border-radius:.5rem;padding:.7rem;margin-top:.7rem}
        .cp-res{font-size:.8rem;margin:.15rem 0}
        .cp-in{border:1px solid rgba(128,128,128,.5);border-radius:.4rem;padding:.3rem .5rem;background:transparent;color:inherit}
    </style>

    <x-demo-banner source="kho từ khóa/cụm, AI gợi ý bài và cột ngày dự kiến"
        real="bài viết (bảng articles), độ phủ cụm, cảnh báo trùng từ khóa, tạo bài, giới hạn bài/ngày, lịch đăng/đã đăng (bảng publications). Ngày dự kiến của bài 'Đã lên kế hoạch' lưu tạm, sẽ có cột khi Dev1 thêm" />

    <div class="cp-muted" style="margin-bottom:1rem">
        @if ($site)
            <label>Website đích:
                <select class="cp-in" wire:model.live="websiteId" aria-label="Website đích">
                    @foreach ($sites as $s)<option value="{{ $s->id }}" @selected($s->id === $site->id)>{{ $s->name }}</option>@endforeach
                </select>
            </label>
            · {{ $total }} bài trên website · giới hạn <strong>{{ $limit }} bài/ngày</strong> (tạm, tới khi có bảng chiến dịch) · giờ Việt Nam (UTC+7), lưu UTC
        @else
            <strong>Chưa có website đang hoạt động.</strong> Kết nối website ở màn Website trước khi lập kế hoạch.
        @endif
    </div>

    @if ($allConflicts && $site)
        @foreach ($allConflicts as $kwNorm => $ids)
            <div class="cp-banner" role="status" id="cp-conflict">
                <strong>Trùng từ khóa chính "{{ $kwNorm }}": {{ count($ids) }} bài cùng nhắm một từ khóa ("ăn thịt từ khóa").</strong>
                <div class="cp-muted">Mỗi từ khóa chính chỉ nên có một bài: gộp hai bài, hoặc đổi từ khóa chính của một bài trước khi duyệt.</div>
                <ul class="cp-list">
                    @foreach ($ids as $id)
                        @php $a = $byId[$id]; @endphp
                        <li><span>{{ $a['title'] }}</span><span class="cp-badge">{{ StatusPresenter::label($a['status']) }}</span></li>
                    @endforeach
                </ul>
            </div>
        @endforeach
    @endif

    @if ($lastResults)
        <div class="cp-box" style="margin-bottom:1rem" role="status">
            <div class="cp-h">Kết quả tạo bài</div>
            @foreach ($lastResults as $r)
                <p class="cp-res">{{ $r['status'] === 'created' ? '✓' : '✕' }} <strong>{{ $r['keyword'] }}</strong> — {{ $r['message'] }}</p>
            @endforeach
        </div>
    @endif

    <div class="cp-top">
        <section class="cp-box" aria-labelledby="cl-h">
            <div class="cp-h" id="cl-h">Cụm chủ đề</div>
            @if (count($rows) === 0)
                <div role="status">
                    <strong>Chưa có cụm chủ đề.</strong>
                    <p class="cp-muted">Cụm được tạo ở trang <a href="{{ \App\Filament\Pages\KeywordsPage::getUrl() }}" style="text-decoration:underline">Từ khóa</a>: nhập từ khóa, bấm "Gom cụm bằng AI" rồi nhận cụm.</p>
                </div>
            @else
                <ul class="cp-cl">
                    @foreach ($rows as $r)
                        <li wire:key="cl-{{ $loop->index }}"><button type="button" wire:click="selectCluster(@js($r['c']['name']))" aria-current="{{ $detail && $detail['c']['name'] === $r['c']['name'] ? 'true' : 'false' }}">
                            <strong>{{ $r['c']['name'] }}</strong> <span class="cp-muted">{{ count($r['cov']['covered']) }} / {{ count($r['c']['keywords']) }} từ khóa có bài</span>
                            <div class="cp-meter"><span style="width:{{ $r['cov']['percent'] }}%"></span></div>
                            <span class="cp-badge">{{ $intentLabel[$r['c']['intent']] ?? $r['c']['intent'] }}</span> <span class="cp-muted">Phủ {{ $r['cov']['percent'] }}%</span>
                            @if ($r['conflicts'])<span class="cp-warn">· {{ $r['conflicts'] }} từ khóa bị trùng bài</span>@endif
                        </button></li>
                    @endforeach
                </ul>
                <p class="cp-muted" style="margin-top:.5rem">Độ phủ = số từ khóa của cụm đã có bài (tính thật từ từ khóa chính của bài trên website này) / số từ khóa trong cụm.</p>
            @endif
        </section>

        <section class="cp-box" aria-labelledby="det-h">
            @if ($detail)
                <div style="display:flex;justify-content:space-between;gap:.5rem;flex-wrap:wrap;align-items:center">
                    <div><span class="cp-h" id="det-h">{{ $detail['c']['name'] }}</span> <span class="cp-muted">{{ count($detail['cov']['covered']) }} / {{ count($detail['c']['keywords']) }} từ khóa có bài · {{ count($detail['cov']['free']) }} chưa có bài</span></div>
                    <div>
                        {{ $this->createFromClusterAction }}
                        @if (! $canCreate)<span class="cp-muted">Cụm đã đủ bài cho mọi từ khóa — nút bị vô hiệu.</span>@endif
                    </div>
                </div>
                <div class="cp-tabs" role="tablist" aria-label="Chi tiết cụm">
                    <button role="tab" type="button" wire:click="setTab('posts')" aria-selected="{{ $tab === 'posts' ? 'true' : 'false' }}">Bài trong cụm ({{ count($detail['posts']) }})</button>
                    <button role="tab" type="button" wire:click="setTab('kw')" aria-selected="{{ $tab === 'kw' ? 'true' : 'false' }}">Từ khóa chưa có bài ({{ count($detail['cov']['free']) }})</button>
                    <button role="tab" type="button" wire:click="setTab('warn')" aria-selected="{{ $tab === 'warn' ? 'true' : 'false' }}">Cảnh báo ({{ count($detail['conflicts']) }})</button>
                </div>

                @if ($tab === 'posts')
                    <ul class="cp-list">
                        @forelse ($detail['posts'] as $a)
                            @php $dup = isset($detail['conflicts'][$norm($a['keyword'])]); @endphp
                            <li class="{{ $dup ? 'is-conflict' : '' }}" wire:key="post-{{ $a['id'] }}">
                                <div><div>{{ $a['title'] }}</div><div class="cp-muted">{{ $a['keyword'] }}@if ($dup) · <span class="cp-warn">trùng từ khóa chính</span>@endif · {{ $a['at'] ? VnTime::format($a['at']) : 'chưa có ngày' }}</div></div>
                                <div style="text-align:right"><span class="cp-badge">{{ StatusPresenter::label($a['status']) }}</span>
                                    @if ($a['status'] === 'planned')<br><button type="button" class="cp-link" wire:click="mountAction('reschedule', { article: {{ $a['id'] }} })">Đổi ngày</button>
                                    @else<br><span class="cp-muted" title="Xếp lịch đăng làm ở màn Lịch xuất bản">Không đổi ngày ở đây</span>@endif
                                </div>
                            </li>
                        @empty
                            <li><span class="cp-muted">Chưa có bài nào trong cụm này trên website đang chọn.</span></li>
                        @endforelse
                    </ul>
                @elseif ($tab === 'kw')
                    <ul class="cp-list">
                        @forelse (array_slice($detail['cov']['free'], 0, 12) as $k)<li><span>{{ $k }}</span></li>@empty
                            <li><span class="cp-muted">Mọi từ khóa của cụm đã có bài.</span></li>@endforelse
                    </ul>
                    @if (count($detail['cov']['free']) > 12)<p class="cp-muted">Còn {{ count($detail['cov']['free']) - 12 }} từ khóa nữa.</p>@endif
                    <div class="cp-ai">
                        <span class="cp-badge">AI đề xuất (minh họa)</span>
                        <span class="cp-muted">Nguồn: {{ count($detail['cov']['free']) }} từ khóa chưa có bài trong cụm "{{ $detail['c']['name'] }}" · đã loại từ khóa trùng {{ count($detail['cov']['covered']) }} bài hiện có</span>
                        <x-filament::button color="gray" size="sm" wire:click="suggest" :disabled="! $canCreate">Gợi ý bài cho cụm</x-filament::button>
                        @foreach ($suggestions as $s)
                            <label style="display:block;font-size:.85rem;margin-top:.4rem"><input type="checkbox" wire:model="suggestPick" value="{{ $s['keyword'] }}"> {{ $s['title'] }} <span class="cp-muted">— từ khóa chính {{ $s['keyword'] }}</span></label>
                        @endforeach
                        @if ($suggestions)
                            <x-filament::button color="gray" size="sm" wire:click="createSuggested" style="margin-top:.4rem">Tạo các bài đã chọn</x-filament::button>
                        @endif
                        <p class="cp-muted">AI chỉ đề xuất tiêu đề và từ khóa chính. Bài chỉ được tạo khi bạn bấm tạo; chưa viết nội dung, chưa tốn token.</p>
                    </div>
                @else
                    @forelse ($detail['conflicts'] as $kwNorm => $ids)
                        <div class="cp-banner"><strong>Trùng từ khóa chính: {{ $kwNorm }}</strong> ({{ count($ids) }} bài). Hệ thống chặn tạo thêm bài cho từ khóa đã có; hai bài này tồn tại từ trước.</div>
                    @empty
                        <p class="cp-muted">Không có cảnh báo trong cụm này.</p>
                    @endforelse
                @endif
            @else
                <p class="cp-muted">Chọn một cụm bên trái để xem chi tiết.</p>
            @endif
        </section>
    </div>

    <section class="cp-box" aria-labelledby="wk-h">
        <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-bottom:.6rem">
            <span class="cp-h" id="wk-h" style="margin:0">Lịch biên tập tuần</span>
            <span class="cp-muted">{{ $rangeLabel }} · {{ $weekCount }} bài · giờ Việt Nam (UTC+7), lưu UTC</span>
            <x-filament::button color="gray" size="sm" wire:click="previousWeek">Tuần trước</x-filament::button>
            <x-filament::button color="gray" size="sm" wire:click="currentWeek">Tuần này</x-filament::button>
            <x-filament::button color="gray" size="sm" wire:click="nextWeek">Tuần sau</x-filament::button>
        </div>
        @if ($weekCount === 0)<p class="cp-muted" role="status">Tuần này chưa có bài nào. Dùng "Tạo bài từ cụm" kèm ngày dự kiến để xếp vào các ô trống.</p>@endif
        <div class="cp-week">
            @foreach ($days as $d)
                <div class="cp-day {{ $d['isToday'] ? 'is-today' : '' }} {{ $d['isPast'] ? 'is-past' : '' }}" data-day="{{ $d['key'] }}">
                    <div class="cp-day__head"><span>{{ $d['name'] }} {{ $d['label'] }}@if ($d['isToday']) · hôm nay @endif</span><span class="cp-muted" title="Giới hạn {{ $limit }} bài/ngày">{{ $d['count'] }}/{{ $limit }}</span></div>
                    @foreach ($d['items'] as $a)
                        <article class="cp-card cp-card--{{ StatusPresenter::color($a['status']) }}" wire:key="wk-{{ $a['id'] }}">
                            <strong>{{ $a['at']->format('H:i') }}</strong> <span class="cp-badge">{{ StatusPresenter::label($a['status']) }}</span>
                            <div>{{ $a['title'] }}</div>
                            @if ($a['status'] === 'planned' && ! $d['isPast'])<button type="button" class="cp-link" wire:click="mountAction('reschedule', { article: {{ $a['id'] }} })">Đổi ngày</button>@endif
                        </article>
                    @endforeach
                    @if ($d['isPast'])<div class="cp-slot">Ngày đã qua</div>
                    @elseif ($d['full'])<div class="cp-slot">Đã đủ {{ $limit }} bài/ngày — không đặt thêm</div>
                    @else<div class="cp-slot">Còn {{ $limit - $d['count'] }} ô trống</div>@endif
                </div>
            @endforeach
        </div>
        <p class="cp-muted" style="margin-top:.5rem">Chỉ bài "Đã lên kế hoạch" đổi ngày được ở đây (hộp thoại "Đổi ngày"; kéo thả sẽ có ở V1.2). Bài đã duyệt / đã xếp lịch / đã đăng: xếp lịch đăng ở màn Lịch xuất bản.</p>
        @if ($undated)
            <h3 class="cp-h" style="margin-top:.8rem">Chưa có ngày · {{ count($undated) }} bài</h3>
            @foreach ($undated as $a)<div class="cp-res">{{ $a['title'] }} <span class="cp-badge">{{ StatusPresenter::label($a['status']) }}</span></div>@endforeach
        @endif
    </section>

    <details class="cp-box" style="margin-top:1rem">
        <summary class="cp-h">Luật nghiệp vụ của màn này</summary>
        <ul class="cp-muted" style="list-style:disc;padding-left:1.2rem">
            <li>Biên tập viên trở lên (đang hoạt động) mới xem/tạo bài; máy chủ kiểm lại ở mọi thao tác.</li>
            <li>Mỗi từ khóa chính chỉ một bài trên mỗi website (so khớp không phân biệt hoa/thường). Từ khóa đã có bài bị chặn khi tạo và được báo riêng; tạo thành công một phần được phép.</li>
            <li>Hai bài cùng từ khóa chính (đã tồn tại từ trước) hiện cảnh báo "ăn thịt từ khóa".</li>
            <li>Độ phủ cụm = từ khóa của cụm đã có bài / tổng từ khóa của cụm, tính từ bài thật trên website.</li>
            <li>Tối đa {{ $limit }} bài/ngày (hằng số tạm tới khi có bảng chiến dịch). Ngày đủ bài thì không đặt thêm.</li>
            <li>Đổi ngày chỉ cho bài "Đã lên kế hoạch", sang ngày tương lai chưa đủ bài. Bài đã duyệt/đã xếp lịch/đã đăng không đổi ở đây (xếp lịch đăng ở màn Lịch xuất bản).</li>
            <li>Giờ hiển thị theo Việt Nam (UTC+7); lịch đăng thật lưu UTC. Ngày dự kiến của bài "Đã lên kế hoạch" lưu tạm, sẽ có cột khi Dev1 thêm.</li>
            <li>Gợi ý AI chỉ là đề xuất minh họa; chỉ khi bạn bấm tạo mới có bài.</li>
        </ul>
    </details>

    <x-filament-actions::modals />
</x-filament-panels::page>
