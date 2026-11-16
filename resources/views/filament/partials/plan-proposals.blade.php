@php
    $sourceUrl = [
        'keyword' => \App\Filament\Pages\KeywordsPage::getUrl(),
        'competitor' => \App\Filament\Pages\CompetitorsPage::getUrl(),
        'audit' => \App\Filament\Pages\AuditPage::getUrl(),
    ];
    $sourceTone = ['keyword' => 'info', 'competitor' => 'accent', 'audit' => 'warn'];
@endphp
<div class="ss-card" style="margin-bottom:.75rem" data-plan-proposals><div class="ss-card__b">
    <div class="ss-card__h">Đề xuất kế hoạch (theo luật)
        <x-help title="Đề xuất lấy từ đâu, xếp thế nào">
            <p>Lấy từ <b>Kho từ khóa</b> (lượt tìm, cụm), <b>khoảng trống đối thủ</b> và đề xuất làm mới từ <b>Audit</b>. Chỉ từ khóa chưa có bài. Không dùng AI, không tốn token.</p>
            <p>Điểm 0–100: lượt tìm tối đa 50, cụm còn ít bài tối đa 30, khoảng trống đối thủ tối đa 20; lệch lĩnh vực bị trừ 30. Bạn duyệt trước khi đội AI viết.</p>
        </x-help>
    </div>
    @forelse ($proposals as $p)
        <div class="ss-text-sm" style="display:flex;gap:.5rem;align-items:flex-start;padding:.35rem 0;border-bottom:1px solid rgba(128,128,128,.2)" wire:key="pp-{{ $p['keyword_id'] }}" data-proposal="{{ $p['keyword_id'] }}">
            <input type="checkbox" id="pp-in-{{ $p['keyword_id'] }}" value="{{ $p['keyword_id'] }}" wire:model="planPick" style="margin-top:.3rem" aria-label="Chọn {{ $p['keyword'] }}">
            <div style="flex:1;min-width:0">
                <label for="pp-in-{{ $p['keyword_id'] }}"><strong>{{ $p['keyword'] }}</strong>
                    <span class="ss-muted">· điểm {{ $p['priority'] }}</span></label>
                <x-help title="Vì sao xếp ở đây"><p>{{ implode('; ', $p['reasons']) }}.</p></x-help>
                <div style="display:flex;gap:.3rem;flex-wrap:wrap;margin-top:.15rem">
                    @foreach ($p['sources'] as $src)
                        <a href="{{ $sourceUrl[$src['type']] ?? '#' }}" data-source="{{ $src['type'] }}" title="Mở trang nguồn" style="text-decoration:none"><x-studio.badge :tone="$sourceTone[$src['type']] ?? 'muted'">{{ $src['label'] }}</x-studio.badge></a>
                    @endforeach
                    @if ($p['off_topic'])<x-studio.badge tone="warn">Có vẻ lệch chủ đề, sẽ không được thêm</x-studio.badge>@endif
                </div>
            </div>
        </div>
    @empty
        <p class="ss-muted ss-text-sm">Không còn từ khóa mới nào chưa có bài.</p>
    @endforelse
    @if (($pendingAudit ?? 0) > 0)
        <p class="ss-text-sm" style="margin-top:.5rem"><a href="{{ $sourceUrl['audit'] }}" data-source="audit" style="text-decoration:none"><x-studio.badge tone="warn">Audit: làm mới · {{ $pendingAudit }} đề xuất đang chờ</x-studio.badge></a>
            <span class="ss-muted">Chấp nhận ở trang Audit thì bài làm mới vào kế hoạch.</span></p>
    @endif
    @if ($proposals)
        <div style="margin-top:.5rem;display:flex;gap:.5rem;align-items:center;flex-wrap:wrap">
            @if ($showTarget)
                <select class="cp-in" wire:model="planTarget" aria-label="Gán vào chiến dịch" data-plan-target>
                    <option value="">Gán vào… (chọn chiến dịch hoặc Bài lẻ)</option>
                    <option value="solo">Bài lẻ — không thuộc chiến dịch</option>
                    @foreach ($targetCampaigns as $c)<option value="{{ $c->id }}">{{ $c->name }}</option>@endforeach
                </select>
            @endif
            <x-filament::button size="sm" wire:click="addProposed">Thêm vào kế hoạch</x-filament::button>
        </div>
    @endif
</div></div>
