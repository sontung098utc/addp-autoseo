<x-filament-panels::page>
<div data-testid="brand-profile" class="space-y-4">
    <x-filament::section>
        <div style="display:flex;flex-wrap:wrap;gap:1rem;align-items:end;justify-content:space-between">
            <label style="min-width:16rem;flex:1">
                <span class="text-sm" style="font-weight:600">Website</span>
                <x-filament::input.wrapper>
                    <x-filament::input.select wire:model.live="websiteId">
                        @foreach ($websites as $id => $name)
                            <option value="{{ $id }}">{{ $name }}</option>
                        @endforeach
                    </x-filament::input.select>
                </x-filament::input.wrapper>
            </label>
            <div style="min-width:16rem;flex:1" data-testid="completeness">
                <div class="text-sm" style="font-weight:600">
                    Độ đầy đủ hồ sơ: {{ $completeness['filled'] }}/{{ $completeness['total'] }} ({{ $completeness['percent'] }}%)
                    <x-studio.hint text="Hồ sơ này được dùng bởi: Chiến lược (chủ đề có phù hợp website không), Người viết (giọng, thông điệp, claim được phép), Biên tập (từ cấm và tên đối thủ bị luật chặn)." />
                </div>
                <div style="height:.5rem;border-radius:.25rem;background:rgba(128,128,128,.25);overflow:hidden;margin-top:.3rem">
                    <div style="height:100%;width:{{ $completeness['percent'] }}%;background:{{ $completeness['percent'] === 100 ? '#16a34a' : '#d97706' }}"></div>
                </div>
                @if ($completeness['missing'])
                    <div class="text-xs mt-1" style="opacity:.75">Còn thiếu: {{ implode(', ', $completeness['missing']) }}</div>
                @endif
            </div>
        </div>
    </x-filament::section>

    @if (! $websiteId)
        <x-filament::section>Chưa có website nào. Hãy thêm website trước.</x-filament::section>
    @else
        <form wire:submit="save" class="space-y-4">
            {{ $this->form }}
            @if ($canEdit)
                <x-filament::button type="submit">{{ $exists ? 'Lưu hồ sơ' : 'Tạo hồ sơ' }}</x-filament::button>
            @else
                <span class="text-xs" style="opacity:.7">Bạn chỉ có quyền xem hồ sơ này. <x-studio.hint text="Chỉ quản trị viên và người duyệt được sửa hồ sơ doanh nghiệp." /></span>
            @endif
        </form>
    @endif
</div>
</x-filament-panels::page>
