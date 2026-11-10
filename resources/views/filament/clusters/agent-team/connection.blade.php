@php
    $money = fn ($v) => number_format((float) $v, 0, ',', '.').' đ';
    $th = 'text-align:left;padding:.5rem .75rem;font-weight:600;font-size:.8rem;opacity:.8';
    $td = 'padding:.5rem .75rem;vertical-align:middle';
@endphp
<x-filament-panels::page>
    <div data-testid="ai-connection" class="space-y-4">
        <x-filament::section>
            <p class="text-sm">
                <strong>Tác vụ nào chưa có khóa provider sẽ không chạy.</strong> Hệ thống không tự dùng Mock thay thế.
                Mọi bài do AI viết đều dừng ở <strong>bản nháp chờ duyệt</strong>. Khóa API chỉ ghi, không bao giờ hiện lại.
                @unless ($isAdmin) Chỉ quản trị viên được thêm, sửa hoặc đổi khóa. @endunless
            </p>
            <p class="mt-1 text-xs" style="opacity:.75">Giới hạn mỗi phiên: ngân sách {{ $money($limits['budget']) }} · tối đa {{ $limits['rounds'] }} vòng tự sửa.</p>
        </x-filament::section>

        @if ($legacy)
            <x-filament::section>
                <p class="text-sm">Đang dùng cấu hình cũ: một Gemini ({{ $legacy['source'] === 'db' ? 'khóa lưu trong hệ thống' : 'khóa từ biến môi trường' }}, model {{ $legacy['model'] }}). Thêm provider bên dưới để chuyển sang định tuyến model theo loại việc.</p>
            </x-filament::section>
        @endif

        <x-filament::section heading="Provider">
            @if ($providers->isEmpty())
                <p class="text-sm" style="opacity:.75">Chưa có provider nào. @if ($isAdmin) Bấm "Thêm provider" để bắt đầu. @endif</p>
            @else
                <div style="overflow-x:auto">
                    <table style="width:100%;border-collapse:collapse;font-size:.875rem">
                        <thead><tr><th style="{{ $th }}">Provider</th><th style="{{ $th }}">Khóa API</th><th style="{{ $th }}">Trạng thái</th><th style="{{ $th }}">Ngân sách tháng</th><th style="{{ $th }}">Đã dùng tháng này</th><th style="{{ $th }}"></th></tr></thead>
                        <tbody>
                        @foreach ($providers as $p)
                            <tr style="border-top:1px solid rgba(128,128,128,.25)" data-provider="{{ $p->id }}">
                                <td style="{{ $td }}"><strong>{{ $p->name }}</strong><div class="text-xs" style="opacity:.7">{{ $p->kindLabel() }}@if ($p->base_url) · {{ $p->base_url }}@endif</div></td>
                                <td style="{{ $td }}">@if ($p->hasKey()) •••• đã lưu @else <span style="opacity:.7">Chưa có khóa</span> @endif</td>
                                <td style="{{ $td }}">
                                    @if (! $p->enabled)<x-filament::badge color="gray">Đang tắt</x-filament::badge>
                                    @elseif (! $p->hasKey())<x-filament::badge color="warning">Chưa cấu hình</x-filament::badge>
                                    @elseif ($p->last_test_ok === true)<x-filament::badge color="success">Dùng được</x-filament::badge>
                                    @elseif ($p->last_test_ok === false)<x-filament::badge color="danger">Lỗi: {{ $p->last_test_message }}</x-filament::badge>
                                    @else<x-filament::badge color="gray">Chưa kiểm tra</x-filament::badge>@endif
                                </td>
                                <td style="{{ $td }}">{{ $p->monthly_budget_vnd ? $money($p->monthly_budget_vnd) : '—' }}</td>
                                <td style="{{ $td }}">
                                    {{ $money($p->used_month) }}
                                    @if ($p->monthly_budget_vnd && $p->used_month >= $p->monthly_budget_vnd)<x-filament::badge color="danger">Vượt ngân sách</x-filament::badge>@endif
                                </td>
                                <td style="{{ $td }};white-space:nowrap">
                                    @if ($isAdmin)
                                        {{ ($this->updateKeyAction)(['id' => $p->id]) }} {{ ($this->testProviderAction)(['id' => $p->id]) }} {{ ($this->editProviderAction)(['id' => $p->id]) }}
                                    @endif
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
                <p class="mt-2 text-xs" style="opacity:.7">"Đã dùng tháng này" chỉ tính các phiên chạy qua định tuyến (có ghi provider); phiên chạy cũ không quy được cho provider nào.</p>
            @endif
        </x-filament::section>

        <x-filament::section heading="Định tuyến theo loại việc">
            <div style="overflow-x:auto">
                <table style="width:100%;border-collapse:collapse;font-size:.875rem">
                    <thead><tr><th style="{{ $th }}">Loại việc</th><th style="{{ $th }}">Bậc</th><th style="{{ $th }}">Model chính</th><th style="{{ $th }}">Dự phòng</th><th style="{{ $th }}">Trần chi phí/lần</th><th style="{{ $th }}"></th></tr></thead>
                    <tbody>
                    @foreach ($tasks as $task => [$label, $defaultTier])
                        @php $r = $routes->get($task); @endphp
                        <tr style="border-top:1px solid rgba(128,128,128,.25)" data-task="{{ $task }}">
                            <td style="{{ $td }}"><strong>{{ $label }}</strong></td>
                            <td style="{{ $td }}">{{ $r ? ($tiers[$r->tier] ?? $r->tier) : '—' }}</td>
                            <td style="{{ $td }}">
                                @if ($r && $r->primaryProvider)
                                    {{ $r->primaryProvider->name }} · {{ $r->primary_model }}
                                    @if (! $r->primaryProvider->hasKey() || ! $r->primaryProvider->enabled)<x-filament::badge color="warning">Không chạy: {{ $r->primaryProvider->enabled ? 'thiếu khóa' : 'provider tắt' }}</x-filament::badge>@endif
                                @else
                                    <x-filament::badge color="warning">Chưa cấu hình, không chạy</x-filament::badge>
                                @endif
                            </td>
                            <td style="{{ $td }}">{{ $r && $r->fallbackProvider ? $r->fallbackProvider->name.' · '.$r->fallback_model : 'Không có dự phòng' }}</td>
                            <td style="{{ $td }}">{{ $r && $r->cost_cap_vnd_per_call ? $money($r->cost_cap_vnd_per_call) : 'Không giới hạn' }}</td>
                            <td style="{{ $td }}">@if ($isAdmin && $providers->isNotEmpty()){{ ($this->editRouteAction)(['task' => $task]) }}@endif</td>
                        </tr>
                    @endforeach
                    </tbody>
                </table>
            </div>
            <p class="mt-2 text-xs" style="opacity:.75">Dự phòng chỉ chuyển giữa các model <strong>thật</strong>, và mỗi lần chuyển được ghi vào nhật ký phiên chạy ("đã chuyển từ A sang B vì lỗi X").</p>
        </x-filament::section>
    </div>
</x-filament-panels::page>
