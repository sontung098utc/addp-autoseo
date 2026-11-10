<x-filament-panels::page>
    <style>
        .kb-in{border:1px solid rgba(128,128,128,.5);border-radius:.4rem;padding:.3rem .5rem;max-width:100%}
        .kb-bar{display:flex;gap:.5rem;flex-wrap:wrap;align-items:center;margin-bottom:.6rem}
        .kb-grid{display:grid;gap:.6rem;grid-template-columns:repeat(auto-fit,minmax(16rem,1fr))}
        .kb-prev{display:-webkit-box;-webkit-line-clamp:2;-webkit-box-orient:vertical;overflow:hidden;opacity:.8;font-size:.85em}
        .kb-src{white-space:pre-wrap;max-height:22rem;overflow:auto;font-size:.85em;border:1px solid rgba(128,128,128,.3);border-radius:.4rem;padding:.5rem}
        .kb-menu{position:relative;display:inline-block}
        .kb-menu__p{position:absolute;right:0;z-index:20;min-width:9rem;background:rgb(var(--gray-50));border:1px solid rgba(128,128,128,.4);border-radius:.4rem;padding:.2rem;display:flex;flex-direction:column}
        .dark .kb-menu__p{background:rgb(var(--gray-800))}
        .kb-menu__p button{text-align:left;padding:.3rem .5rem;border-radius:.3rem}
        .kb-menu__p button:hover{background:rgba(128,128,128,.2)}
        .kb-ai{border-left:3px solid rgb(var(--info-500,59 130 246))}
        .kb-act{display:flex;gap:.3rem;align-items:center;justify-content:flex-end}
    </style>
    @php
        $statusTone = ['proposed' => 'warn', 'approved' => 'ok', 'outdated' => 'muted', 'rejected' => 'danger'];
        $scope = fn ($i) => $i->website?->name ?? ($i->website_id ? 'Website #'.$i->website_id : 'Chung');
        $warnCount = count($warn['pairs']) + count($warn['dups']) + $warn['expired']->count();
        $tabs = $manage ? [
            ['key' => 'inbox', 'label' => 'Hộp duyệt', 'count' => $inbox->count() ?: null, 'tone' => $inbox->count() ? 'warn' : null],
            ['key' => 'all', 'label' => 'Tất cả'],
            ['key' => 'sources', 'label' => 'Nguồn', 'count' => $sources->count() ?: null],
            ['key' => 'warn', 'label' => 'Cảnh báo', 'count' => $warnCount ?: null, 'tone' => $warnCount ? 'danger' : null],
            ['key' => 'gaps', 'label' => 'Khoảng trống', 'count' => $gaps->where('status', 'open')->count() ?: null, 'tone' => $gaps->where('status', 'open')->count() ? 'warn' : null],
            ['key' => 'add', 'label' => 'Thêm kiến thức'],
        ] : [['key' => 'all', 'label' => 'Kiến thức đã duyệt']];
        if ($brain) { // AG-B1: trang một Trí tuệ — thêm tab Hồ sơ lĩnh vực (đầu) và Cài đặt (cuối); 'Tất cả' đổi tên thành 'Hiểu biết'
            $tabs = array_map(fn ($t) => $t['key'] === 'all' ? ['key' => 'all', 'label' => 'Hiểu biết'] : $t, $tabs);
            array_unshift($tabs, ['key' => 'profile', 'label' => 'Hồ sơ lĩnh vực', 'count' => $completeness[0].'/'.$completeness[1], 'tone' => $completeness[0] < 3 ? 'warn' : null]);
            if ($manage) { $tabs[] = ['key' => 'settings', 'label' => 'Cài đặt']; }
        }
        $topicOptions = fn () => $topics;
    @endphp
    <div class="ss-page">
        @if ($brain)
            @include('filament.pages.partials.brain-header')
        @endif
        @if ($selected)
            <div class="ss-card" style="margin-bottom:1rem" role="region" aria-label="Chi tiết mục kiến thức"><div class="ss-card__b">
                <div class="kb-bar">
                    <div class="ss-card__h" style="margin:0">KB#{{ $selected->id }} · {{ $selected->title }}</div>
                    <x-studio.badge :tone="$statusTone[$selected->status] ?? 'muted'">{{ $statuses[$selected->status] ?? $selected->status }}</x-studio.badge>
                    <span class="ss-muted ss-text-xs">v{{ $selected->version }} · {{ $types[$selected->type] ?? $selected->type }} · {{ $scope($selected) }}</span>
                    <span style="flex:1"></span>
                    @if ($manage && ! $editing)
                        <x-filament::button size="sm" wire:click="startEdit">Sửa</x-filament::button>
                    @endif
                    <x-filament::button size="sm" color="gray" wire:click="closeItem">Đóng</x-filament::button>
                </div>
                @if ($editing)
                    <div class="kb-grid">
                        <label>Tiêu đề<input class="kb-in" style="width:100%" wire:model="edit.title" maxlength="200"></label>
                        <label>Loại<select class="kb-in" style="width:100%" wire:model="edit.type">@foreach ($types as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>
                        <label>Chủ đề<select class="kb-in" style="width:100%" wire:model="edit.topic_id"><option value="">—</option>@foreach ($topics as $t)<option value="{{ $t->id }}">{{ $t->fullName() }}</option>@endforeach</select></label>
                        <label>Thực thể <x-studio.hint text="Để máy so sánh: ví dụ 'Gói Cơ bản'. Cùng thực thể + cùng trường mà khác giá trị sẽ bị cờ mâu thuẫn." /><input class="kb-in" style="width:100%" wire:model="edit.entity"></label>
                        <label>Trường<input class="kb-in" style="width:100%" wire:model="edit.field" placeholder="giá"></label>
                        <label>Giá trị<input class="kb-in" style="width:100%" wire:model="edit.value"></label>
                        <label>Đơn vị<input class="kb-in" style="width:100%" wire:model="edit.value_unit"></label>
                        <label>Thẻ (cách nhau bằng dấu phẩy)<input class="kb-in" style="width:100%" wire:model="edit.tags"></label>
                        <label>Hiệu lực đến<input type="date" class="kb-in" style="width:100%" wire:model="edit.valid_until"></label>
                    </div>
                    <label style="display:block;margin-top:.5rem">Nội dung <x-studio.hint tone="warning" text="Đổi nội dung của mục đã duyệt sẽ tạo phiên bản mới và đưa mục về Đề xuất để duyệt lại." /><textarea class="kb-in" style="width:100%" rows="5" wire:model="edit.body"></textarea></label>
                    <div class="kb-bar" style="margin-top:.5rem">
                        <x-filament::button wire:click="saveEdit">Lưu</x-filament::button>
                        <x-filament::button color="gray" wire:click="$set('editing', false)">Hủy</x-filament::button>
                    </div>
                @else
                    <div class="ss-split">
                        <div>
                            <p style="white-space:pre-wrap">{{ $selected->body }}</p>
                            <p class="ss-muted ss-text-xs">
                                @if ($selected->entity){{ $selected->entity }} · {{ $selected->field }} = {{ $selected->value }} {{ $selected->value_unit }} ·@endif
                                @if ($selected->valid_until) hiệu lực đến {{ $selected->valid_until->format('d/m/Y') }} ·@endif
                                @foreach ($selected->tags ?? [] as $tag)<x-studio.chip>{{ $tag }}</x-studio.chip> @endforeach
                            </p>
                            @if ($manage && $selectedFlags)
                                @foreach ($selectedFlags['conflict'] as $c)
                                    <div class="ss-alert ss-alert--danger" role="alert">Mâu thuẫn với KB#{{ $c->id }} "{{ $c->title }}": {{ $c->value }} {{ $c->value_unit }}. Chọn bản đúng để tiếp tục.</div>
                                @endforeach
                                @foreach ($selectedFlags['exact'] as $d)<div class="ss-alert ss-alert--info">Trùng y hệt KB#{{ $d->id }} "{{ $d->title }}".</div>@endforeach
                                @foreach ($selectedFlags['similar'] as $d)<div class="ss-alert ss-alert--info">Gần giống KB#{{ $d['item']->id }} ({{ $d['percent'] }}%) "{{ $d['item']->title }}".</div>@endforeach
                            @endif
                            <p class="ss-text-sm"><strong>Đã dùng trong {{ $selected->usages->pluck('articleVersion.article_id')->filter()->unique()->count() }} bài</strong>
                                <x-studio.hint text="Mỗi lần Người viết đưa mục này vào prompt, hệ thống ghi bài và phiên bản mục đã dùng." /></p>
                            <ul class="ss-text-sm">
                                @foreach ($selected->usages->take(10) as $u)
                                    @if ($u->articleVersion)
                                        <li><a href="{{ \App\Filament\Resources\ArticleVersionResource::getUrl('view', ['record' => $u->articleVersion->id]) }}">{{ $u->articleVersion->title ?: 'Bài #'.$u->articleVersion->article_id }}</a> · dùng v{{ $u->item_version }}</li>
                                    @endif
                                @endforeach
                            </ul>
                            <div class="ss-text-sm"><strong>Lịch sử phiên bản</strong>
                                <ul>@foreach ($selected->versions as $v)<li>v{{ $v->version }} · {{ $statuses[$v->status] ?? $v->status }} · {{ $v->created_at?->format('d/m/Y H:i') }} · {{ $v->note }}</li>@endforeach</ul>
                            </div>
                            @if ($manage)
                                <div class="kb-bar">
                                    @if ($selected->status !== 'approved')
                                        <x-filament::button size="sm" wire:click="approve({{ $selected->id }})">Duyệt</x-filament::button>
                                    @endif
                                    @if ($selectedFlags && $selectedFlags['conflict']->isNotEmpty())
                                        <x-filament::button size="sm" color="warning" wire:click="pickCorrect({{ $selected->id }})" wire:confirm="Chọn mục này là bản đúng? Các mục mâu thuẫn sẽ bị loại/đánh dấu cũ.">Chọn bản này là đúng</x-filament::button>
                                    @endif
                                    @if ($selected->status !== 'rejected')<x-filament::button size="sm" color="gray" wire:click="reject({{ $selected->id }})">Loại</x-filament::button>@endif
                                    @if ($selected->status === 'approved')<x-filament::button size="sm" color="gray" wire:click="outdate({{ $selected->id }})">Đánh dấu cũ</x-filament::button>@endif
                                </div>
                            @endif
                        </div>
                        <div>
                            @if ($selected->source)
                                <div class="ss-text-sm"><strong>Nguồn: {{ $selected->source->title }}</strong>
                                    <x-studio.hint text="Văn bản gốc đã dán. Đoạn của mục này được tô sáng. Nguồn chỉ là dữ liệu tham khảo." /></div>
                                @php
                                    $raw = (string) $selected->source->raw_text;
                                    $needle = $selected->source_span ?: $selected->body;
                                    $pos = $needle !== '' ? mb_strpos($raw, mb_substr($needle, 0, 80)) : false;
                                    $len = $pos === false ? 0 : mb_strlen($needle);
                                @endphp
                                <div class="kb-src">@if ($pos === false){{ mb_substr($raw, 0, 6000) }}@else{{ mb_substr($raw, 0, $pos) }}<mark>{{ mb_substr($raw, $pos, $len) }}</mark>{{ mb_substr($raw, $pos + $len, 6000) }}@endif</div>
                            @else
                                <p class="ss-muted ss-text-sm">Mục nhập tay, không có tài liệu nguồn.</p>
                            @endif
                        </div>
                    </div>
                @endif
            </div></div>
        @endif

        <x-studio.tabs :persist="$brain ? 'tri-tue' : 'kho-kien-thuc'" :default="$brain ? 'profile' : ($manage && $inbox->count() ? 'inbox' : 'all')" :tabs="$tabs">
            @if ($brain)
                @include('filament.pages.partials.brain-panels')
            @endif
            @if ($manage)
            <x-studio.panel key="inbox">
                @if ($inbox->isEmpty())
                    <div class="ss-alert ss-alert--info" role="status">Hộp duyệt trống. Dán tài liệu hoặc thêm kiến thức ở tab "Thêm kiến thức".</div>
                @else
                    <div class="kb-bar">
                        <span class="ss-muted ss-text-sm">{{ $inbox->count() }} mục chờ duyệt</span>
                        <x-studio.hint text="Chỉ mục 'Đã duyệt' mới được AI dùng. Mục còn cờ mâu thuẫn không duyệt được cho đến khi chọn bản đúng." />
                    </div>
                    <div class="ss-dt__scroll"><table class="ss-dt__table">
                        <thead><tr><th>Mục</th><th style="width:1%;white-space:nowrap">Cờ</th><th style="width:1%;white-space:nowrap">Nguồn</th><th style="width:1%;white-space:nowrap">Thao tác</th></tr></thead>
                        <tbody>
                        @foreach ($inbox as $i)
                            @php $fl = $inboxFlags[$i->id]; @endphp
                            <tr wire:key="inbox-{{ $i->id }}">
                                <td style="min-width:20rem"@if ($i->created_by === 'ai') class="kb-ai" @endif><strong>{{ $i->title }}</strong>
                                    @if ($i->created_by === 'ai')<x-studio.badge tone="info">AI đề xuất{{ $i->confidence !== null ? ' · '.$i->confidence.'%' : '' }}</x-studio.badge>@endif
                                    <div class="kb-prev">{{ $i->body }}</div>
                                    @if ($i->created_by === 'ai')
                                        <div class="kb-prev" data-source-span><em>Đoạn nguồn:</em> {{ $i->source_span ?: 'AI không trích được đoạn nguyên văn; mở "Xem" để đối chiếu với nguồn.' }}</div>
                                    @endif</td>
                                <td style="width:1%;white-space:nowrap">
                                    @if ($fl['conflict']->isNotEmpty())<x-studio.badge tone="danger">Mâu thuẫn</x-studio.badge>@endif
                                    @if ($fl['exact']->isNotEmpty() || $fl['similar']->isNotEmpty())<x-studio.badge tone="warn">Trùng</x-studio.badge>@endif
                                </td>
                                <td style="width:1%;white-space:nowrap">{{ $i->source?->title ?? 'Nhập tay' }}</td>
                                <td style="width:1%;white-space:nowrap"><div class="kb-act">
                                    @if ($fl['conflict']->isNotEmpty())
                                        <x-filament::button size="xs" color="warning" wire:click="openItem({{ $i->id }})">Giải quyết</x-filament::button>
                                    @else
                                        <x-filament::button size="xs" wire:click="approve({{ $i->id }})">Duyệt</x-filament::button>
                                    @endif
                                    <x-filament::button size="xs" color="gray" wire:click="openItem({{ $i->id }})">Xem</x-filament::button>
                                    <div class="kb-menu" x-data="{o:false}" x-on:click.outside="o=false">
                                        <button type="button" class="ss-dt__btn" aria-label="Thêm thao tác" aria-haspopup="true" x-on:click="o=!o">⋯</button>
                                        <div class="kb-menu__p" x-show="o" x-cloak>
                                            <button type="button" wire:click="openItem({{ $i->id }})" x-on:click="o=false">Sửa / xem nguồn</button>
                                            @foreach ($fl['exact']->concat($fl['similar']->pluck('item')) as $dup)
                                                <button type="button" wire:click="mergeInto({{ $dup->id }}, {{ $i->id }})" wire:confirm="Gộp mục này vào KB#{{ $dup->id }}? Mục này sẽ bị loại.">Gộp vào KB#{{ $dup->id }}</button>
                                            @endforeach
                                            <button type="button" wire:click="reject({{ $i->id }})" x-on:click="o=false">Loại</button>
                                        </div>
                                    </div>
                                </div></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table></div>
                @endif
            </x-studio.panel>
            @endif

            <x-studio.panel key="all">
                <div class="kb-bar">
                    <input type="search" class="kb-in" wire:model.live.debounce.400ms="q" placeholder="Tìm tiêu đề, nội dung, thực thể" aria-label="Tìm kiến thức">
                    <select class="kb-in" wire:model.live="fType" aria-label="Lọc theo loại"><option value="">Loại: tất cả</option>@foreach ($types as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>
                    @if ($manage)<select class="kb-in" wire:model.live="fStatus" aria-label="Lọc theo trạng thái"><option value="">Trạng thái: tất cả</option>@foreach ($statuses as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select>@endif
                    <select class="kb-in" wire:model.live="fTopic" aria-label="Lọc theo chủ đề"><option value="">Chủ đề: tất cả</option>@foreach ($topics as $t)<option value="{{ $t->id }}">{{ $t->fullName() }}</option>@endforeach</select>
                    <select class="kb-in" wire:model.live="fSite" aria-label="Lọc theo website"><option value="">Phạm vi: tất cả</option><option value="general">Chung</option>@foreach ($sites as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select>
                    <input type="text" class="kb-in" wire:model.live.debounce.400ms="fTag" placeholder="Thẻ" aria-label="Lọc theo thẻ" style="width:8rem">
                </div>
                @if ($all->isEmpty())
                    <div class="ss-alert ss-alert--info" role="status">Chưa có mục kiến thức nào khớp.</div>
                @else
                    <div class="ss-dt__scroll"><table class="ss-dt__table">
                        <thead><tr><th>Mục</th><th style="width:1%;white-space:nowrap">Loại</th><th style="width:1%;white-space:nowrap">Phạm vi</th><th style="width:1%;white-space:nowrap">Trạng thái</th><th style="width:1%;white-space:nowrap">Thao tác</th></tr></thead>
                        <tbody>
                        @foreach ($all as $i)
                            <tr wire:key="all-{{ $i->id }}">
                                <td style="min-width:20rem"><strong>{{ $i->title }}</strong> <span class="ss-muted ss-text-xs">KB#{{ $i->id }} · v{{ $i->version }}@if ($i->topic) · {{ $i->topic->name }}@endif</span><div class="kb-prev">{{ $i->body }}</div></td>
                                <td style="width:1%;white-space:nowrap">{{ $types[$i->type] ?? $i->type }}</td>
                                <td style="width:1%;white-space:nowrap">{{ $scope($i) }}</td>
                                <td style="width:1%;white-space:nowrap"><x-studio.badge :tone="$statusTone[$i->status] ?? 'muted'">{{ $statuses[$i->status] ?? $i->status }}</x-studio.badge></td>
                                <td style="width:1%;white-space:nowrap"><div class="kb-act">
                                    <x-filament::button size="xs" color="gray" wire:click="openItem({{ $i->id }})">Xem</x-filament::button>
                                    @if ($manage)
                                    <div class="kb-menu" x-data="{o:false}" x-on:click.outside="o=false">
                                        <button type="button" class="ss-dt__btn" aria-label="Thêm thao tác" aria-haspopup="true" x-on:click="o=!o">⋯</button>
                                        <div class="kb-menu__p" x-show="o" x-cloak>
                                            @if ($i->status !== 'approved')<button type="button" wire:click="approve({{ $i->id }})" x-on:click="o=false">Duyệt</button>@endif
                                            @if ($i->status === 'approved')<button type="button" wire:click="outdate({{ $i->id }})" x-on:click="o=false">Đánh dấu cũ</button>@endif
                                            @if ($i->status !== 'rejected')<button type="button" wire:click="reject({{ $i->id }})" x-on:click="o=false">Loại</button>@endif
                                        </div>
                                    </div>
                                    @endif
                                </div></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table></div>
                    @if ($all->count() >= 200)<p class="ss-muted ss-text-xs">Hiện 200 mục mới nhất; dùng bộ lọc để thu hẹp.</p>@endif
                @endif
            </x-studio.panel>

            @if ($manage)
            <x-studio.panel key="sources">
                @if ($sources->isEmpty())
                    <div class="ss-alert ss-alert--info" role="status">Chưa có nguồn. Dán tài liệu ở tab "Thêm kiến thức".</div>
                @else
                    <div class="ss-dt__scroll"><table class="ss-dt__table">
                        <thead><tr><th>Nguồn</th><th style="width:1%;white-space:nowrap">Phạm vi</th><th style="width:1%;white-space:nowrap">Số mục</th><th style="width:1%;white-space:nowrap">Thao tác</th></tr></thead>
                        <tbody>
                        @foreach ($sources as $s)
                            <tr wire:key="src-{{ $s->id }}">
                                <td style="min-width:20rem"><strong>{{ $s->title }}</strong> <span class="ss-muted ss-text-xs">{{ $s->created_at?->format('d/m/Y') }} · {{ number_format(mb_strlen((string) $s->raw_text), 0, ',', '.') }} ký tự</span>
                                    @php $lg = $s->ai_log[0] ?? null; @endphp
                                    @if ($lg)
                                        <div class="ss-text-xs ss-muted" data-ai-log>
                                            <x-studio.badge tone="info">AI</x-studio.badge>
                                            {{ $lg['proposed'] }} mục đề xuất · {{ number_format($lg['tokens'], 0, ',', '.') }} token · ≈ {{ number_format($lg['cost_vnd'], 0, ',', '.') }} đ · {{ $lg['model'] }}@if ($lg['flag_conflict'] || $lg['flag_similar']) · cờ: {{ $lg['flag_conflict'] }} mâu thuẫn, {{ $lg['flag_similar'] }} gần trùng @endif
                                            <x-studio.hint :text="'Lượt tách bằng AI gần nhất'.(($lg['stopped'] ?? null) ? ' (dừng sớm: '.$lg['stopped'].')' : '').'. Đã loại '.($lg['invalid'] + $lg['injection_dropped']).' mục không hợp lệ, bỏ '.$lg['duplicates'].' mục trùng y hệt.'.(($lg['demo'] ?? false) ? ' AI giả lập, không phải AI thật.' : '')" />
                                        </div>
                                    @endif</td>
                                <td style="width:1%;white-space:nowrap">{{ $s->website?->name ?? 'Chung' }}</td>
                                <td style="width:1%;white-space:nowrap">{{ $s->items_count }} @if ($s->proposed_count)<x-studio.badge tone="warn">{{ $s->proposed_count }} chờ duyệt</x-studio.badge>@endif</td>
                                <td style="width:1%;white-space:nowrap"><div class="kb-act">
                                    @if ($s->proposed_count)
                                        <x-filament::button size="xs" wire:click="approveSource({{ $s->id }})" wire:confirm="Duyệt tất cả mục chờ duyệt của nguồn này? Mục còn mâu thuẫn sẽ được bỏ qua.">Duyệt cả nguồn</x-filament::button>
                                    @else
                                        <x-filament::button size="xs" color="gray" wire:click="splitSource({{ $s->id }})">Tách thành mục</x-filament::button>
                                    @endif
                                    @if ($aiAvail['ok'])
                                        <x-filament::button size="xs" color="info" wire:click="splitSourceAi({{ $s->id }})" wire:loading.attr="disabled" wire:target="splitSourceAi({{ $s->id }})" wire:confirm="Gửi nguồn này cho AI để đề xuất các mục? Mục chỉ vào Hộp duyệt ở trạng thái Đề xuất; có thể tốn chi phí AI.">Tách bằng AI</x-filament::button>
                                    @else
                                        <x-filament::button size="xs" color="gray" disabled>Tách bằng AI</x-filament::button>
                                        <x-studio.hint tone="warning" :text="$aiAvail['reason'] ?: 'Chưa kết nối AI.'" />
                                    @endif
                                    <div class="kb-menu" x-data="{o:false}" x-on:click.outside="o=false">
                                        <button type="button" class="ss-dt__btn" aria-label="Thêm thao tác" aria-haspopup="true" x-on:click="o=!o">⋯</button>
                                        <div class="kb-menu__p" x-show="o" x-cloak>
                                            <button type="button" wire:click="splitSource({{ $s->id }})" x-on:click="o=false">Tách lại thành mục <x-studio.hint text="Mục trùng y hệt mục đã có sẽ được bỏ qua." /></button>
                                        </div>
                                    </div>
                                </div></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table></div>
                @endif
            </x-studio.panel>

            <x-studio.panel key="warn">
                @if ($warnCount === 0 && $warn['unused']->isEmpty())
                    <div class="ss-alert ss-alert--ok" role="status">Không có cảnh báo.</div>
                @endif
                @if ($warn['pairs'])
                    <div class="ss-card__h">Mâu thuẫn <x-studio.hint tone="warning" text="Cùng thực thể + cùng trường nhưng khác giá trị. Phải chọn bản đúng; bản còn lại bị loại/đánh dấu cũ." /></div>
                    @foreach ($warn['pairs'] as $p)
                        <div class="ss-card" style="margin-bottom:.5rem" wire:key="pair-{{ $p['a']->id }}-{{ $p['b']->id }}"><div class="ss-card__b kb-grid">
                            @foreach ([$p['a'], $p['b']] as $x)
                                <div><strong>KB#{{ $x->id }} · {{ $x->title }}</strong>
                                    <div class="ss-text-sm">{{ $x->entity }} · {{ $x->field }} = <strong>{{ $x->value }} {{ $x->value_unit }}</strong></div>
                                    <x-studio.badge :tone="$statusTone[$x->status] ?? 'muted'">{{ $statuses[$x->status] ?? $x->status }}</x-studio.badge>
                                    <div class="kb-act" style="justify-content:flex-start;margin-top:.3rem">
                                        <x-filament::button size="xs" wire:click="pickCorrect({{ $x->id }})" wire:confirm="Chọn KB#{{ $x->id }} là bản đúng?">Chọn bản này</x-filament::button>
                                        <x-filament::button size="xs" color="gray" wire:click="openItem({{ $x->id }})">Xem</x-filament::button>
                                    </div></div>
                            @endforeach
                        </div></div>
                    @endforeach
                @endif
                @if ($warn['dups'])
                    <div class="ss-card__h">Trùng / gần giống</div>
                    <ul class="ss-text-sm">@foreach ($warn['dups'] as $d)<li wire:key="dup-{{ $d['a']->id }}-{{ $d['b']->id }}">KB#{{ $d['a']->id }} "{{ $d['a']->title }}" ≈ KB#{{ $d['b']->id }} "{{ $d['b']->title }}" ({{ $d['percent'] }}%)
                        <x-filament::button size="xs" color="gray" wire:click="mergeInto({{ $d['a']->id }}, {{ $d['b']->id }})" wire:confirm="Gộp KB#{{ $d['b']->id }} vào KB#{{ $d['a']->id }}?">Gộp</x-filament::button></li>@endforeach</ul>
                @endif
                @if ($warn['expired']->isNotEmpty())
                    <div class="ss-card__h">Quá hiệu lực <x-studio.hint text="Mục đã duyệt nhưng quá 'Hiệu lực đến'. Truy xuất tự bỏ qua; nên cập nhật hoặc đánh dấu cũ." /></div>
                    <ul class="ss-text-sm">@foreach ($warn['expired'] as $x)<li wire:key="exp-{{ $x->id }}">KB#{{ $x->id }} "{{ $x->title }}" hết hạn {{ $x->valid_until?->format('d/m/Y') }}
                        <x-filament::button size="xs" color="gray" wire:click="outdate({{ $x->id }})">Đánh dấu cũ</x-filament::button></li>@endforeach</ul>
                @endif
                @if ($warn['unused']->isNotEmpty())
                    <div class="ss-card__h">Lâu không dùng <x-studio.hint text="Đã duyệt nhưng chưa được Người viết dùng trong nhiều tháng; xem lại còn cần không." /></div>
                    <ul class="ss-text-sm">@foreach ($warn['unused']->take(30) as $x)<li wire:key="unused-{{ $x->id }}">KB#{{ $x->id }} "{{ $x->title }}"</li>@endforeach</ul>
                @endif
            </x-studio.panel>

            <x-studio.panel key="gaps">
                @if ($gaps->isEmpty())
                    <div class="ss-alert ss-alert--ok" role="status">Chưa có khoảng trống: Người viết tìm thấy kiến thức đã duyệt cho các bài đã chạy.</div>
                @else
                    <div class="kb-bar">
                        <span class="ss-muted ss-text-sm">{{ $gaps->where('status', 'open')->count() }} chỗ còn thiếu</span>
                        <x-studio.hint text="Người viết đã tìm trong kho nhưng không thấy mục đã duyệt nào cho từ khóa của bài. Thêm kiến thức rồi duyệt: khoảng trống tự chuyển sang 'Đã bổ sung' khi mục đã duyệt khớp truy vấn." />
                    </div>
                    <div class="ss-dt__scroll"><table class="ss-dt__table">
                        <thead><tr><th>Truy vấn</th><th style="width:1%;white-space:nowrap">Website</th><th>Bài</th><th style="width:1%;white-space:nowrap">Số lần</th><th style="width:1%;white-space:nowrap">Trạng thái</th><th style="width:1%;white-space:nowrap">Thao tác</th></tr></thead>
                        <tbody>
                        @foreach ($gaps as $g)
                            <tr wire:key="gap-{{ $g->id }}">
                                <td style="min-width:14rem"><strong>{{ $g->query }}</strong></td>
                                <td style="width:1%;white-space:nowrap">{{ $g->website?->name ?? '—' }}</td>
                                <td>{{ $g->article ? ($g->article->title ?: $g->article->primary_keyword) : '—' }}</td>
                                <td style="width:1%;white-space:nowrap">{{ $g->count }}</td>
                                <td style="width:1%;white-space:nowrap">
                                    @if ($g->status === 'filled')
                                        <x-studio.badge tone="ok">Đã bổ sung</x-studio.badge>
                                        @if ($g->filled_item_id)<span class="ss-muted ss-text-xs">KB#{{ $g->filled_item_id }}</span>@endif
                                    @else
                                        <x-studio.badge tone="warn">Còn thiếu</x-studio.badge>
                                    @endif
                                </td>
                                <td style="width:1%;white-space:nowrap"><div class="kb-act">
                                    @if ($g->status !== 'filled')
                                        <x-filament::button size="xs" wire:click="prefillFromGap({{ $g->id }})" x-on:click="set('add')">Thêm kiến thức</x-filament::button>
                                    @endif
                                </div></td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table></div>
                @endif
            </x-studio.panel>

            <x-studio.panel key="add">
                <div class="ss-split">
                    <div class="ss-card"><div class="ss-card__b">
                        <div class="ss-card__h">Dán tài liệu <x-studio.hint text="Hệ thống lưu nguồn rồi tách theo đoạn trống và dòng tiêu đề thành các mục 'Đề xuất' (quy tắc cố định, không dùng AI). Bạn duyệt từng mục sau." /></div>
                        <div class="kb-bar">
                            <input class="kb-in" wire:model="paste.title" placeholder="Tên tài liệu" aria-label="Tên tài liệu" maxlength="200">
                            <select class="kb-in" wire:model="paste.website_id" aria-label="Website áp dụng"><option value="">Chung (mọi website)</option>@foreach ($sites as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select>
                        </div>
                        <textarea class="kb-in" style="width:100%" rows="9" wire:model="paste.text" placeholder="Dán văn bản: bảng giá, chính sách, quy trình, FAQ…" aria-label="Nội dung tài liệu"></textarea>
                        <div class="kb-bar" style="margin-top:.5rem">
                            <label><input type="checkbox" wire:model="paste.split"> Tách thành mục ngay</label>
                            <x-filament::button wire:click="pasteDoc">Lưu nguồn</x-filament::button>
                        </div>
                    </div></div>
                    <div class="ss-card"><div class="ss-card__b">
                        <div class="ss-card__h">Thêm 1 mục kiến thức</div>
                        <div class="kb-grid">
                            <label>Tiêu đề<input class="kb-in" style="width:100%" wire:model="new.title" maxlength="200"></label>
                            <label>Loại<select class="kb-in" style="width:100%" wire:model="new.type">@foreach ($types as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>
                            <label>Website<select class="kb-in" style="width:100%" wire:model="new.website_id"><option value="">Chung</option>@foreach ($sites as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</select></label>
                            <label>Chủ đề<select class="kb-in" style="width:100%" wire:model="new.topic_id"><option value="">—</option>@foreach ($topics as $t)<option value="{{ $t->id }}">{{ $t->fullName() }}</option>@endforeach</select></label>
                            <label>Thực thể <x-studio.hint text="Tùy chọn. Ví dụ 'Gói Cơ bản' + trường 'giá' + giá trị '8000000' giúp máy phát hiện mâu thuẫn." /><input class="kb-in" style="width:100%" wire:model="new.entity"></label>
                            <label>Trường<input class="kb-in" style="width:100%" wire:model="new.field"></label>
                            <label>Giá trị<input class="kb-in" style="width:100%" wire:model="new.value"></label>
                            <label>Đơn vị<input class="kb-in" style="width:100%" wire:model="new.value_unit"></label>
                            <label>Thẻ<input class="kb-in" style="width:100%" wire:model="new.tags" placeholder="giá, học phí"></label>
                            <label>Hiệu lực đến<input type="date" class="kb-in" style="width:100%" wire:model="new.valid_until"></label>
                        </div>
                        <label style="display:block;margin-top:.5rem">Nội dung<textarea class="kb-in" style="width:100%" rows="4" wire:model="new.body"></textarea></label>
                        <div class="kb-bar" style="margin-top:.5rem"><x-filament::button wire:click="addItem">Thêm vào Hộp duyệt</x-filament::button></div>
                        <hr style="margin:.75rem 0;opacity:.3">
                        <div class="kb-bar">
                            <input class="kb-in" wire:model="topicName" placeholder="Chủ đề mới" aria-label="Tên chủ đề mới" maxlength="120">
                            <select class="kb-in" wire:model="topicParent" aria-label="Chủ đề cha"><option value="">Cấp 1</option>@foreach ($topics->whereNull('parent_id') as $t)<option value="{{ $t->id }}">Con của: {{ $t->name }}</option>@endforeach</select>
                            <x-filament::button size="sm" color="gray" wire:click="addTopic">Thêm chủ đề</x-filament::button>
                            <x-studio.hint text="Chủ đề có tối đa 2 cấp." />
                        </div>
                    </div></div>
                </div>
            </x-studio.panel>
            @endif
        </x-studio.tabs>
    </div>
</x-filament-panels::page>
