<?php

namespace App\Services\Knowledge\Discover;

use App\Models\Website;

/**
 * Một lượt "nạp kho theo một câu truy vấn" có CHẨN ĐOÁN: tìm nguồn, lọc nguồn tin cậy, nạp; nếu ra 0 nguồn vì lý do còn cứu được (không có nguồn tin cậy trong kết quả, hoặc kết quả
 * rỗng) thì thử MỘT lần với cách diễn đạt khác trước khi bỏ cuộc. Luôn trả lý do thật của kết quả 0 để người xem biết đó là do nguồn, mạng, trùng kho hay do AI, thay vì chỉ thấy "0 nguồn".
 *
 * Trước đây các bước "Nạp bổ sung kho" chỉ ghi "0 nguồn" mà không nói vì sao: dữ liệu thật cho thấy nguyên nhân không phải model mà là (a) kết quả tìm không có nguồn tin cậy,
 * (b) trang chặn tải (403) hoặc không tải được, (c) nội dung đã có sẵn trong kho. Mỗi nguyên nhân cần cách xử lý khác nhau.
 */
final class KbIntake
{
    /** Cụm thêm vào câu truy vấn ở lần thử lại, theo lĩnh vực; không có thì thêm cụm chung. */
    private const RETRY_HINT = ['health' => 'hướng dẫn chẩn đoán điều trị khuyến cáo chính thức', 'pharma' => 'guideline standard', 'finance' => 'quy định hướng dẫn chính thức', 'legal' => 'quy định văn bản chính thức'];

    public function __construct(private readonly SourceDiscoveryService $svc) {}

    /**
     * @param  array<string,mixed>  $importOpts  tùy chọn truyền cho SourceDiscoveryService::import (brain_id, max_sources, budget_cap_vnd, purpose...)
     * @return array{imp: array<string,mixed>, diagnosis: array<string,mixed>, tried: list<string>}
     */
    public function run(Website $site, string $query, array $importOpts, bool $retry = true): array
    {
        $tried = [];
        $merged = ['imported' => 0, 'items_auto' => 0, 'items_proposed' => 0, 'pages' => [], 'auto_item_ids' => [], 'proposed_item_ids' => [], 'cost_vnd' => 0.0, 'run_id' => null, 'stopped_reason' => null];
        $diag = null;
        $queries = [$query];
        for ($i = 0; $i < count($queries); $i++) {
            $q = $queries[$i];
            $tried[] = $q;
            $d = $this->svc->discover($site, $q);
            $trusted = array_values(array_filter($d['candidates'], fn ($c) => $c['trust']['level'] === 'trusted'));
            $imp = $this->svc->import($site, $trusted, null, $importOpts + ['field' => $d['field'], 'only_trusted' => true,
                'search' => ['query' => $d['query'], 'results' => count($d['candidates']), 'cost_vnd' => $d['cost_vnd'], 'provider' => $d['provider']]]);
            foreach (['imported', 'items_auto', 'items_proposed'] as $k) {
                $merged[$k] += (int) $imp[$k];
            }
            $merged['cost_vnd'] += (float) $imp['cost_vnd'];
            $merged['run_id'] ??= $imp['run_id'];
            $merged['stopped_reason'] ??= $imp['stopped_reason'];
            foreach (['pages', 'auto_item_ids', 'proposed_item_ids'] as $k) {
                $merged[$k] = array_merge($merged[$k], (array) $imp[$k]);
            }
            $diag = self::diagnose($d, $imp);
            if ($retry && $i === 0 && $merged['imported'] === 0 && in_array($diag['code'], ['no_trusted', 'no_results'], true)) {
                $hint = self::RETRY_HINT[(string) ($d['field'] ?? '')] ?? 'tài liệu chính thức nguồn uy tín';
                $queries[] = mb_substr(trim($query.' '.$hint), 0, 300);
            }
        }

        return ['imp' => $merged, 'diagnosis' => $diag ?? self::diagnose(['candidates' => [], 'results_total' => 0], $merged), 'tried' => $tried];
    }

