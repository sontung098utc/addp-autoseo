{{-- AG-B1: tab Hồ sơ lĩnh vực + Cài đặt của một Trí tuệ (tab kiến thức do knowledge.blade.php vẽ). --}}
<x-studio.panel key="profile">
    <div class="ss-card" style="margin-bottom:.8rem"><div class="ss-card__b" style="display:flex;gap:.8rem;align-items:center;flex-wrap:wrap">
        <strong>Độ đầy đủ: {{ $completeness[0] }}/{{ $completeness[1] }} trường</strong>
        <div style="flex:1;min-width:8rem;max-width:20rem;height:.5rem;border-radius:.25rem;background:rgba(128,128,128,.25);overflow:hidden" role="progressbar" aria-valuemin="0" aria-valuemax="{{ $completeness[1] }}" aria-valuenow="{{ $completeness[0] }}">
            <div style="height:100%;width:{{ $completeness[1] ? round($completeness[0] / $completeness[1] * 100) : 0 }}%;background:rgb(var(--primary-500))"></div>
        </div>
        <x-studio.hint text="Hồ sơ lĩnh vực là HÀNH VI (giọng, khung bài, thuật ngữ, lập luận, bài mẫu), không phải sự kiện. AI luôn có kiến thức riêng; hồ sơ này định hướng cách viết. Hồ sơ càng đầy đủ, bài càng đúng giọng lĩnh vực." />
        @if ($missingFields)
            <span class="ss-muted ss-text-xs">Còn trống: {{ implode(', ', array_slice($missingFields, 0, 4)) }}@if (count($missingFields) > 4)…@endif</span>
        @endif
    </div></div>
    <form wire:submit="saveProfile">
        {{ $this->form }}
        @if ($canEdit)
            <div style="margin-top:.8rem"><x-filament::button type="submit">Lưu Hồ sơ lĩnh vực</x-filament::button></div>
        @endif
    </form>
</x-studio.panel>

@if ($canEdit)
<x-studio.panel key="settings">
    <div class="ss-card"><div class="ss-card__b">
        <div class="kb-grid">
            <label>Mức dựa vào AI <x-studio.hint text="Tự do: kho chỉ định hướng giọng và cấu trúc. Kiểm số liệu: số liệu phải khớp kho. Bắt buộc nguồn: cần người có chuyên môn duyệt và hạng rủi ro của bài tối thiểu mức vừa."/>
                <select class="kb-in" style="width:100%" wire:model="settings.ai_reliance">@foreach ($reliances as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>
            <label>Hạng rủi ro của lĩnh vực <x-studio.hint text="Bài dùng Trí tuệ này có hạng rủi ro tối thiểu bằng mức này. Không bao giờ hạ thấp hạng mà hệ thống đã tính từ nội dung bài."/>
                <select class="kb-in" style="width:100%" wire:model="settings.risk_class">@foreach ($risks as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>
        </div>
        <div class="ss-card__h" style="margin-top:1rem">Website sử dụng <x-studio.hint text="Chiến dịch không chọn Trí tuệ riêng sẽ dùng các Trí tuệ của website, theo thứ tự ưu tiên (số nhỏ dùng trước)."/></div>
        <div class="ss-dt__scroll"><table class="ss-dt__table"><tbody>
            @foreach ($allSites as $s)
                <tr>
                    <td style="min-width:14rem"><label><input type="checkbox" wire:model="siteLinks.{{ $s->id }}.on"> {{ $s->name }}</label></td>
                    <td style="width:1%;white-space:nowrap">Ưu tiên <input type="number" min="1" max="1000" class="kb-in" style="width:5rem" wire:model="siteLinks.{{ $s->id }}.priority" aria-label="Ưu tiên cho {{ $s->name }}"></td>
                </tr>
            @endforeach
        </tbody></table></div>
        <div style="margin-top:.8rem;display:flex;gap:.5rem;flex-wrap:wrap">
            <x-filament::button wire:click="saveSettings">Lưu cài đặt</x-filament::button>
            <x-filament::button color="gray" wire:click="toggleArchive" wire:confirm="{{ $brain->isActive() ? 'Lưu trữ Trí tuệ này? AI sẽ ngừng dùng nó cho đến khi khôi phục.' : 'Khôi phục Trí tuệ này?' }}">{{ $brain->isActive() ? 'Lưu trữ' : 'Khôi phục' }}</x-filament::button>
        </div>
    </div></div>
</x-studio.panel>
@endif
