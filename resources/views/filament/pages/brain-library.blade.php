<x-filament-panels::page>
    <style>
        .bl-grid{display:grid;gap:.8rem;grid-template-columns:repeat(auto-fill,minmax(19rem,1fr))}
        .bl-card{display:flex;flex-direction:column;gap:.5rem;height:100%}
        .bl-row{display:flex;gap:.4rem;flex-wrap:wrap;align-items:center}
        .bl-in{border:1px solid rgba(128,128,128,.5);border-radius:.4rem;padding:.3rem .5rem;max-width:100%;width:100%}
        .bl-modal{position:fixed;inset:0;z-index:60;background:rgba(0,0,0,.45);display:flex;align-items:center;justify-content:center;padding:1rem}
        .bl-modal__b{background:rgb(var(--gray-50));max-width:34rem;width:100%;max-height:90vh;overflow:auto;border-radius:.6rem;padding:1rem}
        .dark .bl-modal__b{background:rgb(var(--gray-900))}
    </style>
    <div class="ss-page">
        <div class="bl-row" style="margin-bottom:.8rem">
            <x-studio.hint text="Mỗi Trí tuệ là một lĩnh vực (ví dụ Y khoa, Giáo dục, Thương hiệu) với giọng văn, khung bài, thuật ngữ và quy tắc lập luận riêng. AI vẫn dùng kiến thức của nó và tìm web; kho chỉ định hướng và chốt điều đúng. Một Trí tuệ gắn được nhiều website." />
            @if ($manage && ! $archived)
                <x-filament::button size="sm" wire:click="openCreate" icon="heroicon-o-plus">Tạo trí tuệ</x-filament::button>
            @endif
            <label class="ss-muted ss-text-xs" style="margin-left:auto"><input type="checkbox" wire:model.live="showArchived"> Xem đã lưu trữ ({{ $archivedCount }})</label>
        </div>

        @if ($cards->isEmpty())
            <div class="ss-card"><div class="ss-card__b">{{ $archived ? 'Chưa có Trí tuệ nào được lưu trữ.' : 'Chưa có Trí tuệ nào.' }}@if ($manage && ! $archived) Bấm "Tạo trí tuệ" để bắt đầu từ một mẫu.@endif</div></div>
        @else
            <div class="bl-grid">
                @foreach ($cards as $c)
                    @php $b = $c['brain']; @endphp
                    <div class="ss-card"><div class="ss-card__b bl-card">
                        <div class="bl-row" style="justify-content:space-between">
                            <a href="{{ \App\Filament\Pages\BrainPage::getUrl(['brain' => $b->id]) }}" style="font-weight:600;font-size:1.05rem">{{ $b->name }}</a>
                            <x-studio.badge tone="info">{{ $reliances[$b->reliance()] ?? $b->reliance() }}</x-studio.badge>
                        </div>
                        <div class="ss-muted ss-text-xs">Mẫu: {{ $c['template'] }} · Hồ sơ {{ $c['filled'] }}/{{ $c['total'] }} trường</div>
                        <div class="bl-row ss-text-xs">
                            <span>{{ $c['sources'] }} nguồn</span><span>·</span><span>{{ $c['items'] }} mục</span>
                            @if ($c['proposed'])<x-studio.badge tone="warn">{{ $c['proposed'] }} chờ duyệt</x-studio.badge>@endif
                        </div>
                        <div class="bl-row ss-text-xs">
                            <span>Độ khỏe</span>
                            <x-studio.hint text="Mâu thuẫn: cùng thực thể và trường nhưng khác giá trị. Cũ: mục đã duyệt nhưng quá hiệu lực. Khoảng trống: truy vấn Người viết không tìm thấy kiến thức đã duyệt." />
                            @if ($c['conflicts'] + $c['expired'] + $c['gaps'] === 0)
                                <x-studio.badge tone="ok">Không cảnh báo</x-studio.badge>
                            @else
                                @if ($c['conflicts'])<x-studio.badge tone="danger">{{ $c['conflicts'] }} mâu thuẫn</x-studio.badge>@endif
                                @if ($c['expired'])<x-studio.badge tone="warn">{{ $c['expired'] }} cũ</x-studio.badge>@endif
                                @if ($c['gaps'])<x-studio.badge tone="warn">{{ $c['gaps'] }} khoảng trống</x-studio.badge>@endif
                            @endif
                        </div>
                        <div class="ss-muted ss-text-xs">Website: {{ $c['sites'] ? implode(', ', array_slice($c['sites'], 0, 3)).(count($c['sites']) > 3 ? ' +'.(count($c['sites']) - 3) : '') : 'chưa gắn website nào' }}</div>
                        <div style="margin-top:auto"><x-filament::button size="sm" color="gray" tag="a" :href="\App\Filament\Pages\BrainPage::getUrl(['brain' => $b->id])">Mở</x-filament::button></div>
                    </div></div>
                @endforeach
            </div>
        @endif

        @if ($showCreate)
            <div class="bl-modal" role="dialog" aria-modal="true" aria-label="Tạo trí tuệ" wire:key="create-modal" x-data x-on:keydown.escape.window="$wire.closeCreate()">
                <div class="bl-modal__b">
                    <div class="ss-card__h">Tạo trí tuệ</div>
                    <form wire:submit="createBrain" style="display:flex;flex-direction:column;gap:.7rem">
                        <label>Mẫu <x-studio.hint text="Mẫu chỉ mang khung cấu trúc và quy tắc, không mang sự kiện hay số liệu. Bạn tự điền thuật ngữ và kiến thức."/>
                            <select class="bl-in" wire:model.live="create.template">@foreach ($templates as $k => $t)<option value="{{ $k }}">{{ $t['name'] }}</option>@endforeach</select></label>
                        @if (isset($templates[$create['template']]))
                            <div class="ss-muted ss-text-xs">{{ $templates[$create['template']]['description'] }} Mức dựa vào AI mặc định: {{ $reliances[$templates[$create['template']]['ai_reliance']] ?? '' }}.</div>
                        @endif
                        <label>Tên<input class="bl-in" wire:model="create.name" maxlength="160" placeholder="Ví dụ: Y khoa – sức khỏe" required></label>
                        <fieldset><legend>Website dùng Trí tuệ này</legend>
                            @foreach ($sites as $s)<label style="display:block"><input type="checkbox" wire:model="create.websites" value="{{ $s->id }}"> {{ $s->name }}</label>@endforeach
                            @if ($sites->isEmpty())<span class="ss-muted ss-text-xs">Chưa có website.</span>@endif
                        </fieldset>
                        <div class="bl-row" style="justify-content:flex-end">
                            <x-filament::button type="button" color="gray" wire:click="closeCreate">Hủy</x-filament::button>
                            <x-filament::button type="submit">Tạo</x-filament::button>
                        </div>
                    </form>
                </div>
            </div>
        @endif
    </div>
</x-filament-panels::page>
