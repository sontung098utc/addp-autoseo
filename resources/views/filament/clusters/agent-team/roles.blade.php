<x-filament-panels::page>
    <div data-testid="agent-roles" class="space-y-4">
        <p class="text-sm" style="opacity:.8">
            Đây là "đội AI SEO" và luật làm việc của từng vai trò, học từ bộ claude-seo và chỉnh cho website tiếng Việt.
            Nhãn trạng thái phản ánh việc <strong>thật sự được nối vào phiên chạy</strong>, không phải kế hoạch. Phiên bản này chỉ để xem; sửa rubric chưa làm.
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
            @endforeach
        </div>
    </div>
</x-filament-panels::page>
