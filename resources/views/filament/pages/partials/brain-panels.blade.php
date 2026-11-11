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
    @if ($sampleLabel)
        <div class="ss-muted ss-text-xs" style="margin-bottom:.6rem" data-testid="sample-label">{{ $sampleLabel }}</div>
    @endif
    <form wire:submit="saveProfile">
        {{ $this->form }}
        @if ($canEdit)
            <div style="margin-top:.8rem"><x-filament::button type="submit">Lưu Hồ sơ lĩnh vực</x-filament::button></div>
        @endif
    </form>
</x-studio.panel>

@if ($isBrand)
{{-- AG-B2: Hồ sơ doanh nghiệp của website, sửa chính bảng brand_profiles (mỗi website một bản). Đội AI nhận qua options['brand']; nguồn ghi "Trí tuệ X · Hồ sơ doanh nghiệp". --}}
<x-studio.panel key="brand">
    <div data-testid="brand-profile" class="space-y-4">
        @if ($brandSites->isEmpty())
            <div class="ss-card"><div class="ss-card__b">Trí tuệ này chưa gắn website nào. Gắn một website ở tab Cài đặt để điền hồ sơ doanh nghiệp.</div></div>
        @else
            <div class="ss-card"><div class="ss-card__b" style="display:flex;flex-wrap:wrap;gap:1rem;align-items:end;justify-content:space-between">
                <label style="min-width:16rem;flex:1">
                    <span class="text-sm" style="font-weight:600">Website</span>
                    @if ($brandSites->count() > 1)
                        <x-filament::input.wrapper><x-filament::input.select wire:model.live="brandWebsiteId">@foreach ($brandSites as $s)<option value="{{ $s->id }}">{{ $s->name }}</option>@endforeach</x-filament::input.select></x-filament::input.wrapper>
                    @else
                        <div>{{ $brandSites->first()->name }}</div>
                    @endif
                </label>
                <div style="min-width:16rem;flex:1" data-testid="completeness">
                    <div class="text-sm" style="font-weight:600">
                        Độ đầy đủ hồ sơ: {{ $brandCompleteness['filled'] }}/{{ $brandCompleteness['total'] }} ({{ $brandCompleteness['percent'] }}%)
                        <x-studio.hint text="Hồ sơ này được dùng bởi: Chiến lược (chủ đề có phù hợp website không), Người viết (giọng, thông điệp, claim được phép), Biên tập (từ cấm và tên đối thủ bị luật chặn). Nguồn hiện trong bước viết là: Trí tuệ {{ $brain->name }} · Hồ sơ doanh nghiệp." />
                    </div>
                    <div style="height:.5rem;border-radius:.25rem;background:rgba(128,128,128,.25);overflow:hidden;margin-top:.3rem">
                        <div style="height:100%;width:{{ $brandCompleteness['percent'] }}%;background:{{ $brandCompleteness['percent'] === 100 ? '#16a34a' : '#d97706' }}"></div>
                    </div>
                    @if ($brandCompleteness['missing'])
                        <div class="text-xs mt-1" style="opacity:.75">Còn thiếu: {{ implode(', ', $brandCompleteness['missing']) }}</div>
                    @endif
                </div>
            </div></div>
            <form wire:submit="saveBrand" class="space-y-4">
                {{ $this->brandForm }}
                @if ($canEditBrand)
                    <x-filament::button type="submit">{{ $brandExists ? 'Lưu hồ sơ' : 'Tạo hồ sơ' }}</x-filament::button>
                @else
                    <span class="text-xs" style="opacity:.7">Bạn chỉ có quyền xem hồ sơ này. <x-studio.hint text="Chỉ quản trị viên và người duyệt được sửa hồ sơ doanh nghiệp." /></span>
                @endif
            </form>
        @endif
    </div>
</x-studio.panel>
@endif

