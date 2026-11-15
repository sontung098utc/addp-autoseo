@php
    $p = $campaignProposal;
    $tone = ['keyword' => 'info', 'competitor' => 'accent', 'gsc' => 'ok', 'serp' => 'accent', 'autocomplete' => 'muted', 'ai' => 'warn'];
@endphp
<div class="ss-card" style="margin-bottom:.75rem" data-campaign-proposal><div class="ss-card__b">
    <div class="ss-card__h">Đề xuất chiến dịch (trụ + vệ tinh)
        <x-help title="Đề xuất chiến dịch lấy từ đâu">
            <p>Từ chủ đề bạn nhập, hệ thống gom ứng viên từ <b>Kho từ khóa</b>, <b>Search Console</b> (nếu site đã nối thật), <b>Mọi người cũng hỏi / tìm kiếm liên quan / gợi ý tự động</b> (nếu có nguồn SERP). Mỗi gợi ý có nhãn nguồn, lý do bằng số liệu thật và "nếu sai thì thấy gì". Thiếu nguồn nào thì ghi rõ, không điền số giả.</p>
            <p>Chưa tạo gì cho tới khi bạn bấm <b>Tạo chiến dịch</b>. Chiến dịch tạo ra ở trạng thái <b>tạm dừng</b>, bài ở trạng thái <b>Đã lên kế hoạch</b>; đội AI chưa viết.</p>
        </x-help>
    </div>
    <form wire:submit="proposeCampaign" style="display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
        <input class="cp-in" type="text" wire:model="campaignSeed" maxlength="120" placeholder="Chủ đề của chiến dịch, ví dụ: đường huyết" aria-label="Chủ đề chiến dịch" style="min-width:18rem" data-campaign-seed>
        <x-filament::button size="sm" type="submit">Đề xuất chiến dịch</x-filament::button>
    </form>
    @if ($p)
        <div style="margin-top:.75rem" data-campaign-result>
            @foreach ($p['notes'] as $n)<p class="ss-text-xs ss-muted" style="margin:.15rem 0">• {{ $n }}</p>@endforeach
            <div class="ss-text-sm" style="padding:.4rem 0;border-bottom:1px solid rgba(128,128,128,.2)" data-pillar>
                <strong>Bài trụ: {{ $p['pillar']['keyword'] }}</strong> <x-studio.badge tone="info">{{ $p['pillar']['sources'][0]['label'] }}</x-studio.badge>
                <p class="ss-muted ss-text-xs" style="margin:.15rem 0">{{ $p['pillar']['why'] }}</p>
                <p class="ss-text-xs" style="margin:.15rem 0"><b>Nếu sai thì thấy:</b> {{ $p['pillar']['if_wrong'] }}</p>
                @if ($p['pillar']['secondary'])<p class="ss-text-xs ss-muted" style="margin:.15rem 0">Từ khóa phụ cho bài trụ: {{ implode(', ', $p['pillar']['secondary']) }}</p>@endif
            </div>
            @forelse ($p['satellites'] as $s)
                <div class="ss-text-sm" style="display:flex;gap:.5rem;align-items:flex-start;padding:.35rem 0;border-bottom:1px solid rgba(128,128,128,.2)" wire:key="cp-{{ md5($s['keyword']) }}" data-satellite>
                    <input type="checkbox" id="cp-in-{{ md5($s['keyword']) }}" value="{{ $s['keyword'] }}" wire:model="campaignPick" style="margin-top:.3rem" aria-label="Chọn {{ $s['keyword'] }}">
                    <div style="flex:1;min-width:0">
                        <label for="cp-in-{{ md5($s['keyword']) }}"><strong>{{ $s['keyword'] }}</strong> <span class="ss-muted">· điểm {{ $s['score'] }}</span></label>
                        <div style="display:flex;gap:.3rem;flex-wrap:wrap;margin:.1rem 0">@foreach ($s['sources'] as $src)<x-studio.badge :tone="$tone[$src['type']] ?? 'muted'">{{ $src['label'] }}</x-studio.badge>@endforeach</div>
                        <p class="ss-muted ss-text-xs" style="margin:.1rem 0">{{ $s['why'] }}</p>
                        <p class="ss-text-xs" style="margin:.1rem 0"><b>Nếu sai thì thấy:</b> {{ $s['if_wrong'] }}</p>
                    </div>
                </div>
            @empty
                <p class="ss-muted ss-text-sm" style="margin-top:.5rem">Chưa tìm được bài vệ tinh nào cho chủ đề này.</p>
            @endforelse
            @foreach ($p['merged'] as $m)
                <p class="ss-text-xs ss-muted" style="margin:.2rem 0" data-merged>Không viết riêng «{{ $m['keyword'] }}»: {{ $m['why'] }}</p>
            @endforeach
            @if ($p['satellites'])
                <div style="margin-top:.5rem;display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
                    <input class="cp-in" type="text" wire:model="campaignName" maxlength="120" aria-label="Tên chiến dịch" style="min-width:16rem">
                    <x-filament::button size="sm" wire:click="createCampaignFromProposal" data-create-campaign>Tạo chiến dịch (tạm dừng)</x-filament::button>
                    <x-filament::button size="sm" color="gray" wire:click="discardCampaignProposal">Bỏ đề xuất</x-filament::button>
                </div>
            @endif
        </div>
    @endif
</div></div>
