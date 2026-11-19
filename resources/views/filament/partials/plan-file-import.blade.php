@php
    use App\Services\Keywords\KeywordAiResolver;
    use App\Services\Planning\Import\PlanFileImporter;
    use App\Services\Planning\Import\SheetReader;

    $st = $importState;
    $pv = $importPreview;
    $res = $importResult;
    $ai = $st ? null : KeywordAiResolver::availability('plan_import');
@endphp
<style>
    .pfm-back { position: fixed; inset: 0; z-index: 100; display: flex; align-items: center; justify-content: center; padding: 1rem; background: rgb(15 18 25 / .55); }
    .pfm { display: flex; flex-direction: column; width: 100%; max-width: 54rem; max-height: calc(100vh - 2rem); border-radius: .9rem; background: var(--ss-surface, #fff); color: var(--ss-text, #111); box-shadow: 0 24px 64px rgb(0 0 0 / .35); }
    .pfm-h { display: flex; align-items: center; justify-content: space-between; gap: 1rem; padding: .9rem 1.3rem; border-bottom: 1px solid var(--ss-border, #ddd); }
    .pfm-h h2 { margin: 0; font-size: 1.1rem; font-weight: 650; display: flex; align-items: center; gap: .4rem; }
    .pfm-x { border: 0; background: none; font-size: 1.5rem; line-height: 1; cursor: pointer; color: var(--ss-text-muted, #666); padding: .1rem .45rem; border-radius: .4rem; }
    .pfm-x:hover { background: var(--ss-muted-bg, #eee); }
    .pfm-b { padding: .9rem 1.3rem 1.1rem; overflow-y: auto; }
    .pfm-f { display: flex; gap: .6rem; justify-content: flex-end; align-items: center; padding: .75rem 1.3rem; border-top: 1px solid var(--ss-border, #ddd); }
    .pfm-drop { display: flex; flex-direction: column; align-items: center; gap: .45rem; padding: 2.4rem 1rem; text-align: center; border: 2px dashed var(--ss-border, #ccc); border-radius: .8rem; cursor: pointer; }
    .pfm-drop:hover { border-color: var(--ss-accent-fg, #2563eb); }
    .pfm-drop strong { font-size: 1.05rem; }
    .pfm-in { padding: .4rem .65rem; border: 1px solid var(--ss-border, #ccc); border-radius: .5rem; background: var(--ss-surface, #fff); color: inherit; font-size: .9rem; max-width: 100%; }
    .pfm-btn { padding: .3rem .7rem; border: 1px solid var(--ss-border, #ccc); border-radius: .5rem; background: var(--ss-surface, #fff); color: inherit; font-size: .85rem; cursor: pointer; }
    .pfm-btn:hover { border-color: var(--ss-accent-fg, #2563eb); }
    .pfm-step { display: flex; align-items: center; gap: .45rem; margin: 1rem 0 .4rem; font-weight: 650; font-size: .95rem; }
    .pfm-step > i { display: inline-flex; align-items: center; justify-content: center; width: 1.4rem; height: 1.4rem; border-radius: 50%; background: var(--ss-accent-bg, #e0e7ff); color: var(--ss-accent-fg, #3730a3); font-size: .8rem; font-style: normal; }
    .pfm-muted { color: var(--ss-text-muted, #666); font-size: .82rem; }
    .pfm-box { padding: .5rem .8rem; border-radius: .6rem; font-size: .88rem; margin: .5rem 0; display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; }
    .pfm-box--info { background: var(--ss-accent-bg, #eef2ff); }
    .pfm-box--warn { background: var(--ss-warn-bg, #fef3c7); color: var(--ss-warn-fg, #92400e); }
    .pfm-box--ok { background: var(--ss-ok-bg, #dcfce7); color: var(--ss-ok-fg, #166534); }
    .pfm-bar { display: flex; align-items: center; gap: .6rem; flex-wrap: wrap; }
    .pfm-chip { display: inline-flex; align-items: center; gap: .3rem; padding: .15rem .6rem; border-radius: 999px; font-size: .8rem; background: var(--ss-ok-bg, #dcfce7); color: var(--ss-ok-fg, #166534); }
    .pfm-tbl { width: 100%; border-collapse: collapse; font-size: .88rem; }
    .pfm-tbl th, .pfm-tbl td { text-align: left; padding: .45rem .5rem; border-bottom: 1px solid var(--ss-border, #e5e5e5); vertical-align: middle; }
    .pfm-tbl th { font-size: .75rem; text-transform: uppercase; letter-spacing: .03em; color: var(--ss-text-muted, #666); font-weight: 600; }
    .pfm-wrap { overflow-x: auto; }
    .pfm-off { opacity: .5; }
    .pfm-sum { display: flex; flex-wrap: wrap; gap: .3rem 1.2rem; font-size: .88rem; margin: .2rem 0 .5rem; }
    .pfm-sum b { font-size: 1.05rem; }
    .pfm-ana { display: none; flex-direction: column; align-items: center; justify-content: center; gap: .6rem; min-height: 22rem; padding: 1.5rem 1rem; text-align: center; }
    .pfm-ana strong { font-size: 1.1rem; }
    .pfm-orb { display: flex; gap: .45rem; height: 2.2rem; align-items: center; }
    .pfm-orb i { width: .9rem; height: .9rem; border-radius: 50%; background: var(--ss-accent-fg, #2563eb); animation: pfm-bounce 1.1s ease-in-out infinite; }
    .pfm-orb i:nth-child(2) { animation-delay: .15s; } .pfm-orb i:nth-child(3) { animation-delay: .3s; }
    @keyframes pfm-bounce { 0%, 80%, 100% { transform: translateY(0) scale(.7); opacity: .45; } 40% { transform: translateY(-.7rem) scale(1); opacity: 1; } }
    .pfm-msgs { position: relative; height: 1.4rem; width: 100%; color: var(--ss-text-muted, #666); font-size: .9rem; }
    .pfm-msgs span { position: absolute; inset: 0; opacity: 0; animation: pfm-msg 12s infinite; }
    .pfm-msgs span:nth-child(2) { animation-delay: 3s; } .pfm-msgs span:nth-child(3) { animation-delay: 6s; } .pfm-msgs span:nth-child(4) { animation-delay: 9s; }
    @keyframes pfm-msg { 0% { opacity: 0; transform: translateY(.4rem); } 4%, 22% { opacity: 1; transform: none; } 27%, 100% { opacity: 0; } }
    .pfm-ibar { position: relative; width: min(22rem, 80%); height: .4rem; border-radius: 999px; background: var(--ss-muted-bg, #eee); overflow: hidden; }
    .pfm-ibar i { position: absolute; top: 0; bottom: 0; width: 40%; border-radius: 999px; background: var(--ss-accent-fg, #2563eb); animation: pfm-slide 1.4s ease-in-out infinite; }
    @keyframes pfm-slide { 0% { left: -40%; } 100% { left: 100%; } }
    .pfm-skel { display: grid; gap: .5rem; width: min(30rem, 90%); margin-top: .8rem; }
    .pfm-skel b { height: .9rem; border-radius: .4rem; background: linear-gradient(90deg, var(--ss-muted-bg, #eee) 25%, var(--ss-border, #ddd) 50%, var(--ss-muted-bg, #eee) 75%); background-size: 200% 100%; animation: pfm-shimmer 1.4s linear infinite; }
    .pfm-skel b:nth-child(2) { width: 85%; } .pfm-skel b:nth-child(3) { width: 70%; } .pfm-skel b:nth-child(4) { width: 90%; }
    @keyframes pfm-shimmer { 0% { background-position: 200% 0; } 100% { background-position: -200% 0; } }
    @media (prefers-reduced-motion: reduce) { .pfm-orb i, .pfm-ibar i, .pfm-skel b { animation: none; } .pfm-msgs span { animation: none; } .pfm-msgs span:first-child { opacity: 1; } }
    .pfm-res { margin: .2rem 0 .6rem 1.1rem; padding: 0; list-style: disc; font-size: .88rem; color: var(--ss-text-muted, #555); display: grid; gap: .2rem; }
    .pfm-card { border: 1px solid var(--ss-accent-fg, #2563eb); background: var(--ss-accent-bg, #eef2ff); border-radius: .75rem; padding: .8rem 1rem; margin-top: .8rem; }
    .pfm-zt { margin: 0 0 .35rem; font-size: .7rem; font-weight: 700; letter-spacing: .06em; text-transform: uppercase; color: var(--ss-accent-fg, #3730a3); }
    .pfm-where { display: flex; align-items: center; gap: .5rem; flex-wrap: wrap; margin: .55rem 0 .15rem; }
    .pfm-where label { font-weight: 600; font-size: .88rem; }
    .pfm-more { margin-top: .8rem; border: 1px solid var(--ss-border, #ddd); border-radius: .75rem; }
    .pfm-more > summary { cursor: pointer; padding: .6rem .9rem; font-weight: 600; font-size: .9rem; }
    .pfm-more > summary .pfm-muted { font-weight: 400; }
    .pfm-more-body { padding: 0 .9rem .8rem; }
    .pfm-head { font-size: 1.15rem; line-height: 1.4; }
    .pfm-attn { margin: .6rem 0; padding: .55rem .8rem; border-radius: .6rem; background: var(--ss-warn-bg, #fef3c7); color: var(--ss-warn-fg, #92400e); font-size: .88rem; }
    .pfm-attn ul { margin: .3rem 0 0 1.1rem; padding: 0; list-style: disc; display: grid; gap: .35rem; }
    .pfm-btn--go { margin-left: .4rem; border-color: var(--ss-warn-fg, #92400e); font-weight: 600; }
    .pfm-inl { display: inline-block; vertical-align: top; } .pfm-inl summary { cursor: pointer; text-decoration: underline; } .pfm-inl ul { margin-top: .3rem; font-size: .84rem; }
    .pfm-card .pfm-tbl th, .pfm-card .pfm-tbl td { border-bottom-color: rgb(0 0 0 / .08); }
    details.pfm-adv { margin-top: .9rem; font-size: .85rem; }
    details.pfm-adv summary { cursor: pointer; color: var(--ss-text-muted, #666); }
</style>

<div class="pfm-back" x-data @keydown.escape.window="$wire.closeImport()" role="dialog" aria-modal="true" aria-labelledby="pfm-title">
    <div class="pfm">
        <div class="pfm-h">
            <h2 id="pfm-title">Nhập kế hoạch từ file
                <x-help title="Cách hoạt động">
                    <ul>
                        <li>Chọn file Excel/CSV bất kỳ cấu trúc nào. AI đọc vài dòng đầu và tự đoán cột nào là từ khóa, chiến dịch, ngày đăng...</li>
                        <li>Bạn chỉ sửa cột nào đoán sai, rồi bấm Tạo.</li>
                        <li>Bài tạo ra ở trạng thái "Đã lên kế hoạch", chiến dịch mới ở "Tạm dừng"; chưa viết nội dung, chưa tốn token viết bài.</li>
                    </ul>
                </x-help>
            </h2>
            <button type="button" class="pfm-x" wire:click="closeImport" aria-label="Đóng">×</button>
        </div>

        <div class="pfm-ana" wire:loading.flex wire:target="planFile,analyzeImport,chooseImportSheet" role="status" aria-live="polite">
            <div class="pfm-orb" aria-hidden="true"><i></i><i></i><i></i></div>
            <strong>Đang phân tích file</strong>
            <div class="pfm-msgs" aria-hidden="true">
                <span>Đọc các sheet và tìm dòng tiêu đề...</span>
                <span>Nhận ra cột nào là từ khóa, ngày, chiến dịch...</span>
                <span>Tìm liên kết giữa các sheet...</span>
                <span>Chuẩn bị bản xem trước...</span>
            </div>
            <div class="pfm-ibar" aria-hidden="true"><i></i></div>
            <span class="pfm-muted">Thường mất 5–15 giây, bạn chưa cần làm gì</span>
            <div class="pfm-skel" aria-hidden="true"><b></b><b></b><b></b><b></b></div>
        </div>

        <div class="pfm-b" wire:loading.remove wire:target="planFile,analyzeImport,chooseImportSheet">
            @if ($res)
                <div class="pfm-box pfm-box--ok" role="status">
                    <strong>Đã tạo {{ $res['created'] }} bài</strong>@if ($res['campaigns']) và {{ count($res['campaigns']) }} chiến dịch ({{ implode(', ', $res['campaigns']) }})@endif
                </div>
                <ul class="pfm-res">
                    <li>Bài ở trạng thái "Đã lên kế hoạch"; chiến dịch mới đang "Tạm dừng" cho tới khi bạn bật. Chưa tốn token viết bài.</li>
                    @foreach ($res['notes'] as $n)<li>{{ $n }}</li>@endforeach
                </ul>
                @if ($res['skipped'] || $res['blocked'])
                    <details><summary class="pfm-muted">Dòng không được tạo ({{ count($res['skipped']) + count($res['blocked']) }})</summary>
                        <ul style="margin:.4rem 0 0 1.1rem;font-size:.86rem">@foreach (array_slice(array_merge($res['blocked'], $res['skipped']), 0, 60) as $m)<li>{{ $m }}</li>@endforeach</ul>
                    </details>
                @endif
            @elseif (! $st)
                <label class="pfm-drop">
                    <x-filament::icon icon="heroicon-o-arrow-up-tray" style="width:2rem;height:2rem" />
                    <strong>Chọn file kế hoạch</strong>
                    <input type="file" wire:model="planFile" accept=".xlsx,.ods,.csv" data-plan-file>
                    <span class="pfm-muted">.xlsx · .ods · .csv · tối đa {{ SheetReader::MAX_BYTES / 1048576 }} MB
                        <x-help text="File .xls cũ: mở bằng Excel rồi lưu lại thành .xlsx. Mỗi sheet đọc tối đa {{ SheetReader::MAX_ROWS }} dòng." />
                    </span>
                </label>
                @if ($importError)<div class="pfm-box pfm-box--warn" role="alert">{{ $importError }}</div>@endif
                @if ($ai && ! $ai['ok'])
                    <div class="pfm-box pfm-box--warn">AI chưa sẵn sàng, bạn sẽ tự chọn cột <x-help tone="warning" :text="$ai['reason']" /></div>
                @endif
            @else
                <div class="pfm-bar">
                    <strong>{{ $st['file'] }}</strong>
                    <button type="button" class="pfm-btn" wire:click="discardImport" wire:confirm="Bỏ file này và chọn file khác?">Đổi file</button>
                    <span style="flex:1"></span>
                    @if ($st['analyzed'])
                        <span class="pfm-chip" title="{{ $st['ai']['label'] ?? 'AI' }}{{ ! empty($st['ai']['model']) ? ' · '.$st['ai']['model'] : '' }} · chi phí {{ number_format((float) ($st['ai']['cost_vnd'] ?? 0), 0, ',', '.') }} đ">✓ AI đã phân tích</span>
                        @if (! empty($st['section_rows']))<span class="pfm-chip" title="AI thấy file chia nhóm bằng dòng đề mục: tên đề mục được dùng làm tên chiến dịch cho các bài bên dưới">{{ count($st['section_rows']) }} dòng đề mục → chiến dịch</span>@endif
                        @if (! empty($st['links']))<span class="pfm-chip" title="AI nối sheet chính với: {{ implode(', ', array_column($st['links'], 'sheet')) }} (tra tên, mô tả, ngày của chiến dịch theo mã)">⇄ {{ implode(', ', array_column($st['links'], 'sheet')) }}</span>@endif
                        @if ($st['notes'])<x-help title="Ghi chú của AI">@foreach ($st['notes'] as $n)<p>{{ $n }}</p>@endforeach</x-help>@endif
                    @endif
                    <button type="button" class="pfm-btn" wire:click="analyzeImport">{{ $st['analyzed'] ? '↻ Phân tích lại' : 'Nhờ AI phân tích' }}</button>
                </div>
                @if ($importError)<div class="pfm-box pfm-box--warn" role="alert">{{ $importError }}</div>@endif
                @if ($st['analyzed'] && ! ($st['is_plan'] ?? true))<div class="pfm-box pfm-box--warn">File này có vẻ không phải kế hoạch nội dung. Kiểm tra kỹ trước khi tạo.</div>@endif


                @php
                    $usedCols = count(array_filter($st['columns'], fn ($c) => ($c['field'] ?? 'ignore') !== 'ignore'));
                    $dataCols = count(array_filter($st['columns'], fn ($c) => $c['samples'] !== []));
                    $campName = fn ($id) => (string) ($campaigns->firstWhere('id', (int) $id)?->name ?? '');
                    $dest = null; $hint = '';
                    if ($pv) {
                        $names = $pv['campaign_names'] ?? [];
                        $more = $pv['campaigns'] - count($names);
                        $dest = match (true) {
                            $importTarget === PlanFileImporter::TARGET_NEW => 'chiến dịch mới «'.(trim($importNewName) !== '' ? trim($importNewName) : '(chưa nhập tên)').'»',
                            $importTarget === PlanFileImporter::TARGET_SOLO => 'bài lẻ (không thuộc chiến dịch nào)',
                            $importTarget === PlanFileImporter::TARGET_BY_COLUMN => $pv['campaigns'] > 0
                                ? ($pv['campaigns'] === 1 ? 'chiến dịch «'.$names[0].'»' : $pv['campaigns'].' chiến dịch: '.implode(', ', array_map(fn ($n) => '«'.$n.'»', $names)).($more > 0 ? ' và '.$more.' chiến dịch khác' : ''))
                                : 'bài lẻ (file không ghi chiến dịch)',
                            default => 'chiến dịch «'.$campName($importTarget).'»',
                        };
                    }
                    $hint = match (true) {
                        $importTarget === PlanFileImporter::TARGET_NEW => 'Tất cả bài vào một chiến dịch mới, ở trạng thái Tạm dừng. Bạn bật khi sẵn sàng.',
                        $importTarget === PlanFileImporter::TARGET_SOLO => 'Bài lẻ không thuộc chiến dịch nào nên không có lịch chạy tự động.',
                        $importTarget === PlanFileImporter::TARGET_BY_COLUMN => 'Dùng tên chiến dịch ở cột (hoặc dòng đề mục) trong file. Dòng không có tên sẽ thành bài lẻ.',
                        default => 'Thêm tất cả bài vào chiến dịch đã chọn.',
                    };
                @endphp

                <section class="pfm-card" aria-labelledby="pfm-z-res">
                    <h3 class="pfm-zt" id="pfm-z-res">Kết quả khi bấm Tạo</h3>
                    @if ($pv)
                        <div class="pfm-head">
                            @if ($pv['count'] > 0 && $pv['solo'] > 0 && $pv['solo'] < $pv['count'])
                                <b>{{ $pv['count'] }} bài</b>: <b>{{ $pv['count'] - $pv['solo'] }}</b> vào <b>{{ $dest }}</b>, <b>{{ $pv['solo'] }}</b> là bài lẻ
                            @elseif ($pv['count'] > 0)
                                <b>{{ $pv['count'] }} bài</b> sẽ vào <b>{{ $dest }}</b>
                            @else
                                <b>Chưa có bài nào để tạo</b>
                            @endif
                        </div>
                    @else
                        <div class="pfm-head"><b>Chưa xem trước được</b>: chọn một cột làm "Từ khóa chính" trong mục "Chỉnh cột" bên dưới.</div>
                    @endif

                    <div class="pfm-where">
                        <label for="pfm-target">Đưa vào</label>
                        <select id="pfm-target" class="pfm-in" wire:model.live="importTarget">
                            <option value="{{ PlanFileImporter::TARGET_BY_COLUMN }}">Theo chiến dịch ghi trong file</option>
                            <option value="{{ PlanFileImporter::TARGET_NEW }}">Gom vào một chiến dịch mới</option>
                            <option value="{{ PlanFileImporter::TARGET_SOLO }}">Bài lẻ (không thuộc chiến dịch)</option>
                            @foreach ($campaigns as $camp)<option value="{{ $camp->id }}">Thêm vào: {{ $camp->name }}</option>@endforeach
                        </select>
                        @if ($importTarget === PlanFileImporter::TARGET_NEW)
                            <input type="text" class="pfm-in" wire:model.blur="importNewName" maxlength="120" placeholder="Tên chiến dịch mới" aria-label="Tên chiến dịch mới" style="min-width:14rem">
                        @endif
                        <x-help title="Đưa vào đâu"><p>{{ $hint }}</p><p>Bài tạo ra ở trạng thái "Đã lên kế hoạch", chiến dịch mới ở "Tạm dừng"; chưa viết nội dung, chưa tốn token.</p></x-help>
                    </div>

                    @if ($pv)
                        @php
                            $attn = [];
                            if ($pv['count'] > 0 && $pv['solo'] > 0) {
                                $attn[] = 'solo';
                            }
                            if ($pv['problem_count'] > 0) {
                                $attn[] = 'problems';
                            }
                            if ($pv['over_limit']) {
                                $attn[] = 'over';
                            }
                            if (! empty($pv['unlinked'])) {
                                $attn[] = 'unlinked';
                            }
                            if (! ($pv['dates_ok'] ?? true) && $pv['dated'] > 0) {
                                $attn[] = 'dates';
                            }
                        @endphp
                        @if ($pv['count'] > 0 && $attn === [])
                            <div class="pfm-box pfm-box--ok">✓ Không có gì cần chú ý. Bấm "Tạo {{ $pv['count'] }} bài" khi sẵn sàng.</div>
                        @elseif ($attn !== [])
                            <div class="pfm-attn" role="status">
                                <strong>Cần chú ý ({{ count($attn) }})</strong>
                                <ul>
                                    @if (in_array('solo', $attn, true))
                                        <li><b>{{ $pv['solo'] }} bài sẽ thành bài lẻ</b>, không thuộc chiến dịch nào nên không có lịch chạy tự động.
                                            @if ($importTarget !== PlanFileImporter::TARGET_NEW)
                                                <button type="button" class="pfm-btn pfm-btn--go" wire:click="importIntoNewCampaign">Gom vào chiến dịch mới</button>
                                            @endif
                                        </li>
                                    @endif
                                    @if (in_array('problems', $attn, true))
                                        <li><b>{{ $pv['problem_count'] }} dòng bị bỏ qua</b> (trùng, thiếu từ khóa hoặc không hợp lệ).
                                            <details class="pfm-inl"><summary>Xem lý do</summary>
                                                <ul>@foreach ($pv['problems'] as $p)<li>Dòng {{ $p['row'] }}@if ($p['keyword'] !== '') ({{ $p['keyword'] }})@endif: {{ $p['reason'] }}</li>@endforeach</ul>
                                            </details>
                                        </li>
                                    @endif
                                    @if (in_array('over', $attn, true))<li>Mỗi lần chỉ tạo tối đa {{ PlanFileImporter::MAX_ITEMS }} bài; chạy lại file sau để tạo phần còn lại (bài đã có tự bỏ qua).</li>@endif
                                    @if (in_array('unlinked', $attn, true))<li>Không tìm thấy trong sheet liên kết: {{ implode('; ', $pv['unlinked']) }}. Các dòng đó giữ nguyên giá trị gốc.</li>@endif
                                    @if (in_array('dates', $attn, true))<li>Chưa lưu được ngày dự kiến: hệ thống cần chạy migration thêm cột ngày (php artisan migrate). Bài vẫn được tạo, chưa có ngày.</li>@endif
                                </ul>
                            </div>
                        @endif


                        @if ($pv['rows'])
                            <div class="pfm-wrap">
                                <table class="pfm-tbl">
                                    <thead><tr><th>Từ khóa chính</th><th>Chiến dịch</th>@if ($pv['dated'] > 0)<th>Ngày</th>@endif</tr></thead>
                                    <tbody>
                                        @foreach (array_slice($pv['rows'], 0, 5) as $r)
                                            <tr><td>{{ $r['keyword'] }}</td><td>{{ $r['campaign'] ?? 'Bài lẻ' }}</td>@if ($pv['dated'] > 0)<td>{{ $r['date'] ? \Carbon\Carbon::parse($r['date'])->format('d/m/Y') : '—' }}</td>@endif</tr>
                                        @endforeach
                                    </tbody>
                                </table>
                            </div>
                            @if ($pv['count'] > 5)<p class="pfm-muted" style="margin:.3rem 0 0">+{{ $pv['count'] - 5 }} bài nữa</p>@endif
                        @endif
                    @endif
                </section>

                <details class="pfm-more" @if (! $pv) open @endif>
                    <summary>Chỉnh cột và tùy chọn <span class="pfm-muted">· dùng {{ $usedCols }}/{{ $dataCols }} cột · {{ ! empty($st['analyzed']) ? 'AI đã đoán' : 'đoán theo tên cột' }}, chỉ sửa khi sai</span></summary>
                    <div class="pfm-more-body">
                        @if ($pv && $pv['count'] > 0)
                            <p class="pfm-muted" style="margin:0 0 .5rem">{{ $pv['dated'] }}/{{ $pv['count'] }} bài có ngày · {{ $pv['with_notes'] ?? 0 }} có chỉ dẫn viết · {{ $pv['with_secondary'] ?? 0 }} có từ khóa phụ@if (($pv['volumes'] ?? 0) > 0) · {{ $pv['volumes'] }} có lượng tìm @endif
                                @foreach (($pv['link_hits'] ?? []) as $sheetName => $n) · ⇄ {{ $n }} từ «{{ \Illuminate\Support\Str::limit($sheetName, 22) }}»@endforeach</p>
                        @endif
                        <p class="pfm-muted" style="margin:0 0 .5rem">Ô bên phải là trường sẽ nhập vào. Cột không cần thì để "Bỏ qua". Chỉ cần một cột "Từ khóa chính" là tạo được bài.</p>
<div class="pfm-wrap">
                    <table class="pfm-tbl">
                        <thead><tr><th>Cột</th><th>Ví dụ</th><th>Nhập vào</th></tr></thead>
                        <tbody>
                            @foreach ($st['columns'] as $i => $c)
                                @if ($c['header'] !== '' || $c['samples'] !== [] || ($c['field'] ?? 'ignore') !== 'ignore')
                                    <tr class="{{ ($c['field'] ?? 'ignore') === 'ignore' ? 'pfm-off' : '' }}">
                                        <td><strong>{{ $c['header'] !== '' ? $c['header'] : 'Cột '.$c['letter'] }}</strong> <span class="pfm-muted">{{ $c['letter'] }}</span></td>
                                        <td class="pfm-muted">{{ implode(' · ', $c['samples']) ?: '—' }}</td>
                                        <td>
                                            <select class="pfm-in" wire:model.live="importState.columns.{{ $i }}.field" aria-label="Cột {{ $c['letter'] }} nhập vào" @if (! empty($c['reason'])) title="{{ $c['reason'] }}" @endif>
                                                @foreach ($fieldGroups as $group => $opts)
                                                    <optgroup label="{{ $group }}">@foreach ($opts as $k => $label)<option value="{{ $k }}">{{ $label }}</option>@endforeach</optgroup>
                                                @endforeach
                                            </select>
                                        </td>
                                    </tr>
                                @endif
                            @endforeach
                        </tbody>
                    </table>
                </div>
                        <details class="pfm-adv">
                    <summary>Tùy chọn</summary>
                    <div class="pfm-bar" style="margin-top:.5rem">
                        @if (count($st['sheets']) > 1)
                            <label>Sheet
                                <select class="pfm-in" wire:change="chooseImportSheet($event.target.value)">
                                    @foreach ($st['sheets'] as $s)<option value="{{ $s['name'] }}" @selected($s['name'] === $st['sheet'])>{{ $s['name'] }} ({{ $s['total'] }} dòng)</option>@endforeach
                                </select>
                            </label>
                        @endif
                        <label>Dòng chứa tên cột
                            <input type="number" min="0" max="50" class="pfm-in" style="width:4.5rem" wire:model.live.debounce.700ms="importState.header_row">
                        </label>
                        <x-help title="Dòng chứa tên cột"><p>Dòng có các tên như "Từ khóa", "Ngày đăng". Hệ thống tự tìm; chỉ đổi nếu danh sách cột ở bước 1 trông sai. Nhập 0 nếu file không có dòng tên cột.</p></x-help>
                    </div>
                </details>
                    </div>
                </details>
            @endif
        </div>

        <div class="pfm-f" wire:loading.remove wire:target="planFile,analyzeImport,chooseImportSheet">
            @if ($res)
                <x-filament::button x-on:click="$wire.closeImport().then(() => document.querySelector('.ss-tab[data-key=cac-ke-hoach]')?.click())">Xem các kế hoạch</x-filament::button>
            @else
                <x-filament::button color="gray" wire:click="closeImport">Để sau</x-filament::button>
                @if ($st)
                    <x-filament::button wire:click="runImport" wire:loading.attr="disabled" wire:target="runImport" :disabled="! $pv || $pv['count'] === 0">
                        {{ $pv && $pv['count'] > 0 ? 'Tạo '.$pv['count'].' bài' : 'Tạo bài' }}
                    </x-filament::button>
                @endif
            @endif
        </div>
    </div>
</div>