@if ($canEdit)
<x-studio.panel key="registry">
    {{-- AG-B3 (P2, Tầng 3): Căn cứ chuẩn mực. Hệ thống KHÔNG tự cho rằng nguồn nào uy tín: gợi ý của mẫu chưa có bậc và chưa được tính; chỉ nguồn do người xác nhận + xếp bậc mới có hiệu lực. --}}
    <div data-testid="source-registry">
        <div class="ss-card" style="margin-bottom:.8rem"><div class="ss-card__b">
            <div class="ss-card__h" style="display:flex;gap:.4rem;align-items:center">Căn cứ chuẩn mực
                <x-studio.hint text="Nguồn quốc tế, pháp lý, khoa học đã được công nhận mà AI được phép trích dẫn. Chuyên gia xếp bậc từng nguồn: Bậc 1 pháp lý, tiêu chuẩn, cơ quan chính thức · Bậc 2 nghiên cứu khoa học bình duyệt · Bậc 3 tạp chí chuyên ngành uy tín · Bậc 4 chỉ để tham khảo (chỉ gợi ý hướng tìm, không dùng để khẳng định) · Chặn. Chỉ nguồn đã xác nhận mới được tính khi kiểm bằng chứng. Hệ thống chưa tìm web; việc kiểm hiện ở mức cả bài, chưa nối từng câu với từng nguồn."/>
            </div>
            @if ($authoritySources->isEmpty())
                <div class="ss-alert ss-alert--info" role="status">Căn cứ chuẩn mực đang trống. @if ($brain->reliance() === 'must_cite') Trí tuệ này đặt mức Bắt buộc nguồn, bài sẽ luôn giữ cho người có chuyên môn duyệt cho tới khi có nguồn đã xác nhận. @endif</div>
            @else
                <div class="ss-dt__scroll"><table class="ss-dt__table">
                    <thead><tr><th>Nguồn</th><th style="width:1%;white-space:nowrap">Bậc</th><th style="width:1%;white-space:nowrap">Trạng thái</th>@if ($canEdit)<th style="width:1%;white-space:nowrap"></th>@endif</tr></thead>
                    <tbody>
                    @foreach ($authoritySources as $src)
                        <tr data-source="{{ $src->id }}">
                            <td style="min-width:14rem">{{ $src->name }}<div class="ss-muted ss-text-xs">{{ $src->domain }}@if ($src->note) · {{ $src->note }}@endif</div>
                                @if ($src->status === 'confirmed' && $src->confirmed_at)<div class="ss-muted ss-text-xs" data-testid="confirmed-by">Xác nhận bởi {{ $confirmers[$src->confirmed_by] ?? 'người dùng đã xóa' }}, {{ $src->confirmed_at->format('d/m/Y') }}</div>@endif</td>
                            <td style="width:1%;white-space:nowrap">
                                @if ($canEdit)
                                    {{-- Bậc 1–2 là mức tin cậy cao nhất: hỏi lại trước khi áp dụng, hủy thì trả ô chọn về giá trị cũ --}}
                                    <select class="kb-in" aria-label="Bậc của {{ $src->name }}" x-data
                                        x-on:change="if (['1','2'].includes($event.target.value) && ! confirm('Xác nhận nguồn này ở bậc ' + $event.target.value + '? Nguồn bậc cao được tính là bằng chứng chính khi kiểm bài.')) { $event.target.value = @js((string) $src->tier); return }; $wire.setSourceTier({{ $src->id }}, $event.target.value)">
                                        <option value="">Chọn bậc…</option>
                                        @foreach ($tiers as $k => $l)<option value="{{ $k }}" @selected((string) $src->tier === (string) $k)>{{ $l }}</option>@endforeach
                                    </select>
                                @else
                                    {{ $src->tier ? ($tiers[$src->tier] ?? '') : '—' }}
                                @endif
                            </td>
                            <td style="width:1%;white-space:nowrap"><x-studio.badge tone="{{ $src->isEffective() ? ($src->tier === 'blocked' ? 'danger' : 'ok') : 'warn' }}">{{ $src->isEffective() ? ($src->tier === 'blocked' ? 'Đã chặn' : 'Đã xác nhận') : 'Chưa xác nhận, chưa được tính' }}</x-studio.badge></td>
                            @if ($canEdit)
                                <td style="width:1%;white-space:nowrap">
                                    @if ($src->status === 'confirmed')<x-filament::button size="xs" color="gray" wire:click="unconfirmSource({{ $src->id }})">Rút xác nhận</x-filament::button>@endif
                                    <x-filament::button size="xs" color="gray" outlined wire:click="removeSource({{ $src->id }})" wire:confirm="Xóa nguồn này khỏi sổ?">Xóa</x-filament::button>
                                </td>
                            @endif
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            @endif
        </div></div>
        @if ($canEdit)
            <div class="ss-card"><div class="ss-card__b">
                <div class="ss-card__h">Thêm nguồn</div>
                <div class="kb-grid">
                    <label>Tên nguồn <input class="kb-in" style="width:100%" maxlength="190" wire:model="srcName" placeholder="Tên tổ chức hoặc ấn phẩm"></label>
                    <label>Tên miền <input class="kb-in" style="width:100%" maxlength="190" wire:model="srcDomain" placeholder="ví dụ: who.int"></label>
                    <label>Bậc <x-studio.hint text="Để trống nếu chỉ muốn lưu gợi ý; có bậc thì nguồn được xác nhận ngay."/>
                        <select class="kb-in" style="width:100%" wire:model="srcTier"><option value="">Chưa xếp bậc</option>@foreach ($tiers as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>
                    <label>Ghi chú <input class="kb-in" style="width:100%" maxlength="500" wire:model="srcNote"></label>
                </div>
                <div style="margin-top:.6rem"><x-filament::button wire:click="addSource">Thêm vào sổ</x-filament::button></div>
            </div></div>
        @endif
    </div>
</x-studio.panel>

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
