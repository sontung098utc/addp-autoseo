@php
    $money = fn ($v) => number_format((float) $v, 0, ',', '.').' đ';
@endphp
<style>
    .cfg-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(21rem,1fr));gap:.9rem}
    .cfg-card{border:1px solid rgba(128,128,128,.35);border-radius:.9rem;padding:1rem;display:flex;flex-direction:column;gap:.7rem;min-width:0}
    .cfg-card--off{opacity:.7}
    .cfg-head{display:flex;gap:.7rem;align-items:center;min-width:0}
    .cfg-head strong{display:block;line-height:1.2;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
    .cfg-sub{font-size:.75rem;opacity:.7}
    .cfg-row{display:flex;justify-content:space-between;gap:.6rem;font-size:.8rem;align-items:center;flex-wrap:wrap}
    .cfg-key{font-family:ui-monospace,monospace;letter-spacing:.05em}
    .cfg-models{display:flex;flex-wrap:wrap;gap:.3rem}
    .cfg-chip{display:inline-flex;align-items:center;gap:.3rem;padding:.12rem .55rem;border-radius:999px;border:1px solid rgba(128,128,128,.4);font-size:.72rem;white-space:nowrap}
    .cfg-flag{font-size:.58rem;font-weight:700;letter-spacing:.03em;text-transform:uppercase;padding:.05rem .35rem;border-radius:.3rem;line-height:1.3}
    .cfg-flag--new{background:#0f6b5c;color:#fff}
    .cfg-flag--gone{background:#dc2626;color:#fff}
    .cfg-chip--gone{border-color:#dc2626;color:#b91c1c}
    .cfg-chip--gone .cfg-name{text-decoration:line-through}
    .cfg-chip--new{border-color:#0f6b5c}
    .cfg-chip--find{background:transparent;color:inherit;cursor:pointer}
    .cfg-chip--find:hover{border-color:#0f6b5c;background:rgba(15,107,92,.08)}
    .cfg-tier{font-size:.62rem;opacity:.7;text-transform:uppercase;letter-spacing:.03em}
    .cfg-actions{display:flex;flex-wrap:wrap;gap:.3rem;border-top:1px solid rgba(128,128,128,.2);padding-top:.6rem;margin-top:auto}
    .cfg-pick{display:grid;grid-template-columns:repeat(auto-fill,minmax(14rem,1fr));gap:.6rem}
    .cfg-pick button{display:flex;gap:.6rem;align-items:center;text-align:left;padding:.7rem .8rem;border-radius:.8rem;border:1px solid rgba(128,128,128,.4);background:transparent;color:inherit;cursor:pointer}
    .cfg-pick button:hover{border-color:#0f6b5c;background:rgba(15,107,92,.07)}
    .cfg-pick strong{display:block;font-size:.85rem}.cfg-pick span.h{display:block;font-size:.72rem;opacity:.65;line-height:1.3}
    .cfg-add{padding:.45rem .9rem;border-radius:.5rem;border:1px solid #0f6b5c;background:#0f6b5c;color:#fff;cursor:pointer;font-size:.85rem;display:inline-flex;gap:.35rem;align-items:center}
</style>
<div data-testid="ai-connection" class="space-y-4">
    <div style="display:flex;justify-content:space-between;gap:.6rem;align-items:center;flex-wrap:wrap">
        <div class="text-sm" style="display:flex;align-items:center;gap:.3rem">
            <span style="opacity:.85">{{ $providers->count() }} tài khoản · tối đa {{ $limits['rounds'] }} vòng tự sửa, {{ $money($limits['budget']) }}/phiên</span>
            <x-help title="Cấu hình AI">
                <p><strong>Tác vụ nào chưa có khóa tài khoản sẽ không chạy.</strong> Hệ thống không tự dùng Mock thay thế.</p>
                <p>Mọi bài do AI viết đều dừng ở bản nháp chờ duyệt. Khóa API lưu mã hóa, chỉ ghi, không hiện lại (chỉ thấy vài ký tự cuối).</p>
                <p>"Tháng này" chỉ tính các phiên chạy qua định tuyến có ghi tài khoản; phiên cũ không quy được cho tài khoản nào.</p>
                <p>Trang này chỉ cấu hình kết nối và model dùng được. Việc chọn model cho từng nhân sự nằm ở Đội AI → Vai trò → hồ sơ → Mô hình.</p>
                @unless ($isAdmin)<p>Chỉ quản trị viên được thêm, sửa hoặc đổi khóa.</p>@endunless
            </x-help>
        </div>
        @if ($isAdmin)
            <span style="display:inline-flex;gap:.4rem;flex-wrap:wrap"><button type="button" class="cfg-add" style="background:transparent;color:inherit;border-color:rgba(128,128,128,.5)" wire:click="mountAction('editLimits')" data-testid="edit-limits"><x-filament::icon icon="heroicon-o-adjustments-horizontal" style="width:1rem;height:1rem" /> Giới hạn mỗi phiên</button>
            <button type="button" class="cfg-add" style="background:transparent;color:inherit;border-color:rgba(128,128,128,.5)" wire:click="mountAction('syncModels')" wire:loading.attr="disabled" wire:target="mountAction('syncModels')" data-testid="sync-models" title="Hỏi từng hãng danh sách model hiện có. Model cũ còn hoạt động giữ nguyên; model mới hiện dấu &quot;Mới&quot;; model hãng đã gỡ hiện cảnh báo (không tự xóa khỏi bản gán vai trò)."><x-filament::icon icon="heroicon-o-arrow-path" style="width:1rem;height:1rem" /> Cập nhật model mới nhất</button>
            <button type="button" class="cfg-add" wire:click="togglePicker" data-testid="add-account"><x-filament::icon icon="heroicon-o-plus" style="width:1rem;height:1rem" /> Thêm tài khoản</button></span>
        @endif
    </div>

    <div class="text-sm" style="opacity:.8" data-testid="image-status">{{ $imageStatus['text'] }}. Đổi ở Đội AI → Vai trò → Họa viên hình ảnh → Mô hình &amp; kỹ thuật.</div>

    @if (! empty($goneInUse))
        <div class="text-sm" role="alert" data-testid="gone-in-use" style="padding:.7rem .9rem;border:1px solid rgba(220,38,38,.55);border-radius:.6rem;background:rgba(220,38,38,.06)">
            <strong>Có model hãng đã gỡ nhưng vẫn đang được gán cho nhân sự.</strong> Các việc dùng chúng có thể lỗi; chọn model khác ở Đội AI → Vai trò → Mô hình.
            <ul style="margin:.3rem 0 0 1.1rem;list-style:disc">@foreach ($goneInUse as $g)<li>{{ $g['role'] }} ({{ $g['slot'] === 'primary' ? 'chính' : 'dự phòng' }}): <code>{{ $g['model'] }}</code> · {{ $g['provider'] }}</li>@endforeach</ul>
        </div>
    @endif

    @if ($legacy)
        <div class="text-sm" style="padding:.7rem .9rem;border:1px solid rgba(217,119,6,.5);border-radius:.6rem">Đang dùng cấu hình cũ: một Gemini ({{ $legacy['source'] === 'db' ? 'khóa lưu trong hệ thống' : 'khóa từ biến môi trường' }}, model {{ $legacy['model'] }}). Thêm tài khoản để chuyển sang cấu hình mới.</div>
    @endif

    @if ($isAdmin && $showPicker)
        <x-filament::section heading="Chọn nhà cung cấp" data-testid="provider-picker">
            <div class="cfg-pick">
                @foreach ($presets as $key => $pr)
                    <button type="button" wire:click="mountAction('addProvider', {{ \Illuminate\Support\Js::from(['preset' => $key]) }})" data-preset="{{ $key }}">
                        <x-ai.logo :brand="$pr['logo']" :size="2.4" />
                        <span><strong>{{ $pr['label'] }}</strong><span class="h">{{ $pr['hint'] }}</span></span>
                    </button>
                @endforeach
            </div>
        </x-filament::section>
    @endif

    @if ($providers->isEmpty())
        <x-filament::section>
            <p class="text-sm" style="opacity:.75" data-testid="no-accounts">Chưa có tài khoản AI nào.</p>
        </x-filament::section>
    @else
        <div class="cfg-grid">
            @foreach ($providers as $p)
                <article class="cfg-card {{ $p->enabled ? '' : 'cfg-card--off' }}" data-provider="{{ $p->id }}" data-brand="{{ $p->brand }}" wire:key="acct-{{ $p->id }}">
                    <div class="cfg-head">
                        <x-ai.logo :brand="$p->logo" :size="2.6" />
                        <div style="min-width:0;flex:1"><strong title="{{ $p->name }}">{{ $p->name }}</strong><span class="cfg-sub">{{ $p->kindLabel() }}@if ($p->base_url) · {{ \Illuminate\Support\Str::limit(preg_replace('#^https?://#', '', $p->base_url), 32) }}@endif</span></div>
                        <div>
                            @if (! $p->enabled)<x-filament::badge color="gray">Đang tắt</x-filament::badge>
                            @elseif (! $p->hasKey())<x-filament::badge color="warning">Chưa có khóa</x-filament::badge>
                            @elseif ($p->last_test_ok === true)<x-filament::badge color="success">Dùng được</x-filament::badge>
                            @elseif ($p->last_test_ok === false)<x-filament::badge color="danger" title="{{ $p->last_test_message }}">Lỗi kết nối</x-filament::badge>
                            @else<x-filament::badge color="gray">Chưa kiểm tra</x-filament::badge>@endif
                        </div>
                    </div>
                    @if ($p->last_test_ok === false && $p->last_test_message)<div class="cfg-sub" style="color:#dc2626">{{ \Illuminate\Support\Str::limit($p->last_test_message, 140) }}</div>@endif
                    @if ($p->hasKey())<div class="cfg-row"><span class="cfg-sub">Khóa API</span><span class="cfg-key" data-testid="key-mask">@if ($p->hasKey())•••• đã lưu{{ $p->keyTail() ? ' …'.$p->keyTail() : '' }}@else <span style="opacity:.7">Chưa có khóa</span>@endif</span></div>@endif
                    <div>
                        <div class="cfg-sub" style="margin-bottom:.25rem">Model đã bật ({{ count($p->enabled_models) }})</div>
                        <div class="cfg-models" data-testid="account-models">
                            @forelse ($p->enabled_models as $m)
                                <span class="cfg-chip {{ ($m['flag'] ?? null) === 'gone' ? 'cfg-chip--gone' : (($m['flag'] ?? null) === 'new' ? 'cfg-chip--new' : '') }}" data-model="{{ $m['id'] }}" @if (($m['flag'] ?? null) === 'gone') title="Hãng không còn liệt kê model này. Đổi sang model khác trước khi nó ngừng hẳn." @else title="{{ $m['id'] }}{{ $m['context'] ? ' · ngữ cảnh '.\App\Services\Agent\Ai\AiModelCatalog::contextLabel($m['context']) : '' }}" @endif><span class="cfg-name">{{ $m['name'] }}</span>@if (($m['flag'] ?? null) === 'new')<span class="cfg-flag cfg-flag--new">Mới</span>@elseif (($m['flag'] ?? null) === 'gone')<span class="cfg-flag cfg-flag--gone">Đã ngưng</span>@endif@if ($m['tier'])<span class="cfg-tier">{{ $tierLabels[$m['tier']] ?? $m['tier'] }}</span>@elseif ($m['custom'])<span class="cfg-tier">tùy chỉnh</span>@endif</span>
                            @empty<span class="cfg-sub">Chưa bật model nào.</span>@endforelse
                        </div>
                    </div>
                    @if (! empty($p->discovered))
                        <div data-testid="discovered-models">
                            <div class="cfg-sub" style="margin-bottom:.25rem">Model hãng đang có, chưa bật ({{ count($p->discovered) }})@if ($isAdmin) · bấm để bật @endif</div>
                            <div class="cfg-models">
                                @foreach (array_slice($p->discovered, 0, 12) as $d)
                                    @if ($isAdmin)
                                        <button type="button" class="cfg-chip cfg-chip--find {{ $d['is_new'] ? 'cfg-chip--new' : '' }}" wire:click="enableDiscoveredModel({{ $p->id }}, {{ \Illuminate\Support\Js::from($d['id']) }})" title="{{ $d['id'] }}" data-model="{{ $d['id'] }}">+ {{ \Illuminate\Support\Str::limit($d['name'], 28) }}@if ($d['is_new'])<span class="cfg-flag cfg-flag--new">Mới</span>@endif @if ($d['kind'] === 'image')<span class="cfg-tier">ảnh</span>@endif</button>
                                    @else
                                        <span class="cfg-chip {{ $d['is_new'] ? 'cfg-chip--new' : '' }}" title="{{ $d['id'] }}">{{ \Illuminate\Support\Str::limit($d['name'], 28) }}@if ($d['is_new'])<span class="cfg-flag cfg-flag--new">Mới</span>@endif</span>
                                    @endif
                                @endforeach
                                @if (count($p->discovered) > 12)<span class="cfg-sub">… và {{ count($p->discovered) - 12 }} model khác (xem ở nút Sửa)</span>@endif
                            </div>
                        </div>
                    @endif
                    @if ($p->models_synced_at)<div class="cfg-sub" data-testid="models-synced-at">Danh sách model cập nhật {{ $p->models_synced_at->diffForHumans() }}</div>@endif
                    <div class="cfg-row">
                        @if ($p->used_month > 0 || $p->monthly_budget_vnd)<span class="cfg-sub">Tháng này: {{ $money($p->used_month) }}{{ $p->monthly_budget_vnd ? ' / '.$money($p->monthly_budget_vnd) : '' }}@if ($p->monthly_budget_vnd && $p->used_month >= $p->monthly_budget_vnd) <x-filament::badge color="danger">Vượt ngân sách</x-filament::badge>@endif</span>@endif
                        @if ($p->staff_count > 0)<span class="cfg-sub">{{ $p->staff_count }} nhân sự đang dùng</span>@endif
                    </div>
                    @if ($isAdmin)
                        <div class="cfg-actions">
                            {{ ($this->testProviderAction)(['id' => $p->id]) }} {{ ($this->updateKeyAction)(['id' => $p->id]) }} {{ ($this->editProviderAction)(['id' => $p->id]) }} {{ ($this->deleteProviderAction)(['id' => $p->id]) }}
                        </div>
                    @endif
                </article>
            @endforeach
        </div>
    @endif
</div>
