<?php

namespace App\Services\Agent\Pipeline;

use App\Filament\Support\Agent\AgentFlowBlueprint;
use App\Filament\Support\Agent\FeatureRegistry;
use App\Models\AgentRunStep;

/**
 * AG-S12: bản đồ 9 giai đoạn của pipeline (AI_AUTOMATION_DESIGN_ANALYSIS §7). Trạng thái mỗi giai đoạn được TÍNH từ
 * FeatureRegistry (đã có luật "active phải có bằng chứng") và từ agent_run_steps, không ghi tay.
 *
 * Luật cứng: một giai đoạn chỉ được "Đang hoạt động" khi MỌI tính năng liên quan đều active có bằng chứng
 * VÀ đã có bước chạy thật (AI thật) trong 30 ngày. Giai đoạn không có bước nào trong phiên chạy thì tối đa "Dữ liệu minh họa".
 */
final class PipelineBlueprint
{
    /** Nút "Chạy lại" / "Tạm dừng pipeline" chỉ hiện khi true. Chưa có job nền + API pipeline (AG-B10) nên false. */
    public static function controlsWired(): bool
    {
        return false;
    }

    /** @return list<array<string, mixed>> */
    public static function definitions(): array
    {
        return [
            ['n' => 1, 'id' => 'site', 'title' => 'Hiểu website', 'role' => 'Điều phối', 'nodes' => ['site.intake', 'site.crawl'], 'features' => ['website.connect', 'audit.rescan'], 'run_stages' => [],
                'source' => 'Website đã kết nối và dữ liệu crawler (bảng crawled_urls).', 'gate' => null, 'dev1' => ['Quét lại website từ giao diện cần job nền (AG-B10).']],
            ['n' => 2, 'id' => 'audit', 'title' => 'Audit', 'role' => 'Kiểm toán', 'nodes' => ['audit.technical', 'audit.content', 'audit.score'], 'features' => ['audit.view'], 'run_stages' => [],
                'source' => 'Đọc dữ liệu crawler thật; điểm và xu hướng còn là dữ liệu minh họa.', 'gate' => null, 'dev1' => ['Điểm audit tính từ crawler thật và job nền chạy theo lịch.']],
            ['n' => 3, 'id' => 'keywords', 'title' => 'Từ khóa', 'role' => 'Nghiên cứu', 'nodes' => ['kw.research', 'kw.cluster'], 'features' => ['keywords'], 'run_stages' => [],
                'source' => 'Từ khóa nhập tay/CSV. Chưa có nguồn dữ liệu từ khóa thật.', 'gate' => null, 'dev1' => ['Nguồn dữ liệu từ khóa thật và job gom cụm.']],
            ['n' => 4, 'id' => 'competitors', 'title' => 'Đối thủ', 'role' => 'Phân tích', 'nodes' => ['comp.gap'], 'features' => ['competitors'], 'run_stages' => [],
                'source' => 'Số liệu đối thủ minh họa, chưa crawl đối thủ thật.', 'gate' => null, 'dev1' => ['Crawl đối thủ trong allowlist chống SSRF.']],
            ['n' => 5, 'id' => 'plan', 'title' => 'Kế hoạch', 'role' => 'Chiến lược', 'nodes' => ['strategy.plan', 'brief.build'], 'features' => ['plan'], 'run_stages' => [],
                'source' => 'Kế hoạch tuần do người xếp; chưa có bước AI lập kế hoạch tự động.', 'gate' => null, 'dev1' => ['Bước lập kế hoạch tự động và kiểm tra phù hợp website.']],
            ['n' => 6, 'id' => 'write', 'title' => 'Viết bài', 'role' => 'Người viết', 'nodes' => ['guard', 'brief', 'write', 'retry'], 'features' => ['run.slice', 'content.generate'], 'run_stages' => ['guard', 'brief', 'write', 'retry'],
                'source' => 'Phiên chạy của đội AI (agent_runs). Mới được kiểm bằng test giả lập, chưa qua model thật ở quy mô.', 'gate' => 'Có bản nháp được tạo', 'dev1' => []],
            ['n' => 7, 'id' => 'quality', 'title' => 'Kiểm tra chất lượng', 'role' => 'Biên tập (tự động)', 'nodes' => ['risk', 'grade', 'repair', 'decision'], 'features' => ['content.quality', 'ai.selfrepair'], 'run_stages' => ['risk', 'grade', 'repair', 'decision'],
                'source' => 'ContentQualityChecker (luật) và vòng tự sửa tối đa 3 vòng.', 'gate' => 'Không còn lỗi chặn P0', 'dev1' => []],
            ['n' => 8, 'id' => 'publish', 'title' => 'Xuất bản', 'role' => 'Hệ thống', 'nodes' => ['handoff (bản nháp)', 'tự đăng: khóa'], 'features' => ['run.slice', 'publish.schedule'], 'run_stages' => ['handoff'],
                'source' => 'Bậc T1: chỉ tạo bản nháp (và đẩy nháp lên CMS ở chế độ Cân bằng). Tự đăng bị khóa tới khi có văn bản chính sách.', 'gate' => 'Không bao giờ tự xuất bản ở T1', 'dev1' => []],
            ['n' => 9, 'id' => 'measure', 'title' => 'Đo lường', 'role' => 'Phân tích', 'nodes' => ['perf.measure', 'perf.recommend'], 'features' => ['gsc'], 'run_stages' => [],
                'source' => 'Search Console chưa kết nối.', 'gate' => null, 'dev1' => ['Kết nối Search Console thật và job đo lường.']],
        ];
    }

