<x-filament-panels::page>
    <div data-testid="pipeline" class="space-y-3" @if ($live) wire:poll.{{ $pollSeconds }}s @endif>
        @include('filament.clusters.agent-team.pipeline-style')
        <x-studio.tabs persist="pipeline-view" :default="$live ? 'runs' : 'flows'" label="Cách xem pipeline" :tabs="[
            ['key' => 'flows', 'label' => 'Các luồng', 'count' => count($flows)],
            ['key' => 'runs', 'label' => 'Đang chạy', 'count' => count($runs) ?: null, 'tone' => $live ? 'info' : null],
        ]">
            <x-studio.panel key="flows">
                @include('filament.clusters.agent-team.pipeline-flows')
            </x-studio.panel>
            <x-studio.panel key="runs">
                @include('filament.clusters.agent-team.pipeline-runs')
            </x-studio.panel>
        </x-studio.tabs>
    </div>
    <x-filament-actions::modals />
</x-filament-panels::page>
