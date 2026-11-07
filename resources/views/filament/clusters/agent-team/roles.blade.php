<x-filament-panels::page>
    <div data-testid="agent-roles" class="space-y-4">
        <p class="text-sm" style="opacity:.8">
            Đây là "đội AI SEO" và luật làm việc của từng vai trò, học từ bộ claude-seo và chỉnh cho website tiếng Việt.
            Nhãn trạng thái phản ánh việc <strong>thật sự được nối vào phiên chạy</strong>, không phải kế hoạch. Sửa rubric chưa làm. Chỉ vai trò không bắt buộc mới tắt được (hiện là Chiến lược); vai trò Biên tập (chấm luật) và Người viết luôn bật.
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
                @if ($c['switchable'])
                    <div class="text-xs" data-testid="role-switch-{{ $c['key'] }}" style="margin-top:-.4rem;padding:.4rem .8rem;border:1px solid rgba(128,128,128,.35);border-radius:.5rem;display:flex;justify-content:space-between;align-items:center;gap:.5rem">
                        <span>{{ $c['is_off'] ? 'Đang TẮT: bước dàn ý bị bỏ qua' : 'Đang bật' }}</span>
                        @if ($canSwitch)
                            <x-filament::button size="xs" color="gray" wire:click="toggleRole('{{ $c['key'] }}')">{{ $c['is_off'] ? 'Bật lại' : 'Tắt vai trò' }}</x-filament::button>
                        @else
                            <span style="opacity:.6">Chỉ admin đổi</span>
                        @endif
                    </div>
                @endif
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