    /**
     * Vì sao một lượt nạp ra (hoặc không ra) nguồn. code: ok | covered (nội dung đã có trong kho) | no_results | no_trusted | fetch_failed | too_short | no_items | budget | duplicate_run | stopped.
     *
     * @param  array<string,mixed>  $d  kết quả discover()
     * @param  array<string,mixed>  $imp  kết quả import()
     * @return array{code: string, label: string, hint: string, counts: array<string,int>, ok: bool}
     */
    public static function diagnose(array $d, array $imp): array
    {
        $cands = (array) ($d['candidates'] ?? []);
        $trusted = count(array_filter($cands, fn ($c) => ($c['trust']['level'] ?? '') === 'trusted'));
        $pages = (array) ($imp['pages'] ?? []);
        $by = static fn (string $err) => count(array_filter($pages, fn ($p) => ($p['error'] ?? null) === $err));
        $failed = count(array_filter($pages, fn ($p) => ! ($p['ok'] ?? false) && ! in_array($p['error'] ?? '', ['unchanged', 'too_short'], true)));
        $counts = ['found' => (int) ($d['results_total'] ?? count($cands)), 'candidates' => count($cands), 'trusted' => $trusted, 'fetch_failed' => $failed, 'unchanged' => $by('unchanged'), 'too_short' => $by('too_short'), 'imported' => (int) ($imp['imported'] ?? 0), 'items' => (int) ($imp['items_auto'] ?? 0) + (int) ($imp['items_proposed'] ?? 0)];
        $mk = fn (string $code, string $label, string $hint, bool $ok = false) => ['code' => $code, 'label' => $label, 'hint' => $hint, 'counts' => $counts, 'ok' => $ok];

        if (($imp['stopped_reason'] ?? null) === 'duplicate') {
            return $mk('duplicate_run', 'Vừa chạy đúng lượt này cách đây ít phút nên không chạy lại.', 'Chờ vài phút hoặc đổi chủ đề tìm.', true);
        }
        if ($counts['imported'] > 0 && $counts['items'] > 0) {
            return $mk('ok', 'Đã nạp '.$counts['imported'].' nguồn, '.$counts['items'].' mục.', '', true);
        }
        if ($counts['imported'] > 0) {
            return $mk('no_items', 'Đã tải '.$counts['imported'].' nguồn nhưng AI không rút được mục kiến thức nào có đoạn nguyên văn hợp lệ.', 'Nguồn có thể toàn số liệu/biểu bảng hoặc không đúng chủ đề; thử chủ đề cụ thể hơn hoặc thêm nguồn tay.');
        }
        if (in_array($imp['stopped_reason'] ?? null, ['budget', 'emergency_stop'], true)) {
            return $mk('stopped', ($imp['stopped_reason'] === 'budget' ? 'Chạm trần chi phí' : 'Đang dừng khẩn cấp').' nên chưa nạp.', $imp['stopped_reason'] === 'budget' ? 'Nâng trần chi phí phiên nếu muốn nạp thêm.' : 'Bỏ dừng khẩn cấp rồi chạy lại.');
        }
        if ($cands === []) {
            return $mk('no_results', 'Tìm trên web không ra kết quả dùng được'.($counts['found'] > 0 ? ' ('.$counts['found'].' kết quả đều bị loại hoặc là tên miền vừa lỗi)' : '').'.', 'Diễn đạt chủ đề cụ thể hơn, hoặc kiểm tra khóa tìm kiếm web.');
        }
        if ($trusted === 0) {
            $doms = implode(', ', array_slice(array_unique(array_map(fn ($c) => (string) $c['domain'], $cands)), 0, 4));

            return $mk('no_trusted', 'Tìm ra '.count($cands).' kết quả nhưng không có nguồn nào thuộc danh sách tin cậy ('.$doms.'). Hệ thống chỉ tự nạp nguồn tin cậy.', 'Thêm tên miền uy tín vào danh sách tin cậy của lĩnh vực, hoặc thêm nguồn tay rồi duyệt.');
        }
        if ($counts['unchanged'] > 0 && $counts['unchanged'] >= $counts['candidates'] - $failed - $counts['too_short']) {
            return $mk('covered', 'Các nguồn tin cậy tìm được đều đã có trong kho từ trước nên không cần nạp thêm.', 'Không phải lỗi: kho đã có nguồn này. Nếu bài vẫn thiếu căn cứ, thiếu ở khía cạnh khác: hỏi bộ phận nghiên cứu tìm theo cách hỏi khác.', true);
        }
        if ($failed > 0) {
            $errs = implode(', ', array_slice(array_unique(array_map(fn ($p) => (string) ($p['error'] ?? '?'), array_filter($pages, fn ($p) => ! ($p['ok'] ?? false)))), 0, 3));

            return $mk('fetch_failed', 'Có '.$trusted.' nguồn tin cậy nhưng không tải được trang ('.$failed.' lỗi: '.$errs.'; trang chặn máy chủ hoặc lỗi mạng).', 'Thử lại sau, hoặc tải tay nội dung của nguồn đó (dán văn bản / tải tệp) vào kho.');
        }

        return $mk('too_short', 'Các trang tải về quá ít chữ để rút kiến thức.', 'Thử nguồn khác hoặc dán văn bản trực tiếp.');
    }
}
