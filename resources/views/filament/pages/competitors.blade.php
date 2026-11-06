<x-filament-panels::page>
    <style>
        .co-box{border:1px solid rgba(128,128,128,.3);border-radius:.75rem;padding:1rem;margin-bottom:1rem}
        .co-h{font-weight:600;margin-bottom:.5rem}
        .co-muted{opacity:.75;font-size:.8rem;line-height:1.5}
        .co-bar{display:flex;gap:.5rem;align-items:center;flex-wrap:wrap}
        .co-table{width:100%;border-collapse:collapse;font-size:.8rem}
        .co-table th,.co-table td{text-align:left;padding:.4rem .5rem;border-bottom:1px solid rgba(128,128,128,.2);vertical-align:top}
        .co-table-wrap{overflow-x:auto}
        .co-locked{background:rgba(128,128,128,.1)}
        .co-score{display:inline-block;min-width:2rem;padding:.1rem .4rem;border:1px solid rgba(128,128,128,.5);border-radius:.3rem;font-weight:600;text-align:center}
        .co-why summary{cursor:pointer}
        .co-why div{font-size:.72rem;margin-top:.25rem;opacity:.85}
        .co-rank{display:inline-block;margin:0 .4rem .2rem 0;white-space:nowrap;font-size:.75rem}
        .co-ghost{filter:blur(3px);opacity:.55;pointer-events:none;user-select:none}
        .co-ok{color:rgb(var(--success-600))}
        .co-bad{color:rgb(var(--danger-600))}
        .co-inp{border:1px solid rgba(128,128,128,.4);border-radius:.4rem;padding:.35rem .5rem;background:transparent;color:inherit;font-size:.85rem}
        .co-formula{font-family:ui-monospace,monospace;font-size:.8rem;background:rgba(128,128,128,.1);padding:.6rem;border-radius:.4rem;overflow-x:auto}
        .co-law h3{font-weight:600;font-size:.85rem;margin:.75rem 0 .25rem}
        .co-law ul{list-style:disc;padding-left:1.25rem;font-size:.8rem;line-height:1.6}
    </style>

    <x-demo-banner source="API từ khóa trả phí (nhà cung cấp chưa chốt)" real="tính khoảng trống, đánh dấu trùng với bài hiện có, điểm ưu tiên, thêm vào kế hoạch"/>

    <p class="co-muted">Chủ đề đối thủ đã có thứ hạng mà website chưa có bài. Dữ liệu lấy từ nguồn từ khóa bên ngoài, chỉ để tham khảo; chọn chủ đề nào đưa vào kế hoạch là quyết định của người làm nội dung. Hệ thống chỉ truy vấn tên miền công khai do bạn nhập, không truy cập website đối thủ.</p>

    <div class="co-bar" style="margin:.75rem 0">
        <label for="co-site"><strong>Website</strong></label>
        <select id="co-site" class="co-inp" wire:model.live="websiteId">
            @foreach ($websites as $w)<option value="{{ $w->id }}">{{ $w->name }}</option>@endforeach
        </select>
    </div>

    @if (! $site)
        <div class="co-box">Chưa có website nào ở trạng thái Hoạt động. Hãy thêm và kích hoạt website trước.</div>
    @elseif ($state === 'chua-ket-noi')
        <div class="co-box" data-co-state="chua-ket-noi">
            <div class="co-h">Chưa kết nối nguồn từ khóa trả phí</div>
            <p class="co-muted">Để biết đối thủ xếp hạng từ khóa nào, hệ thống cần một API từ khóa trả phí (nhà cung cấp chưa chốt). Hệ thống không tự truy cập website đối thủ nên chưa có dữ liệu cho tới khi nguồn này được cấu hình. Cần có: tài khoản nhà cung cấp, khóa API (lưu mã hóa), hạn mức do quản trị đặt.</p>
        </div>
        <div class="co-box co-ghost-wrap">
            <div class="co-h">Bảng khoảng trống nội dung <span class="co-muted">— Dữ liệu minh họa, chưa kết nối</span></div>
            <div class="co-table-wrap co-ghost"><table class="co-table">
                <thead><tr><th>Chủ đề / từ khóa</th><th>Volume</th><th>Độ khó</th><th>Đối thủ</th><th>Trong hệ thống</th><th>Điểm</th></tr></thead>
                <tbody>@foreach ($ghost as $i)<tr><td>••••• •••• •••</td><td>•••</td><td>••</td><td>•••• #•</td><td>••••</td><td>••</td></tr>@endforeach</tbody>
            </table></div>
            <p class="co-muted">Bảng chỉ để hình dung bố cục. Không có từ khóa hay số liệu nào ở trạng thái này.</p>
        </div>
    @else
        {{-- Danh sách đối thủ --}}
        <div class="co-box" data-co-section="competitors">
            <div class="co-h">{{ count($domains) }} đối thủ đang theo dõi (tối đa {{ $maxCompetitors }})</div>
            @if ($state === 'khong-doi-thu' || count($domains) === 0)
                <p class="co-muted"><strong>Chưa có đối thủ nào để so sánh.</strong> Nhập tên miền công khai của đối thủ cạnh tranh về từ khóa với website này.</p>
            @else
                <div class="co-table-wrap"><table class="co-table">
                    <thead><tr><th>Tên miền</th><th>Từ khóa đối thủ xếp hạng</th><th>Đã có trong hệ thống</th><th>Mức trùng</th><th>Khoảng trống</th><th></th></tr></thead>
                    <tbody>
                    @foreach ($domains as $d)
                        @php($p = $perDomain[$d] ?? ['ranked' => 0, 'in_system' => 0, 'gap' => 0])
                        <tr>
                            <td>{{ $d }}</td><td>{{ $p['ranked'] }}</td><td>{{ $p['in_system'] }}</td>
                            <td>{{ $p['ranked'] ? number_format(100 * $p['in_system'] / $p['ranked'], 1, ',', '.') . '%' : '—' }}</td>
                            <td>{{ $p['gap'] }}</td>
                            <td><button type="button" class="co-muted" style="text-decoration:underline" wire:click="removeCompetitor('{{ $d }}')">Bỏ theo dõi</button></td>
                        </tr>
                    @endforeach
                    </tbody>
                </table></div>
                <p class="co-muted">Mức trùng = số từ khóa đối thủ xếp hạng mà website đã có bài ÷ số từ khóa đối thủ xếp hạng (so khớp với bài hiện có). Khoảng trống = phần còn lại.</p>
            @endif
            <form wire:submit="addCompetitor" class="co-bar" style="margin-top:.75rem">
                <label for="co-new" class="co-muted">Thêm đối thủ theo tên miền</label>
                <input id="co-new" class="co-inp" type="text" wire:model="newDomain" placeholder="doithu.vn" autocomplete="off">
                <x-filament::button type="submit" color="gray" size="sm">Thêm tên miền</x-filament::button>
                <span class="co-muted">Chỉ tên miền, tối đa {{ $maxCompetitors }} đối thủ. Không truy cập website đối thủ.</span>
            </form>
        </div>

        {{-- Nguồn dữ liệu, chi phí, hạn mức --}}
        <div class="co-box" data-co-section="source">
            <div class="co-h">Nguồn từ khóa <span class="co-muted">(minh họa)</span></div>
            <dl class="co-muted" style="display:grid;grid-template-columns:max-content 1fr;gap:.2rem 1rem">
                <dt>Lần làm mới gần nhất</dt><dd>{{ $fetchedLabel ?? 'Chưa làm mới lần nào' }}@if($staleDays) — cách đây {{ $staleDays }} ngày @endif</dd>
                <dt>Chi phí ước tính lần này</dt><dd>~{{ number_format($estimate['cost_vnd'], 0, ',', '.') }} VND (<em>ước tính</em>: {{ number_format($estimate['rows'], 0, ',', '.') }} dòng × {{ number_format($vndPer1000, 0, ',', '.') }} VND / 1.000 dòng, giá giả định chưa phải bảng giá nhà cung cấp nào)</dd>
                <dt>Hạn mức tháng</dt><dd>đã dùng {{ number_format($used, 0, ',', '.') }} / {{ number_format($monthlyLimit, 0, ',', '.') }} dòng, còn {{ number_format($estimate['remaining'], 0, ',', '.') }}</dd>
            </dl>
            <div class="co-bar" style="margin-top:.5rem">
                {{ $this->refreshAction }}
                @if ($blockedReason)<span class="co-bad" data-co-blocked>{{ $blockedReason }}</span>@endif
            </div>
            <p class="co-muted">Làm mới thủ công, không tự chạy. Phải xác nhận chi phí trước khi chạy.</p>
            @if (count($log))
                <details class="co-muted"><summary>Lịch sử làm mới ({{ count($log) }})</summary>
                    <ul>@foreach ($log as $l)<li>{{ \Illuminate\Support\Carbon::parse($l['at'])->setTimezone('Asia/Ho_Chi_Minh')->format('d/m/Y H:i') }} · {{ $l['by'] }} · {{ $l['website'] }} · {{ number_format($l['rows'], 0, ',', '.') }} dòng · ~{{ number_format($l['cost_vnd'], 0, ',', '.') }} VND</li>@endforeach</ul>
                </details>
            @endif
        </div>

        @if ($state === 'dang-tai')
            <div class="co-box" data-co-state="dang-tai">
                <div class="co-h">Đang lấy dữ liệu từ nhà cung cấp <em class="co-muted">(trạng thái minh họa)</em></div>
                <p class="co-muted">Bảng khoảng trống hiện sau khi tất cả tên miền xong. Chưa có dữ liệu để hiển thị.</p>
            </div>
        @elseif ($state === 'loi-api' || $refreshError)
            <div class="co-box" data-co-state="loi-api" style="border-color:rgb(var(--danger-600))">
                <div class="co-h co-bad">Không lấy được dữ liệu</div>
                <p class="co-muted">{{ $refreshError ?? 'Nhà cung cấp báo hết hạn mức hoặc không phản hồi (trạng thái minh họa). Bấm "Làm mới dữ liệu" để thử lại; hệ thống không tự thử liên tục để tránh tốn phí.' }}</p>
                @if ($fetchedLabel)
                    <p class="co-muted"><strong>Dữ liệu cũ{{ $staleDays ? ' '.$staleDays.' ngày' : '' }}</strong>: bản lưu ngày {{ $fetchedLabel }}. Hệ thống không đưa bản này vào bảng khoảng trống như dữ liệu mới và không cho thêm vào kế hoạch từ bản cũ khi nguồn đang lỗi.</p>
                @endif
            </div>
        @elseif ($state === 'khong-doi-thu' || count($domains) === 0)
            {{-- đã hướng dẫn thêm đối thủ ở khối trên --}}
        @elseif (! $fetchedLabel)
            <div class="co-box" data-co-state="chua-lam-moi"><p class="co-muted">Chưa có dữ liệu. Bấm "Làm mới dữ liệu" (có xác nhận chi phí) để lấy thứ hạng từ khóa của {{ count($domains) }} đối thủ.</p></div>
        @else
            <div class="co-box" data-co-section="gaps">
                <div class="co-bar" style="justify-content:space-between">
                    <div class="co-h">Khoảng trống nội dung: {{ $openCount }} chủ đề có thể thêm, {{ $totalGaps - $openCount }} đã có trong hệ thống</div>
                    <x-filament::button wire:click="addToPlan" :disabled="! $canWrite || count($selected) === 0" icon="heroicon-o-plus">Thêm vào kế hoạch ({{ count($selected) }})</x-filament::button>
                </div>
                <p class="co-muted">Dữ liệu lấy lúc {{ $fetchedLabel }}@if($staleDays) ({{ $staleDays }} ngày trước)@endif. Tạo bài thật ở trạng thái Đã lên kế hoạch; không tự viết bài.</p>

                <div class="co-bar" style="margin:.5rem 0">
                    <label class="co-muted">Ý định
                        <select class="co-inp" wire:model.live="intentFilter"><option value="">Tất cả</option>@foreach ($intents as $k => $l)<option value="{{ $k }}">{{ $l }}</option>@endforeach</select></label>
                    <label class="co-muted">Volume từ
                        <select class="co-inp" wire:model.live="minVolume"><option value="0">Mọi mức</option><option value="1000">1.000</option><option value="3000">3.000</option><option value="5000">5.000</option></select></label>
                    <label class="co-muted">Sắp xếp
                        <select class="co-inp" wire:model.live="sortBy"><option value="score">Điểm ưu tiên</option><option value="volume">Volume</option><option value="difficulty">Độ khó (dễ trước)</option></select></label>
                    <label class="co-muted"><input type="checkbox" wire:model.live="hideExisting"> Ẩn dòng đã có trong hệ thống</label>
                </div>

                <div class="co-table-wrap"><table class="co-table">
                    <thead><tr><th></th><th>Chủ đề / từ khóa</th><th>Volume / tháng</th><th>Độ khó</th><th>Đối thủ xếp hạng</th><th>Trong hệ thống</th><th>Điểm ưu tiên</th></tr></thead>
                    <tbody>
                    @forelse ($rows as $g)
                        <tr class="{{ $g['existing'] ? 'co-locked' : '' }}" wire:key="gap-{{ md5($g['norm']) }}">
                            <td>@if (! $g['existing'] && $canWrite)<input type="checkbox" wire:model.live="selected" value="{{ $g['norm'] }}" aria-label="Chọn {{ $g['keyword'] }}">@endif</td>
                            <td>{{ $g['keyword'] }} <span class="co-muted">{{ $intents[$g['intent']] ?? $g['intent'] }}</span></td>
                            <td>{{ number_format($g['volume'], 0, ',', '.') }}</td>
                            <td>{{ $g['difficulty'] }}</td>
                            <td>@foreach ($g['ranks'] as $dom => $pos)<span class="co-rank">{{ $dom }} #{{ $pos }}</span>@endforeach</td>
                            <td>
                                @if ($g['existing'])
                                    <strong>{{ $g['existing']['status'] === 'planned' ? 'Đã lên kế hoạch' : 'Đã có bài' }}</strong>
                                    <div class="co-muted">Bài #{{ $g['existing']['id'] }}: {{ $g['existing']['keyword'] }}</div>
                                @else Chưa có bài @endif
                            </td>
                            <td>
                                @if ($g['score'])
                                    <details class="co-why"><summary><span class="co-score">{{ $g['score']['score'] }}</span> xem lý do</summary><div>{{ $g['score']['reason'] }}</div></details>
                                @else <span class="co-muted">— không chấm điểm: đã có trong hệ thống</span> @endif
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="7" class="co-muted">Không có dòng nào khớp bộ lọc.</td></tr>
                    @endforelse
                    </tbody>
                </table></div>
                <p class="co-muted">Dòng xám đã có bài hoặc đã lên kế hoạch (khớp từ khóa chính sau khi chuẩn hóa chữ thường, bỏ khoảng trắng thừa) nên không chọn được, để tránh hai bài tranh cùng từ khóa. Chưa khớp theo ngữ nghĩa; biến thể gần nghĩa vẫn có thể lọt qua, người duyệt kiểm tra.</p>
            </div>

            @if (count($results))
                <div class="co-box" data-co-section="results" role="status">
                    <div class="co-h">Kết quả thêm vào kế hoạch</div>
                    <ul>@foreach ($results as $r)<li class="{{ $r['ok'] ? 'co-ok' : 'co-bad' }}">{{ $r['keyword'] }}: {{ $r['message'] }}</li>@endforeach</ul>
                </div>
            @endif

            <div class="co-box" data-co-section="clusters">
                <div class="co-h">Gom thành cụm chủ đề <span class="co-muted">— AI đề xuất (minh họa): gom bằng từ chung, chỉ đề xuất, không tự thêm</span></div>
                @forelse ($clusters as $i => $c)
                    <div style="margin-bottom:.5rem">
                        <strong>{{ $c['name'] }}</strong> — {{ count($c['keywords']) }} từ khóa, tổng {{ number_format($c['volume'], 0, ',', '.') }} lượt tìm/tháng.
                        <div class="co-muted">Nguồn: {{ implode('; ', array_map(fn ($k) => '"'.$k.'"', $c['keywords'])) }}</div>
                        @if ($canWrite)<x-filament::button size="xs" color="gray" wire:click="selectCluster({{ $i }})">Chọn cả cụm</x-filament::button>@endif
                    </div>
                @empty
                    <p class="co-muted">Chưa đủ từ khóa có từ chung để gom cụm.</p>
                @endforelse
            </div>
        @endif

        <div class="co-box">
            <div class="co-h">Điểm ưu tiên tính thế nào</div>
            <div class="co-formula">{{ $formula }}<br>V = log10(volume + 1) ÷ log10(volume lớn nhất + 1) · D = độ khó (0–100) · O = số đối thủ xếp hạng ÷ tổng số đối thủ<br>Trọng số: 50 điểm volume, 30 điểm độ khó dễ, 20 điểm mức chồng lấn.</div>
            <p class="co-muted">Điểm chỉ để xếp thứ tự xem trước; không thay cho phán đoán của người làm nội dung.</p>
        </div>
    @endif

    <details class="co-box co-law">
        <summary class="co-h" style="cursor:pointer">Luật nghiệp vụ của màn này</summary>
        <h3>Nguồn dữ liệu và phạm vi</h3>
        <ul>
            <li>Danh sách đối thủ do người dùng nhập (tên miền công khai). Hệ thống không crawl trực tiếp website đối thủ ở bản này; thứ hạng đến từ API từ khóa trả phí (nhà cung cấp chưa chốt).</li>
            <li>Dữ liệu ngoài chỉ để tham khảo. Người làm nội dung quyết định chủ đề nào đáng làm; hệ thống không tự thêm, tự viết hay tự đăng.</li>
            <li>Tên miền được chuẩn hóa (bỏ https://, đường dẫn, chữ thường); từ chối IP, localhost, tên miền nội bộ, trùng, tên miền của chính website, và quá {{ $maxCompetitors ?? 10 }} đối thủ.</li>
            <li>Quyền: xem, thêm/xóa đối thủ, làm mới dữ liệu: quản duyệt trở lên; thêm vào kế hoạch cần thêm quyền tạo bài.</li>
        </ul>
        <h3>Chi phí, hạn mức, làm mới</h3>
        <ul>
            <li>Mỗi lần làm mới tốn phí: hiển thị chi phí ước tính (VND, nhãn "ước tính") và hạn mức còn lại, bắt buộc xác nhận. Vượt hạn mức thì nút bị vô hiệu kèm lý do.</li>
            <li>Không làm mới tự động. API lỗi: dừng, báo lỗi, không trừ hạn mức, không dùng số cũ như thể mới (bản cũ luôn gắn nhãn thời điểm).</li>
            <li>Mỗi lần làm mới ghi lại: ai bấm, lúc nào, số dòng, chi phí ước tính.</li>
        </ul>
        <h3>Luật khoảng trống và trùng từ khóa</h3>
        <ul>
            <li>Từ khóa khớp bài hiện có của website (chuẩn hóa chữ thường, bỏ khoảng trắng thừa) thì đánh dấu "Đã có bài" / "Đã lên kế hoạch", không chọn được, không chấm điểm.</li>
            <li>"Thêm vào kế hoạch" tạo bài thật ở trạng thái Đã lên kế hoạch, báo kết quả từng từ khóa; thành công một phần được phép.</li>
        </ul>
    </details>

    <x-filament-actions::modals />
</x-filament-panels::page>
