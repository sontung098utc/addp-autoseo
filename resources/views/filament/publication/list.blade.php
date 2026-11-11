@php $stop = $this->stopWarning(); $chips = $this->getCachedTabs(); $activeTab = strval($this->activeTab); @endphp
<x-filament-panels::page class="fi-resource-list-records-page fi-resource-publications">
    <div class="flex flex-col gap-y-4">
        @if ($stop)
            <div role="alert" data-testid="stop-warning" style="border:1px solid rgba(220,38,38,.45);background:rgba(220,38,38,.09);border-radius:.5rem;padding:.6rem .85rem;font-size:.875rem">
                <strong><x-filament::icon icon="heroicon-o-exclamation-triangle" style="width:1.1rem;height:1.1rem;display:inline;vertical-align:-.2em" /> Lịch xuất bản tự động đang DỪNG KHẨN CẤP.</strong>
                Lý do: {{ $stop['reason'] }}. Toàn bộ lượt đăng đang tạm hoãn.
            </div>
        @endif

        <x-studio.tabs persist="lich-xuat-ban" default="lich-tuan" label="Lịch xuất bản" :tabs="[['key' => 'lich-tuan', 'label' => 'Lịch tuần'], ['key' => 'danh-sach', 'label' => 'Danh sách']]">
            <x-studio.panel key="lich-tuan">
                @include('filament.publication.weekly-board', $this->weeklyBoardData())
            </x-studio.panel>
            <x-studio.panel key="danh-sach">
                <div class="flex flex-col gap-y-4">
                    @if (count($chips))
                        <div role="group" aria-label="Lọc theo trạng thái" data-testid="status-chips" style="display:flex;flex-wrap:wrap;gap:.4rem">
                            @foreach ($chips as $key => $chip)
                                @php $key = strval($key); @endphp
                                <x-filament::button size="xs" :color="$activeTab === $key ? 'primary' : 'gray'" :outlined="$activeTab !== $key" wire:click="$set('activeTab', '{{ $key }}')" wire:key="chip-{{ $key }}">
                                    {{ $chip->getLabel() ?? $this->generateTabLabel($key) }}@if (($b = $chip->getBadge()) !== null && (int) $b > 0) · {{ $b }}@endif
                                </x-filament::button>
                            @endforeach
                        </div>
                    @endif
                    {{ $this->table }}
                </div>
            </x-studio.panel>
        </x-studio.tabs>
    </div>
    <x-filament-actions::modals />
</x-filament-panels::page>