    /**
     * @return list<array<string, mixed>> mỗi giai đoạn kèm: readiness, evidence_ok, runs (số phiên 30 ngày), step_stats, dev1_tasks
     */
    public static function stages(?\DateTimeInterface $since = null): array
    {
        $since ??= now()->subDays(30);
        $flow = AgentFlowBlueprint::byStage();
        $out = [];
        foreach (self::definitions() as $d) {
            $features = array_values(array_filter(array_map(fn ($id) => FeatureRegistry::find($id), $d['features'])));
            $d['readiness'] = self::readiness($features, $d['run_stages'] !== [], $d['run_stages'], $since);
            $d['feature_rows'] = array_map(fn ($f) => ['title' => $f['title'], 'readiness' => $f['readiness']], $features);
            $d['wired'] = $d['run_stages'] !== [];
            $d['runs'] = null;
            $d['step_stats'] = [];
            $d['gate_rate'] = null;
            if ($d['wired']) {
                $steps = AgentRunStep::query()->whereIn('stage', $d['run_stages'])->whereHas('run', fn ($q) => $q->where('created_at', '>=', $since));
                $d['runs'] = (clone $steps)->distinct()->count('agent_run_id');
                $d['step_stats'] = (clone $steps)->selectRaw('status, count(*) c')->groupBy('status')->pluck('c', 'status')->all();
                if ($d['gate'] !== null && array_sum($d['step_stats']) > 0) {
                    $d['gate_rate'] = ($d['step_stats']['ok'] ?? 0) / array_sum($d['step_stats']);
                }
            }
            $d['dev1_tasks'] = array_values(array_filter(array_merge(
                $d['dev1'],
                array_map(fn ($s) => isset($flow[$s]) ? "{$flow[$s]['dev1']} ({$flow[$s]['task']})" : null, $d['run_stages'])
            )));
            $out[] = $d;
        }

        return $out;
    }

    /**
     * Mức sẵn sàng của giai đoạn = mức THẤP nhất trong các tính năng liên quan; 'active' còn cần bằng chứng và bước chạy thật.
     *
     * @param  list<array<string, mixed>>  $features
     * @param  list<string>  $runStages
     */
    private static function readiness(array $features, bool $wired, array $runStages, \DateTimeInterface $since): string
    {
        $rank = ['idea' => 0, 'not_connected' => 1, 'in_development' => 2, 'demo' => 3, 'active' => 4];
        if ($features === []) {
            return 'idea';
        }
        usort($features, fn ($a, $b) => $rank[$a['readiness']] <=> $rank[$b['readiness']]);
        $worst = $features[0]['readiness'];
        if ($worst !== 'active') {
            return $worst;
        }
        $evidence = collect($features)->every(fn ($f) => trim((string) $f['evidence']) !== '');
        $realSteps = $wired && AgentRunStep::query()->whereIn('stage', $runStages)
            ->whereHas('run', fn ($q) => $q->where('ai_mode', 'real')->where('created_at', '>=', $since))->exists();

        return $evidence && $realSteps ? 'active' : 'in_development';
    }
}
