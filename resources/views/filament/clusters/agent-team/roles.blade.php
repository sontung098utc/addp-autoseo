<x-filament-panels::page>
    <div data-testid="agent-roles" class="space-y-4">
        <p class="text-sm" style="opacity:.8">
            Đây là "đội AI SEO" và luật làm việc của từng vai trò, học từ bộ claude-seo và chỉnh cho website tiếng Việt.
            Nhãn trạng thái phản ánh việc <strong>thật sự được nối vào phiên chạy</strong>, không phải kế hoạch. Sửa rubric chưa làm. Admin bấm "Gán" để chọn model, bật/tắt và ghim kỹ năng; người duyệt chỉ xem. Vai trò Biên tập (chấm luật) và Người viết luôn bật.
        </p>
        <div style="display:grid;grid-template-columns:repeat(auto-fill,minmax(17rem,1fr));gap:.75rem">
            @foreach ($cards as $c)
                <button type="button" wire:click="mountAction('detail', {{ \Illuminate\Support\Js::from(['role' => $c['key']]) }})" data-testid="role-card-{{ $c['key'] }}"
                        style="text-align:left;border:1px solid rgba(128,128,128,.35);border-radius:.75rem;padding:.8rem;display:flex;flex-direction:column;gap:.45rem;background:transparent;cursor:pointer;color:inherit">
                    <div style="display:flex;justify-content:space-between;gap:.5rem;align-items:flex-start">
                        <strong>{{ $c['name'] }}</strong>
                        <x-studio.chip domain="readiness" :value="$c['readiness']" />
                    </div>
                    <div class="text-xs" style="opacity:.8">{{ $c['goal'] }}</div>
                    <div class="text-xs" style="opacity:.65">
                        {{ $c['skill_counts']['have'] }}/{{ $c['skill_counts']['total'] }} kỹ năng có playbook · Bậc model: {{ $c['tier_label'] }}
                    </div>
                </button>
                <div class="text-xs" data-testid="role-assign-{{ $c['key'] }}" style="margin-top:-.4rem;padding:.4rem .8rem;border:1px solid rgba(128,128,128,.35);border-radius:.5rem;display:flex;flex-direction:column;gap:.35rem">
                    <div style="display:flex;justify-content:space-between;align-items:center;gap:.5rem;flex-wrap:wrap">
                        <span data-testid="role-model-{{ $c['key'] }}">
                            @if ($c['assigned'])
                                <strong>{{ $c['assigned'] }}</strong>
                            @elseif (! $c['model_wired'])
                                Chưa gán model <x-studio.hint text="Chưa nối vào luồng: vai trò này chưa gọi AI trong phiên chạy nên chưa gán model được." />
                            @elseif (! $hasProviders)
                                Chưa gán model <x-studio.hint tone="warning" text="Chưa có provider — vào Cài đặt → Tích hợp AI để thêm." />
                                <a href="{{ $connectionUrl }}" style="text-decoration:underline">Tích hợp AI</a>
                            @else
                                Chưa gán model, dùng định tuyến theo loại việc
                            @endif
                        </span>
                        @if ($canSwitch)
                            <x-filament::button size="xs" color="gray" wire:click="mountAction('assign', {{ \Illuminate\Support\Js::from(['role' => $c['key']]) }})" data-testid="role-assign-btn-{{ $c['key'] }}">Gán</x-filament::button>
                        @endif
                    </div>
                    @if ($c['skill_changes'] > 0)
                        <span style="opacity:.75">{{ $c['skill_changes'] }} kỹ năng đã tắt hoặc ghim phiên bản</span>
                    @endif
                    @if ($c['switchable'])
                        <span data-testid="role-switch-{{ $c['key'] }}">{{ $c['is_off'] ? 'Đang TẮT: bước dàn ý bị bỏ qua' : 'Đang bật' }}</span>
                        @if ($canSwitch)
                            <x-filament::button size="xs" color="gray" wire:click="toggleRole('{{ $c['key'] }}')">{{ $c['is_off'] ? 'Bật lại' : 'Tắt vai trò' }}</x-filament::button>
                        @else
                            <span style="opacity:.6">Chỉ admin đổi</span>
                        @endif
                    @endif
                    @if ($c['updated'])
                        <span style="opacity:.6">Gán lần cuối: {{ $c['updated'] }}</span>
                    @endif
                </div>
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
