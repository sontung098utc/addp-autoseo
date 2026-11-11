@php
    $statusLabel = ['draft' => ['Nháp, chưa qua bộ mẫu', 'warn'], 'active' => ['Đang dùng', 'ok'], 'retired' => ['Đã nghỉ', 'muted'], 'error' => ['Lỗi định dạng', 'danger']];
    $tierLabel = \App\Services\Agent\Roles\RoleRegistry::TIERS;
@endphp
<x-filament-panels::page>
    <div data-testid="agent-skills" class="space-y-4">
        <p class="text-sm" style="opacity:.85" data-testid="skills-summary">
            {{ $total }} kỹ năng học từ claude-seo (MIT) · {{ $drafts }} đang là nháp
            @if ($errors > 0) · <span style="color:#b91c1c">{{ $errors }} file lỗi</span>@endif
            · <a href="#" wire:click.prevent="mountAction('notice')" style="text-decoration:underline" data-testid="skills-notice-link">Xem ghi công (NOTICE)</a>
            <x-help text="Chỉ để xem: sửa kỹ năng là sửa file trong resources/playbooks và mở PR. Chạy thử chưa nối: cần bộ mẫu AG-K09 với model thật. Kỹ năng nháp chỉ dùng cho lượt chạy thủ công, lượt tự động bỏ qua." />
        </p>

        <div style="display:flex;gap:.5rem;flex-wrap:wrap">
            <input type="search" wire:model.live.debounce.300ms="search" placeholder="Tìm theo tên kỹ năng" class="text-sm" data-testid="skills-search"
                   style="border:1px solid rgba(128,128,128,.4);border-radius:.5rem;padding:.35rem .6rem;background:transparent;min-width:14rem">
            <select wire:model.live="roleFilter" class="text-sm" data-testid="skills-role-filter" style="border:1px solid rgba(128,128,128,.4);border-radius:.5rem;padding:.35rem .6rem;background:transparent">
                <option value="">Mọi vai trò</option>
                @foreach ($roles as $r)<option value="{{ $r['key'] }}">{{ $r['name'] }}</option>@endforeach
            </select>
            <select wire:model.live="statusFilter" class="text-sm" data-testid="skills-status-filter" style="border:1px solid rgba(128,128,128,.4);border-radius:.5rem;padding:.35rem .6rem;background:transparent">
                <option value="">Mọi trạng thái</option>
                <option value="draft">Nháp</option><option value="active">Đang dùng</option><option value="retired">Đã nghỉ</option><option value="error">Lỗi định dạng</option>
            </select>
        </div>

        @forelse ($groups as $roleName => $skills)
            <div data-testid="skills-group">
                <div class="text-sm" style="font-weight:600;margin-bottom:.3rem">{{ $roleName }}</div>
                <div style="overflow-x:auto"><table class="text-xs" style="width:100%;border-collapse:collapse">
                    <tbody>
                    @foreach ($skills as $s)
                        @php $pb = $s['playbook']; @endphp
                        <tr data-testid="skill-row" style="border-top:1px solid rgba(128,128,128,.25);vertical-align:top">
                            <td style="min-width:22rem;padding:.4rem .5rem .4rem 0">
                                <button type="button" wire:click="mountAction('detail', {{ \Illuminate\Support\Js::from(['ref' => $s['ref']]) }})" style="text-align:left;background:transparent;border:0;color:inherit;cursor:pointer;padding:0">
                                    <strong style="text-decoration:underline">{{ $s['title'] }}</strong>
                                </button>
                                <div style="opacity:.7"><code>{{ $s['ref'] }}</code></div>
                                @if ($s['error'])<div style="color:#b91c1c">{{ $s['error'] }}</div>@endif
                            </td>
                            <td style="width:1%;white-space:nowrap;padding:.4rem .5rem">
                                <x-studio.badge :tone="$statusLabel[$s['status']][1]">{{ $statusLabel[$s['status']][0] }}</x-studio.badge>
                            </td>
                            <td style="width:1%;white-space:nowrap;padding:.4rem .5rem">{{ $pb ? ($tierLabel[$pb->modelTier] ?? $pb->modelTier) : '—' }}</td>
                            <td style="padding:.4rem .5rem">
                                @if ($pb)<div style="display:flex;gap:.25rem;flex-wrap:wrap">@forelse ($pb->rubricRefs as $c)<x-studio.badge tone="info">{{ $c }}</x-studio.badge>@empty — @endforelse</div>@endif
                            </td>
                            <td style="width:1%;white-space:nowrap;padding:.4rem 0 .4rem .5rem;opacity:.75">
                                @if ($pb){{ $pb->lineage['source'] }} v{{ $pb->lineage['source_version'] }} · {{ $pb->lineage['license'] }}@endif
                            </td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
            </div>
        @empty
            <div class="text-sm" style="opacity:.7" data-testid="skills-empty">Không có kỹ năng nào khớp bộ lọc.</div>
        @endforelse
    </div>
</x-filament-panels::page>
