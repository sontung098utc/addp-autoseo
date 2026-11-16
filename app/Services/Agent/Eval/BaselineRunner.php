<?php

namespace App\Services\Agent\Eval;

use App\Contracts\AiProviderInterface;
use App\Contracts\StructuredAiInterface;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\Website;
use App\Services\Agent\Refresh\AgentRunDispatcher;
use App\Services\Agent\Run\RunPolicy;

/**
 * Q0.3: đo BASELINE của luồng hiện tại trên bộ từ khóa chuẩn.
 *  - mode `existing`: chấm các bài ĐÃ CÓ khớp từ khóa chuẩn (không sinh bài mới, không tốn chi phí sinh);
 *  - mode `generate`: tạo bài (nếu chưa có) trong một website rồi chạy đúng luồng hiện tại, sau đó chấm bản cuối. TỐN CHI PHÍ và GHI DB.
 * Không đổi hành vi hệ thống. Số đo cần AI mà chưa có AI là `not_run` (xem ArticleMetrics).
 */
final class BaselineRunner
{
    public function __construct(
        private readonly ?StructuredAiInterface $judgeAi = null,
        private readonly ?\Closure $sleep = null,
    ) {
    }

    /**
     * @param  list<GoldenQuery>  $queries
     * @return array<string, mixed>
     */
    public function existing(array $queries, ?int $websiteId = null): array
    {
        $metrics = new ArticleMetrics($this->judgeAi, null, $this->sleep);
        $byKey = [];
        $articles = Article::query()->when($websiteId, fn ($q) => $q->where('website_id', $websiteId))->get(['id', 'website_id', 'primary_keyword']);
        foreach ($articles as $a) {
            $byKey[GoldenQuery::normalizeKeyword((string) $a->primary_keyword)][] = $a;
        }
        $rows = [];
        foreach ($queries as $q) {
            $found = $byKey[GoldenQuery::normalizeKeyword($q->keyword)] ?? [];
            if ($found === []) {
                $rows[] = $this->emptyRow($q, 'no_article', 'Chưa có bài nào cho từ khóa này.');

                continue;
            }
            foreach ($found as $a) {
                $v = $this->latestVersion($a->id);
                if ($v === null) {
                    $rows[] = $this->emptyRow($q, 'no_version', 'Bài chưa có phiên bản có nội dung.', $a->id);

                    continue;
                }
                $rows[] = ['status' => 'measured', 'golden_id' => $q->id, 'risk' => $q->risk, 'keyword' => $q->keyword, 'run' => null, 'metrics' => $metrics->measure($v, $a, $q)];
            }
        }

        return $this->wrap('existing', $rows);
    }

    /**
     * @param  list<GoldenQuery>  $queries
     * @return array<string, mixed>
     */
    public function generate(array $queries, Website $site, AiProviderInterface $ai, ?RunPolicy $policy = null, ?\Closure $progress = null): array
    {
        $metrics = new ArticleMetrics($this->judgeAi, null, $this->sleep);
        $rows = [];
        foreach ($queries as $i => $q) {
            $article = Article::firstOrCreate(
                ['website_id' => $site->id, 'primary_keyword' => $q->keyword],
                ['search_intent' => $q->intent, 'workflow_status' => 'planned'],
            );
            $run = AgentRunDispatcher::run($article, $ai, null, 'eval', policy: $policy);
            $v = $run->final_version_id ? ArticleVersion::find($run->final_version_id) : null;
            $runInfo = ['id' => $run->id, 'status' => $run->status, 'ai_mode' => $run->ai_mode, 'repair_rounds' => (int) $run->repair_rounds, 'cost_vnd' => (float) $run->cost_vnd, 'outcome' => $run->outcome_reason];
            $rows[] = $v === null
                ? ['status' => 'no_version', 'golden_id' => $q->id, 'risk' => $q->risk, 'keyword' => $q->keyword, 'run' => $runInfo, 'metrics' => null, 'reason' => 'Phiên không tạo ra phiên bản bài: '.($run->outcome_reason ?: $run->status)]
                : ['status' => 'measured', 'golden_id' => $q->id, 'risk' => $q->risk, 'keyword' => $q->keyword, 'run' => $runInfo, 'metrics' => $metrics->measure($v, $article, $q)];
            if ($progress) {
                $progress($i + 1, count($queries), $q, end($rows));
            }
        }

        return $this->wrap('generate', $rows);
    }

    private function latestVersion(int $articleId): ?ArticleVersion
    {
        return ArticleVersion::query()->where('article_id', $articleId)->whereNotNull('html_content')->where('html_content', '!=', '')->orderByDesc('version')->first();
    }

