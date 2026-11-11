<x-filament-panels::page>
    <style>
        .bb-in{border:1px solid rgba(128,128,128,.5);border-radius:.4rem;padding:.35rem .55rem;background:transparent}
        .bb-row{display:flex;gap:.75rem;flex-wrap:wrap;align-items:flex-end;margin-bottom:.75rem}
        .bb-row label{display:flex;flex-direction:column;gap:.2rem;font-size:.85rem}
        .bb-t{width:100%;border-collapse:collapse;font-size:.9rem}
        .bb-t th,.bb-t td{text-align:left;padding:.35rem .5rem;border-bottom:1px solid rgba(128,128,128,.25);vertical-align:top}
        .kh-h{display:flex;align-items:center;gap:.5rem;width:100%;text-align:left;font-weight:600;font-size:1rem;background:transparent;border:0;padding:0;cursor:pointer;color:inherit}
        .kh-h .kh-chev{margin-left:auto;transition:transform .15s}
        .kh-h[aria-expanded=false] .kh-chev{transform:rotate(-90deg)}
        .kh-sub{margin-top:.9rem;padding-top:.7rem;border-top:1px solid rgba(128,128,128,.25)}
        .kh-sub__h{font-weight:600;margin-bottom:.35rem}
    </style>
    <div class="ss-page">
        <div class="bb-row" style="margin-bottom:.6rem" data-testid="bb-intro">
            <a href="{{ \App\Filament\Pages\BrainLibraryPage::getUrl() }}" wire:navigate class="ss-text-sm"><x-filament::icon icon="heroicon-o-arrow-left" style="width:.9rem;height:.9rem;display:inline;vertical-align:-.15em" /> Về thư viện Trí tuệ</a>
            <x-help title="Luồng tạo Trí tuệ doanh nghiệp"><p>Làm lần lượt từ trên xuống trên cùng một trang, không cần chuyển tab. Khởi tạo nhanh chạy cả chuỗi bằng một nút; hoặc làm từng bước. Xong, kiến thức tự nhận và hồ sơ doanh nghiệp nằm trong Trí tuệ Thương hiệu của website, mục chờ duyệt hiện ở hộp duyệt của Trí tuệ đó.</p></x-help>
        </div>
        <div class="bb-row" style="margin-bottom:0">
            <label>Website
                <select class="bb-in" wire:model.live="websiteId" data-testid="ob-site">
                    @foreach ($sites as $s)<option value="{{ $s->id }}">{{ $s->name }} ({{ $s->base_url }})</option>@endforeach
                </select>
            </label>
        </div>
        <div class="ss-card" style="margin-top:1rem" x-data="{ open: false }" id="khoi-tao-nhanh" data-testid="bb-quick"><div class="ss-card__b">
            <button type="button" class="kh-h" @click="open = ! open" :aria-expanded="open">Khởi tạo nhanh<x-filament::icon icon="heroicon-o-chevron-down" class="kh-chev" style="width:1.1rem;height:1.1rem" /></button>
            <div x-show="open" x-cloak>
            @if ($canOnboard)
            <div class="bb-row" style="margin-top:.75rem">
                <x-help title="Khi chạy"><p>Một nút làm cả chuỗi: đọc website công ty để xây kho và điền hồ sơ doanh nghiệp, gắn Trí tuệ theo lĩnh vực, khảo sát từ khóa, gợi ý bài nên viết trước. Không tạo bài và không giao việc viết. Cần kết nối AI; bước nào chưa làm được sẽ được bỏ qua và nói rõ lý do, các bước còn lại vẫn chạy. Chạy ngay trong trang, có thể mất vài chục giây đến vài phút.</p></x-help>
                <x-filament::button wire:click="startOnboarding" wire:loading.attr="disabled" data-testid="ob-start" icon="heroicon-o-rocket-launch">Khởi tạo nhanh website</x-filament::button>
                <span wire:loading wire:target="startOnboarding" class="ss-muted ss-text-sm">Đang chạy, có thể mất vài phút…</span>
            </div>
            @if ($onboardResult)
                <div class="kh-sub" data-testid="ob-result"><div class="kh-sub__h">Kết quả khởi tạo</div>
                    <ol style="margin:0 0 0 1.1rem">
                        @foreach ($onboardResult['steps'] as $s)
                            <li style="margin:.35rem 0"><strong>{{ $s['label'] }}</strong>
                                <x-studio.badge :tone="$s['status'] === 'ok' ? 'ok' : ($s['status'] === 'failed' ? 'danger' : 'warn')">{{ ['ok' => 'Xong', 'skipped' => 'Bỏ qua', 'failed' => 'Lỗi'][$s['status']] }}</x-studio.badge><br>
                                <span class="ss-muted ss-text-sm">{{ $s['message'] }}</span></li>
                        @endforeach
                    </ol>
                    <p class="ss-text-sm"><a href="{{ $onboardLinks['keywords'] }}" wire:navigate>Từ khóa</a> · <a href="{{ $onboardLinks['plan'] }}" wire:navigate>Kế hoạch nội dung</a></p>
                </div>
            @endif
            @else
                <p class="ss-muted ss-text-sm" style="margin-top:.5rem">Chỉ người duyệt trở lên được khởi tạo nhanh.</p>
            @endif
            </div>
        </div></div>
        <div class="ss-card" style="margin-top:1rem" x-data="{ open: true }" id="tu-website" data-testid="bb-site"><div class="ss-card__b">
            <div style="display:flex;align-items:center;gap:.4rem"><button type="button" class="kh-h" @click="open = ! open" :aria-expanded="open">Từ website công ty <x-filament::icon icon="heroicon-o-chevron-down" class="kh-chev" style="width:1.1rem;height:1.1rem" /></button><x-help title="Xây kho hoạt động thế nào"><p data-testid="bb-note">{{ $this->note() }}</p><p>Chạy nền và chạy theo lịch chưa có: lượt xây chạy ngay trong trang và dừng nếu bạn đóng trang giữa chừng.</p></x-help></div>
            <div x-show="open" x-cloak>
            <div class="bb-row">
                    <input class="bb-in" type="number" min="1" max="50" wire:model.live="maxPages" style="width:8rem">
                </label>
            </div>
            <label style="display:flex;flex-direction:column;gap:.2rem;font-size:.85rem">Thêm URL cụ thể (mỗi dòng một URL, cùng tên miền; có thể bỏ trống)
                <textarea class="bb-in" rows="3" wire:model="urlsText" placeholder="https://..."></textarea>
            </label>
            <div class="bb-row" style="margin-top:.75rem">
                <x-filament::button wire:click="confirmBuild" wire:loading.attr="disabled" :disabled="$off !== null">Bắt đầu xây kho</x-filament::button>
                <span wire:loading wire:target="build" class="ss-muted ss-text-sm">Đang đọc trang…</span>
                @if ($off)<span class="ss-muted ss-text-sm" data-testid="bb-off">{{ $off }}</span>@elseif ($avDemo)<x-studio.badge tone="warn">AI giả lập (local)</x-studio.badge>@elseif ($avLabel)<x-studio.badge tone="ok">{{ $avLabel }}</x-studio.badge>@endif
            </div>
        @if ($result)
            <div class="kh-sub" data-testid="bb-result">
                <div class="kh-sub__h">Kết quả lượt vừa chạy @if ($result['demo'])<x-studio.badge tone="warn">AI giả lập</x-studio.badge>@endif</div>
                <p>Đọc {{ $result['pages_read'] }} trang · bỏ qua {{ $result['pages_skipped'] }} trang (không đổi hoặc quá ít chữ) · lỗi {{ $result['pages_failed'] }} trang · kế hoạch {{ $result['pages_planned'] }} trang.</p>
                <p>{{ $result['items_auto'] }} mục tự nhận · <a href="{{ $reviewUrl }}" wire:navigate>{{ $result['items_proposed'] }} mục chờ duyệt (mở Kho trí tuệ để duyệt)</a>.</p>
                <div class="ss-muted ss-text-sm">Chi phí ước lượng {{ number_format($result['cost_vnd'], 0, ',', '.') }} ₫ <x-help title="Về chi phí"><p>Con số là ước lượng theo giá cấu hình, không phải hóa đơn.</p></x-help></div>
                @if ($result['message'])<div class="ss-alert ss-alert--warn" role="status">{{ $result['message'] }}</div>@endif
                @foreach ($result['rejected_urls'] as $u)<p class="ss-muted ss-text-xs">Bỏ URL không hợp lệ: {{ $u }}</p>@endforeach
            </div>
        @endif        @if ($brandAuto || $brandMissing !== [])
            <div class="kh-sub" data-testid="bb-brand">
                <div class="kh-sub__h">Hồ sơ doanh nghiệp tự điền
                    <x-help title="Cách AI tự điền hồ sơ"><p>Chỉ điền trường đang trống, mỗi trường có đoạn nguyên văn từ trang. Từ cấm và đối thủ cần tránh không bao giờ tự điền. Sau mỗi lượt xây kho đọc được trang, AI thử điền các trường trống (chỉ khi trống từ 3 trường). Hãy xem lại ở trang hồ sơ.</p></x-help>
                </div>
                @if ($brandAuto)
                    <p data-testid="bb-brand-done">Đã tự điền hồ sơ doanh nghiệp từ website lúc {{ \Illuminate\Support\Carbon::parse($brandAuto['at'])->format('d/m/Y H:i') }}: {{ implode(', ', array_map(fn ($f) => \App\Services\Brand\BrandProfileData::REQUIRED[$f] ?? $f, array_keys($brandAuto['fields']))) }}. @if ($brandAuto['demo'])<x-studio.badge tone="warn">AI giả lập</x-studio.badge>@endif <x-filament::button size="xs" color="gray" wire:click="undoBrand">Hoàn tác</x-filament::button></p>
                @else
                    <p class="ss-muted ss-text-sm">Còn trống: {{ implode(', ', $brandMissing) }}.</p>
                @endif
                @if ($result && isset($result['brand_profile']) && ! $result['brand_profile']['applied'] && $result['brand_profile']['reason'])<p class="ss-muted ss-text-xs" data-testid="bb-brand-reason">Lượt vừa chạy: {{ $result['brand_profile']['reason'] }}</p>@endif
            </div>
        @endif
            </div>
        </div></div>
        <div class="ss-card" style="margin-top:1rem" x-data="{ open: {{ $topic !== '' || $candidates !== [] || $webReport ? 'true' : 'false' }} }" id="tu-web" data-testid="bb-discover"><div class="ss-card__b">
            <div style="display:flex;align-items:center;gap:.4rem"><button type="button" class="kh-h" @click="open = ! open" :aria-expanded="open">Khám phá nguồn trên web <x-filament::icon icon="heroicon-o-chevron-down" class="kh-chev" style="width:1.1rem;height:1.1rem" /></button><x-help title="Cách tìm nguồn"><p>Mô tả chủ đề, hệ thống tìm trên web và đề xuất nguồn. Nguồn tin cậy (cơ quan nhà nước, giáo dục, tổ chức y khoa trong danh sách) được tự nhận khi có đoạn nguyên văn; nguồn chưa rõ chỉ đề xuất để bạn duyệt một lần; mạng xã hội, diễn đàn, chợ bị loại. Mọi mục có nhãn nguồn và hoàn tác được.</p></x-help></div>
            <div x-show="open" x-cloak>
            @if (! $webAv['ok'])
                <div class="ss-alert ss-alert--warn" role="status" data-testid="bb-web-off">{{ $webAv['reason'] }}</div>
            @elseif ($webAv['demo'])
                <div class="ss-alert ss-alert--warn" role="status">Đang dùng tìm kiếm mô phỏng, kết quả không phải trang thật.</div>
            @endif
            <div class="bb-row">
                <label style="flex:1;min-width:16rem">Chủ đề bạn muốn tìm tài liệu
                    <input class="bb-in" type="text" wire:model="topic" wire:keydown.enter="discover" maxlength="200" placeholder="Ví dụ: chế độ ăn cho người tiểu đường" data-testid="bb-topic">
                </label>
                <x-filament::button wire:click="discover" wire:loading.attr="disabled" :disabled="! $webAv['ok']">Tìm nguồn</x-filament::button>
                <span wire:loading wire:target="discover" class="ss-muted ss-text-sm">Đang tìm…</span>
            </div>
            @if ($candidates !== [])
                <div data-testid="bb-candidates">
                @foreach ($candidates as $c)
                    @php $lv = $c['trust']['level']; @endphp
                    <label class="ss-card" style="display:block;margin:.5rem 0;padding:.6rem .75rem;border:1px solid rgba(128,128,128,.3);border-radius:.4rem" data-testid="bb-cand">
                        <input type="checkbox" wire:model="picked" value="{{ $c['url'] }}">
                        <a href="{{ $c['url'] }}" target="_blank" rel="noopener noreferrer"><strong>{{ $c['title'] }}</strong></a>
                        <x-studio.badge :tone="$lv === 'trusted' ? 'ok' : 'muted'">{{ $lv === 'trusted' ? 'Tin cậy' : 'Chưa rõ' }}</x-studio.badge>
                        <span class="ss-muted ss-text-xs">{{ $c['domain'] }}</span>
                        @if ($c['snippet'] !== '')<br><span class="ss-text-sm">{{ \Illuminate\Support\Str::limit($c['snippet'], 220) }}</span>@endif
                        <br><span class="ss-muted ss-text-xs">Lý do xếp: {{ $c['trust']['reason'] }}</span>
                    </label>
                @endforeach
                </div>
                <div class="bb-row">
                    <x-filament::button wire:click="addPicked" wire:loading.attr="disabled" :disabled="$picked === []">Thêm nguồn đã chọn</x-filament::button>
                    <x-filament::button color="success" wire:click="addAllTrusted" wire:loading.attr="disabled">Thêm tất cả nguồn tin cậy</x-filament::button>
                    <span wire:loading wire:target="addPicked,addAllTrusted" class="ss-muted ss-text-sm">Đang đọc các trang, vui lòng chờ…</span>
                </div>
                @if ($searchMeta)<div class="ss-muted ss-text-xs">Chi phí tìm ước lượng {{ number_format($searchMeta['cost_vnd'], 0, ',', '.') }} ₫ <x-help title="Chi phí lần thêm"><p>Truy vấn: «{{ $searchMeta['query'] }}». Tổng cộng tối đa {{ number_format(\App\Services\Knowledge\Build\BrainBuildService::totalBudgetVnd(), 0, ',', '.') }} ₫ cho lần thêm.</p></x-help></div>@endif
            @endif
            @if ($webReport)
                <div class="ss-alert ss-alert--info" role="status" data-testid="bb-web-report">
                    Đã đọc {{ $webReport['imported'] }} nguồn · {{ $webReport['items_auto'] }} mục tự nhận (xem bên dưới, có Hoàn tác) · <a href="{{ $reviewUrl }}" wire:navigate>{{ $webReport['items_proposed'] }} mục chờ duyệt</a> · chi phí ước lượng {{ number_format($webReport['cost_vnd'], 0, ',', '.') }} ₫.
                    @if ($webReport['stopped_reason'] === 'budget') Dừng vì chạm trần chi phí.@endif
                    @foreach ($webReport['pages'] as $pg)@if (! $pg['ok'])<br><span class="ss-text-xs">{{ $pg['domain'] }}: {{ $pg['message'] }}</span>@endif @endforeach
                </div>
            @endif
            </div>
        </div></div>
        <div class="ss-card" style="margin-top:1rem" x-data="{ open: {{ ($pendingCount + count($suggestions) + $autoItems->count()) > 0 ? 'true' : 'false' }} }" id="can-xem" data-testid="bb-todo"><div class="ss-card__b">
            <button type="button" class="kh-h" @click="open = ! open" :aria-expanded="open">Việc cần bạn xem<x-filament::icon icon="heroicon-o-chevron-down" class="kh-chev" style="width:1.1rem;height:1.1rem" /></button>
            <div x-show="open" x-cloak>
            @if ($pendingCount > 0)<p data-testid="bb-pending"><a href="{{ $reviewUrl }}" wire:navigate>{{ $pendingCount }} mục chờ duyệt</a> trong Kho trí tuệ.</p>@endif
        @if ($suggestions !== [])
        <div class="kh-sub" data-testid="bb-suggest">
            <div class="kh-sub__h">Gợi ý cho kho từ các bài gần đây
                <x-help title="Về gợi ý"><p>Chỉ là gợi ý, không có gì tự vào kho. "Bổ sung từ website" chỉ đọc tối đa 5 trang có đường dẫn chứa từ khóa.</p></x-help>
            </div>
                <table class="bb-t"><tbody>
                @foreach ($suggestions as $sg)
                    <tr data-testid="bb-sg">
                        <td><strong>{{ $sg['title'] }}</strong><br><span class="ss-muted ss-text-xs">{{ $sg['detail'] }}</span></td>
                        <td style="white-space:nowrap">
                            @if ($sg['action'] === 'build')<x-filament::button size="xs" wire:click="fillGap('{{ $sg['key'] }}')" wire:loading.attr="disabled">Bổ sung từ website</x-filament::button>
                            @else<a href="{{ $reviewUrl }}" wire:navigate class="ss-text-sm">Mở Kho trí tuệ</a>@endif
                            <x-filament::button size="xs" color="gray" wire:click="dismissSuggestion('{{ $sg['key'] }}')">Bỏ qua</x-filament::button>
                        </td>
                    </tr>
                @endforeach
                </tbody></table>
        </div>
        @endif
        @if ($autoItems->isNotEmpty())
        <div class="kh-sub">
            <div class="kh-sub__h">Mục đã tự nhận ({{ $autoItems->count() }})</div>
                <table class="bb-t"><thead><tr><th>Mục</th><th>Loại</th><th>Căn cứ</th><th></th></tr></thead><tbody>
                @foreach ($autoItems as $it)
                    <tr>
                        <td><strong>{{ $it->title }}</strong> <x-studio.badge tone="info">tự nhận</x-studio.badge>@if ($it->source?->origin === 'search')<x-studio.badge tone="muted">web: {{ parse_url((string) $it->source->url, PHP_URL_HOST) }} · {{ $it->source->fetched_at?->format('d/m/Y') }}</x-studio.badge>@endif<br><span class="ss-muted ss-text-xs">{{ \Illuminate\Support\Str::limit($it->body, 160) }}</span></td>
                        <td>{{ \App\Models\KnowledgeItem::TYPES[$it->type] ?? $it->type }}</td>
                        <td class="ss-muted ss-text-xs">{{ \Illuminate\Support\Str::limit((string) $it->source_span, 120) }}</td>
                        <td><x-filament::button size="xs" color="gray" wire:click="undo({{ $it->id }})">Hoàn tác</x-filament::button></td>
                    </tr>
                @endforeach
                </tbody></table>
        </div>
        @endif
        @if ($runs !== [])
        <div class="kh-sub">
            <div class="kh-sub__h">Các lượt xây gần đây</div>
                <table class="bb-t"><thead><tr><th>Lúc</th><th>Trang</th><th>Mục</th><th>Chi phí</th><th>Trạng thái</th></tr></thead><tbody>
                @foreach ($runs as $r)
                    <tr><td>{{ $r['at'] }}</td><td>{{ $r['pages'] }}</td><td>{{ $r['items'] }}</td><td>{{ number_format($r['cost'], 0, ',', '.') }} ₫</td><td>{{ $statusLabels[$r['status']] ?? $r['status'] }}@if ($r['reason'])<br><span class="ss-muted ss-text-xs">{{ $r['reason'] }}</span>@endif</td></tr>
                @endforeach
                </tbody></table>
        </div>
        @endif
            </div>
        </div></div>
    </div>
</x-filament-panels::page>
