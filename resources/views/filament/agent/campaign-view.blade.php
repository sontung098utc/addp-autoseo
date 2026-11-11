@php
    use App\Filament\Resources\ArticleResource;
    use App\Filament\Resources\CampaignResource;
    use App\Models\Campaign;
    $active = $c->status === 'active';
    $progress = [
        ['k' => 'planned', 'label' => 'Kế hoạch'],
        ['k' => 'drafting', 'label' => 'Đang viết'],
        ['k' => 'needs_review', 'label' => 'Chờ duyệt'],
        ['k' => 'approved', 'label' => 'Đã duyệt'],
    ];
    $tabs = [
        ['key' => 'articles', 'label' => 'Bài viết', 'count' => $total],
        ['key' => 'plan', 'label' => 'Kế hoạch', 'count' => $planned->count()],
        ['key' => 'config', 'label' => 'Cấu hình'],
    ];
    $originLabels = ['plan' => 'Từ kế hoạch', 'keyword' => 'Từ khóa', 'audit' => 'Audit: làm mới', 'competitor' => 'Đối thủ', 'gsc' => 'Search Console', 'manual' => 'Thêm tay'];
@endphp
<x-filament-panels::page>
    <div class="ss-page" data-campaign-view>
        <div class="ss-card" style="margin-bottom:.75rem"><div class="ss-card__b">
            <div style="display:flex;gap:.5rem;flex-wrap:wrap;align-items:center">
                <x-studio.badge :tone="$active ? 'ok' : 'muted'">{{ $active ? 'Đang chạy' : 'Tạm dừng' }}</x-studio.badge>
                @if ($live > 0)<x-studio.badge tone="info">Đang viết {{ $live }} bài</x-studio.badge>@endif
                <x-studio.badge tone="accent">{{ CampaignResource::MODE_LABELS[$c->mode] ?? $c->mode }}</x-studio.badge>
                <span class="ss-muted ss-text-sm">{{ $c->website?->name }} · hôm nay {{ $today }}/{{ $c->daily_limit }} bài</span>
                <x-help title="Chiến dịch và bài viết">Chiến dịch gom các bài cùng mục tiêu, lịch và quy tắc; bài lẻ là bài không thuộc chiến dịch nào.</x-help>
            </div>
            <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(7rem,1fr));gap:.5rem;margin-top:.75rem" data-progress>
                @foreach ($progress as $p)
                    <div style="border:1px solid rgba(128,128,128,.25);border-radius:.5rem;padding:.4rem .6rem">
                        <div class="ss-muted ss-text-xs">{{ $p['label'] }}</div>
                        <div style="font-size:1.35rem;font-weight:600" data-count="{{ $p['k'] }}">{{ $counts[$p['k']] ?? 0 }}</div>
                    </div>
                @endforeach
            </div>
        </div></div>

        <x-studio.tabs persist="chien-dich-{{ $c->id }}" :tabs="$tabs">
            <x-studio.panel key="articles">
                @if ($articles->isEmpty())
                    <p class="ss-muted ss-text-sm">Chiến dịch chưa có bài. Bấm "Thêm bài" hoặc thêm đề xuất ở tab Kế hoạch.</p>
                @else
                    <ul class="cp-list ss-text-sm" style="list-style:none;margin:0;padding:0">
                        @foreach ($articles as $a)
                            <li wire:key="ca-{{ $a->id }}" style="display:flex;justify-content:space-between;gap:.5rem;padding:.4rem 0;border-bottom:1px solid rgba(128,128,128,.2)">
                                <div><a href="{{ ArticleResource::getUrl('view', ['record' => $a]) }}" style="text-decoration:underline"><strong>{{ $a->latestVersion?->title ?: $a->primary_keyword }}</strong></a>
                                    <div class="ss-muted ss-text-xs">{{ $a->primary_keyword }}</div></div>
                                <x-studio.chip domain="article" :value="$a->workflow_status" />
                            </li>
                        @endforeach
                    </ul>
                @endif
            </x-studio.panel>

            <x-studio.panel key="plan">
                @if ($planned->isNotEmpty())
                    <ul class="ss-text-sm" style="list-style:none;margin:0 0 .75rem;padding:0" data-planned-list>
                        @foreach ($planned as $a)
                            <li wire:key="cp-{{ $a->id }}" style="display:flex;justify-content:space-between;gap:.5rem;padding:.35rem 0;border-bottom:1px solid rgba(128,128,128,.2)">
                                <span><strong>{{ $a->primary_keyword }}</strong> <span class="ss-muted ss-text-xs">· ưu tiên {{ $a->priority ?? '—' }}</span></span>
                                <x-studio.badge tone="muted">{{ $originLabels[$a->origin] ?? 'Chưa rõ nguồn' }}</x-studio.badge>
                            </li>
                        @endforeach
                    </ul>
                @else
                    <p class="ss-muted ss-text-sm" style="margin-bottom:.75rem">Kế hoạch của chiến dịch đang trống.</p>
                @endif
                @include('filament.partials.plan-proposals', ['proposals' => $proposals, 'pendingAudit' => $pendingAudit, 'showTarget' => false, 'targetCampaigns' => collect()])
            </x-studio.panel>

            <x-studio.panel key="config">
                <dl class="ss-text-sm" style="display:grid;grid-template-columns:max-content 1fr;gap:.35rem 1rem" data-config>
                    <dt class="ss-muted">Website</dt><dd>{{ $c->website?->name }}</dd>
                    <dt class="ss-muted">Nguồn từ khóa</dt><dd>{{ $c->source === 'plan' ? 'Kế hoạch nội dung (hết thì dùng danh sách nhập tay)' : 'Danh sách nhập tay' }} · còn {{ $left }} từ khóa nhập tay</dd>
                    <dt class="ss-muted">Chế độ</dt><dd>{{ CampaignResource::MODE_LABELS[$c->mode] ?? $c->mode }}</dd>
                    <dt class="ss-muted">Hạn mức</dt><dd>{{ $c->daily_limit }} bài/ngày · tối đa {{ number_format((int) $c->daily_cost_cap_vnd, 0, ',', '.') }} đ/ngày · {{ $c->daily_draft_cap }} bản nháp/ngày</dd>
                    <dt class="ss-muted">Điểm dừng chờ người</dt>
                    <dd>{{ collect((array) $c->checkpoints)->map(fn ($k) => Campaign::CHECKPOINTS[$k] ?? $k)->implode(' · ') ?: 'Không có (chạy liền mạch trong hạn mức)' }}</dd>
                </dl>
                @if (CampaignResource::canEdit($c))
                    <p style="margin-top:.75rem"><x-filament::button tag="a" size="sm" color="gray" :href="CampaignResource::getUrl('edit', ['record' => $c])">Sửa cấu hình</x-filament::button></p>
                @endif
            </x-studio.panel>
        </x-studio.tabs>
    </div>
</x-filament-panels::page>