    /** @return array<string, mixed> */
    private function emptyRow(GoldenQuery $q, string $status, string $reason, ?int $articleId = null): array
    {
        return ['status' => $status, 'golden_id' => $q->id, 'risk' => $q->risk, 'keyword' => $q->keyword, 'article_id' => $articleId, 'run' => null, 'metrics' => null, 'reason' => $reason];
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    private function wrap(string $mode, array $rows): array
    {
        return [
            'schema' => 'autoseo-eval-baseline/1',
            'generated_at' => now()->toIso8601String(),
            'mode' => $mode,
            'judge_ai' => $this->judgeAi !== null,
            'rows' => $rows,
            'summary' => self::summarize($rows),
        ];
    }

    /**
     * Trung bình theo hạng rủi ro. Chỉ tính các bài ĐÃ đo; mục `not_run` không vào trung bình (không coi là 0).
     *
     * @param  list<array<string, mixed>>  $rows
     * @return array<string, mixed>
     */
    public static function summarize(array $rows): array
    {
        $acc = [];
        $skipped = ['no_article' => 0, 'no_version' => 0];
        foreach ($rows as $r) {
            if ($r['status'] !== 'measured') {
                $skipped[$r['status']] = ($skipped[$r['status']] ?? 0) + 1;

                continue;
            }
            $m = $r['metrics'];
            foreach (['all', $r['risk']] as $g) {
                $acc[$g]['n'] = ($acc[$g]['n'] ?? 0) + 1;
                $acc[$g]['hygiene'][] = $m['hygiene']['score'];
                if ($m['intent']['coverage'] !== null) {
                    $acc[$g]['intent'][] = $m['intent']['coverage'];
                }
                if ($m['claims']['cited_ratio'] !== null) {
                    $acc[$g]['cited_ratio'][] = $m['claims']['cited_ratio'];
                }
                if ($m['claims']['judge']['supported_ratio'] !== null) {
                    $acc[$g]['supported_ratio'][] = $m['claims']['judge']['supported_ratio'];
                }
                $acc[$g]['no_sources'] = ($acc[$g]['no_sources'] ?? 0) + ($m['has_sources'] ? 0 : 1);
            }
        }
        $avg = fn (?array $a) => $a === null || $a === [] ? null : round(array_sum($a) / count($a), 4);
        $out = [];
        foreach ($acc as $g => $a) {
            $out[$g] = [
                'n' => $a['n'],
                'hygiene' => $avg($a['hygiene'] ?? null),
                'intent_coverage' => $avg($a['intent'] ?? null),
                'intent_n' => count($a['intent'] ?? []),
                'cited_ratio' => $avg($a['cited_ratio'] ?? null),
                'supported_ratio' => $avg($a['supported_ratio'] ?? null),
                'supported_n' => count($a['supported_ratio'] ?? []),
                'no_sources' => $a['no_sources'] ?? 0,
            ];
        }
        $out['skipped'] = $skipped;

        return $out;
    }

    /** Báo cáo dạng Markdown (đọc được, không giấu mục chưa chạy). @param array<string,mixed> $report */
    public static function markdown(array $report): string
    {
        $pct = fn ($v) => $v === null ? 'chưa chạy' : number_format($v * 100, 1).'%';
        $md = "# Báo cáo baseline chất lượng bài ({$report['mode']}, {$report['generated_at']})\n\n";
        $md .= 'Giám khảo AI: '.($report['judge_ai'] ? 'có' : '**chưa có** (các số đo cần AI ghi "chưa chạy", không phải 0)')."\n\n";
        $md .= "## Tổng hợp theo hạng rủi ro\n\n| Nhóm | Số bài | Vệ sinh SEO (TB) | Độ phủ ý định | Câu sự thật có trích dẫn | Cặp trích dẫn được hỗ trợ | Bài không có nguồn |\n|---|---|---|---|---|---|---|\n";
        foreach ($report['summary'] as $g => $s) {
            if ($g === 'skipped') {
                continue;
            }
            $md .= "| {$g} | {$s['n']} | ".($s['hygiene'] ?? '-').' | '.$pct($s['intent_coverage']).($s['intent_n'] ? " (n={$s['intent_n']})" : '').' | '.$pct($s['cited_ratio']).' | '.$pct($s['supported_ratio']).($s['supported_n'] ? " (n={$s['supported_n']})" : '')." | {$s['no_sources']} |\n";
        }
        $sk = $report['summary']['skipped'] ?? [];
        $md .= "\nKhông đo được: chưa có bài {$sk['no_article']} từ khóa, chưa có phiên bản {$sk['no_version']} bài.\n\n";
        $md .= "## Chi tiết\n\n| Từ khóa | Rủi ro | Từ | Vệ sinh | Độ phủ ý định | Câu sự thật (có trích/tổng) | Trích dẫn được hỗ trợ | Ghi chú |\n|---|---|---|---|---|---|---|---|\n";
        foreach ($report['rows'] as $r) {
            if ($r['status'] !== 'measured') {
                $md .= "| {$r['keyword']} | {$r['risk']} | - | - | - | - | - | {$r['reason']} |\n";

                continue;
            }
            $m = $r['metrics'];
            $md .= "| {$r['keyword']} | {$r['risk']} | {$m['words']} | {$m['hygiene']['score']} | ".$pct($m['intent']['coverage']).' | '
                ."{$m['claims']['cited_fact_sentences']}/{$m['claims']['fact_sentences']} | ".$pct($m['claims']['judge']['supported_ratio']).' | '.($m['has_sources'] ? '' : 'không có nguồn').' |'."\n";
        }

        return $md;
    }
}
