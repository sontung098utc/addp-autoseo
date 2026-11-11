<x-filament-panels::page>
    <style>
        .bl-grid{display:grid;gap:.8rem;grid-template-columns:repeat(auto-fill,minmax(16rem,1fr))}
        .bl-card{display:flex;flex-direction:column;gap:.5rem;height:100%}
        .bl-row{display:flex;gap:.4rem;flex-wrap:wrap;align-items:center}
        .bl-in{border:1px solid rgba(128,128,128,.5);border-radius:.4rem;padding:.3rem .5rem;max-width:100%;width:100%}
        .bl-modal{position:fixed;inset:0;z-index:60;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;padding:1rem}
        .bl-modal__b{background:rgb(var(--gray-50));max-width:34rem;width:100%;max-height:90vh;overflow:auto;border-radius:.6rem;padding:1rem}
        .dark .bl-modal__b{background:rgb(var(--gray-900))}
        .bl-kinds{display:grid;gap:.6rem;grid-template-columns:repeat(auto-fit,minmax(14rem,1fr))}
        .bl-kind{display:flex;flex-direction:column;gap:.35rem;align-items:flex-start;text-align:left;padding:.8rem;border:1px solid rgba(128,128,128,.5);border-radius:.5rem;background:transparent;cursor:pointer}
        .bl-kind:hover:not(:disabled),.bl-kind:focus-visible{border-color:rgb(var(--primary-500));outline:2px solid rgb(var(--primary-500))}
        .bl-kind:disabled{opacity:.55;cursor:not-allowed}
    </style>
    <div class="ss-page">
        <div class="bl-row" style="margin-bottom:.8rem">
            <x-studio.hint text="Mỗi Trí tuệ là một lĩnh vực (ví dụ Y khoa, Giáo dục, Thương hiệu) với giọng văn, khung bài, thuật ngữ và quy tắc lập luận riêng. AI vẫn dùng kiến thức của nó và tìm web; kho chỉ định hướng và chốt điều đúng. Một Trí tuệ gắn được nhiều website." />
            @if ($manage && ! $archived)
                <x-filament::button size="sm" wire:click="openCreate" icon="heroicon-o-plus">Tạo trí tuệ</x-filament::button>
            @endif
            @if ($canInstall && ! $archived)
                <x-filament::button size="sm" color="gray" wire:click="openInstall" icon="heroicon-o-arrow-down-tray">Cài các trí tuệ mẫu</x-filament::button>
            @endif
            <input type="search" class="bl-in" style="max-width:14rem" wire:model.live.debounce.300ms="search" placeholder="Tìm theo tên" aria-label="Tìm trí tuệ theo tên">
            <select class="bl-in" style="max-width:11rem" wire:model.live="group" aria-label="Lọc theo nhóm">
                <option value="">Tất cả nhóm</option>
                @foreach ($groups as $gk => $gl)<option value="{{ $gk }}">{{ $gl }}</option>@endforeach
            </select>
            <label class="ss-muted ss-text-xs" style="margin-left:auto"><input type="checkbox" wire:model.live="showArchived"> Xem đã lưu trữ ({{ $archivedCount }})</label>
        </div>

        {{-- Z2: việc đầu tiên khi vào kho: kho đang có gì, thiếu gì (số thật từ các thẻ bên dưới). --}}
        @if (! $archived && $cards->isNotEmpty())
            @php
                $tot = ['items' => $cards->sum('items'), 'proposed' => $cards->sum('proposed'), 'warn' => $cards->sum(fn ($c) => $c['conflicts'] + $c['expired'] + $c['gaps']),
                        'nosrc' => $cards->where('needs_source', true)->count(), 'nosite' => $cards->filter(fn ($c) => $c['sites'] === [])->count()];
            @endphp
            <div class="bl-row" style="margin-bottom:.8rem" data-testid="bl-summary">
                @if ($tot['proposed'])<x-studio.badge tone="warn">{{ $tot['proposed'] }} chờ duyệt</x-studio.badge>@endif
                @if ($tot['warn'])<x-studio.badge tone="danger">{{ $tot['warn'] }} cảnh báo (mâu thuẫn, cũ, khoảng trống)</x-studio.badge>@endif
                @if ($tot['nosrc'])<x-studio.badge tone="warn">{{ $tot['nosrc'] }} chưa có nguồn xác nhận</x-studio.badge>@endif
                @if ($tot['nosite'])<x-studio.badge tone="muted">{{ $tot['nosite'] }} chưa gắn website</x-studio.badge>@endif
                @if ($buildUrl)
                    <x-filament::button size="xs" color="gray" tag="a" :href="$buildUrl" icon="heroicon-o-arrow-down-tray" style="margin-left:auto" data-testid="bl-todo">Việc cần xem và nạp thêm kiến thức</x-filament::button>
                @endif
            </div>
        @endif

        @if ($cards->isEmpty())
            <div class="ss-card"><div class="ss-card__b">{{ $archived ? 'Chưa có Trí tuệ nào được lưu trữ.' : 'Chưa có Trí tuệ nào.' }}</div></div>
        @else
            <div class="bl-grid">
                @foreach ($cards as $c)
                    @php $b = $c['brain']; @endphp
                    <div class="ss-card"><div class="ss-card__b bl-card">
                        <div class="bl-row" style="justify-content:space-between">
                            <a href="{{ \App\Filament\Pages\BrainPage::getUrl(['brain' => $b->id]) }}" style="font-weight:600;font-size:1.05rem">{{ $b->name }}</a>
                            <x-studio.badge tone="info">{{ $reliances[$b->reliance()] ?? $b->reliance() }}</x-studio.badge>
                        </div>
                        <div class="ss-muted ss-text-xs">Mẫu: {{ $c['template'] }} · Hồ sơ {{ $c['filled'] }}/{{ $c['total'] }} trường
                            @if ($c['sample'])<x-studio.hint text="Mẫu: chưa có kiến thức nào được duyệt. Trí tuệ này mới chỉ có khung; chuyên gia cần điền thuật ngữ, nguồn và kiến thức." />@endif</div>
                        @if ($c['brand'])
                            <div class="ss-muted ss-text-xs" data-testid="brand-completeness">Hồ sơ doanh nghiệp {{ $c['brand']['filled'] }}/{{ $c['brand']['total'] }} trường @if (! $c['brand']['has_site'])(chưa gắn website)@endif</div>
                        @endif
                        <div class="bl-row ss-text-xs">
                            <span>{{ $c['sources'] }} tài liệu</span><span>·</span><span>{{ $c['items'] }} mục</span>
                            @if ($c['proposed'])<x-studio.badge tone="warn">{{ $c['proposed'] }} chờ duyệt</x-studio.badge>@endif
                        </div>
                        @if ($c['conflicts'] + $c['expired'] + $c['gaps'] > 0 || $c['needs_source'])
                        <div class="bl-row ss-text-xs">
                            <span>Độ khỏe</span>
                            <x-studio.hint text="Mâu thuẫn: cùng thực thể và trường nhưng khác giá trị. Cũ: mục đã duyệt nhưng quá hiệu lực. Khoảng trống: truy vấn Người viết không tìm thấy kiến thức đã duyệt." />
                            @if ($c['conflicts'] + $c['expired'] + $c['gaps'] > 0 || $c['needs_source'])
                                @if ($c['needs_source'])<x-studio.badge tone="warn">Chưa có nguồn xác nhận</x-studio.badge>@endif
                                @if ($c['conflicts'])<x-studio.badge tone="danger">{{ $c['conflicts'] }} mâu thuẫn</x-studio.badge>@endif
                                @if ($c['expired'])<x-studio.badge tone="warn">{{ $c['expired'] }} cũ</x-studio.badge>@endif
                                @if ($c['gaps'])<x-studio.badge tone="warn">{{ $c['gaps'] }} khoảng trống</x-studio.badge>@endif
                            @endif
                        </div>
                        @endif
                        @if ($c['sites'])<div class="ss-muted ss-text-xs">Website: {{ implode(', ', array_slice($c['sites'], 0, 3)).(count($c['sites']) > 3 ? ' +'.(count($c['sites']) - 3) : '') }}</div>@endif
                        <div style="margin-top:auto"><x-filament::button size="sm" color="gray" tag="a" :href="\App\Filament\Pages\BrainPage::getUrl(['brain' => $b->id])">Mở</x-filament::button></div>
                    </div></div>
                @endforeach
            </div>
        @endif

        @if ($showInstall)
            <div class="bl-modal" role="dialog" aria-modal="true" aria-label="Cài các trí tuệ mẫu" wire:key="install-modal" x-data x-on:keydown.escape.window="$wire.closeInstall()">
                <div class="bl-modal__b">
                    <div class="ss-card__h" style="display:flex;gap:.4rem;align-items:center">Cài các trí tuệ mẫu
                        <x-studio.hint tone="warning" text="Mẫu chỉ mang khung cấu trúc, quy tắc an toàn, tên thuật ngữ (chưa có định nghĩa) và gợi ý nguồn chưa xác nhận. Chưa có kiến thức nào được duyệt; cần chuyên gia điền và duyệt trước khi dùng. Mẫu đã cài thì bỏ qua, không ghi đè bản bạn đã sửa. Không tự gắn website." />
                        <span style="flex:1"></span><x-close-x wire:click="closeInstall" />
                    </div>
                    <form wire:submit="installSelected" style="display:flex;flex-direction:column;gap:.5rem">
                        <div class="bl-row"><x-filament::button type="button" size="xs" color="gray" wire:click="selectNotInstalled">Chọn các mục chưa cài</x-filament::button>
                            <x-filament::button type="button" size="xs" color="gray" x-on:click="$wire.set('installKeys', [])">Bỏ chọn hết</x-filament::button></div>
                        @foreach ($healthTemplates as $k => $n)
                            @php $has = isset($installed[$k]); @endphp
                            <label style="display:block;{{ $has ? 'opacity:.55' : '' }}"><input type="checkbox" wire:model="installKeys" value="{{ $k }}" @disabled($has)> {{ $n }}@if ($has) <span class="ss-muted ss-text-xs">(đã có)</span>@endif</label>
                        @endforeach
                        <div class="bl-row" style="justify-content:flex-end">
                            <x-filament::button type="button" color="gray" wire:click="closeInstall">Hủy</x-filament::button>
                            <x-filament::button type="submit">Cài</x-filament::button>
                        </div>
                    </form>
                </div>
            </div>
        @endif

        @if ($showCreate)
            <div class="bl-modal" role="dialog" aria-modal="true" aria-label="Tạo trí tuệ" wire:key="create-modal" x-data x-on:keydown.escape.window="$wire.closeCreate()">
                <div class="bl-modal__b" style="{{ $createKind === '' ? 'max-width:40rem' : '' }}">
                    <div class="ss-card__h" style="display:flex;gap:.4rem;align-items:center">Tạo trí tuệ
                        @if ($createKind !== '')<span class="ss-muted ss-text-xs">· {{ $kinds[$createKind]['name'] ?? '' }}</span>@endif
                        <span style="flex:1"></span><x-close-x wire:click="closeCreate" />
                    </div>
                    @if ($createKind === '')
                        <p class="ss-muted ss-text-sm" style="margin:0 0 .6rem">Bạn muốn tạo loại Trí tuệ nào?</p>
                        <div class="bl-kinds" data-testid="bl-kinds">
                            @foreach ($kinds as $k)
                                <button type="button" class="bl-kind" wire:click="pickKind('{{ $k['key'] }}')" data-testid="kind-{{ $k['key'] }}" @disabled(! $kindOk[$k['key']])>
                                    <x-filament::icon :icon="$k['icon']" style="width:1.6rem;height:1.6rem" />
                                    <strong>{{ $k['name'] }}</strong>
                                    <span class="ss-muted ss-text-xs">{{ $k['description'] }}@if (! $kindOk[$k['key']]) Cần quyền người duyệt trở lên.@endif</span>
                                </button>
                            @endforeach
                        </div>
                        <div class="bl-row" style="justify-content:flex-end;margin-top:.7rem"><x-filament::button type="button" color="gray" wire:click="closeCreate">Hủy</x-filament::button></div>
                    @else
                    <form wire:submit="createBrain" style="display:flex;flex-direction:column;gap:.7rem">
                        <label>Mẫu <x-studio.hint text="Chọn Trống nếu muốn tự điền từ đầu. Mẫu chỉ mang khung cấu trúc và quy tắc, không mang sự kiện hay số liệu; bạn tự điền thuật ngữ và kiến thức."/>
                            <select class="bl-in" wire:model.live="create.template">@foreach ($templates as $k => $t)<option value="{{ $k }}">{{ $t['name'] }}</option>@endforeach</select></label>
                        @if (isset($templates[$create['template']]))
                            <div class="ss-muted ss-text-xs">{{ $templates[$create['template']]['description'] }} Mức dựa vào AI mặc định: {{ $reliances[$templates[$create['template']]['ai_reliance']] ?? '' }}.</div>
                        @endif
                        <label>Tên (bắt buộc)<input class="bl-in" wire:model="create.name" maxlength="160" placeholder="Ví dụ: Y khoa – sức khỏe" required></label>
                        <fieldset><legend>Website dùng Trí tuệ này <x-studio.hint text="Tùy chọn. Không chọn thì Trí tuệ chưa áp dụng cho website nào; gắn sau ở tab Cài đặt."/></legend>
                            @foreach ($sites as $s)<label style="display:block"><input type="checkbox" wire:model="create.websites" value="{{ $s->id }}"> {{ $s->name }}</label>@endforeach
                            @if ($sites->isEmpty())<span class="ss-muted ss-text-xs">Chưa có website.</span>@endif
                        </fieldset>
                        <div class="bl-row" style="justify-content:flex-end">
                            <x-filament::button type="button" color="gray" wire:click="backToKinds">Chọn loại khác</x-filament::button>
                            <x-filament::button type="submit">Tạo</x-filament::button>
                        </div>
                    </form>
                    @endif
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
