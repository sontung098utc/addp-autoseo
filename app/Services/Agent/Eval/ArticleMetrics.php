<?php

namespace App\Services\Agent\Eval;

use App\Contracts\StructuredAiInterface;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Services\Agent\Run\RunScorer;
use App\Services\Content\CitationSupportJudge;
use App\Services\Content\Quality\ContentQualityChecker;
use App\Services\Content\VietnameseText;
use App\Services\Knowledge\AiJson;

/**
 * Q0.2: BỐN SỐ ĐO cho một phiên bản bài, thay cho một điểm 0–100:
 *  1. hygiene  — vệ sinh SEO (điểm RunScorer hiện có, chỉ kiểm hình thức);
 *  2. intent   — độ phủ ý định: bài trả lời bao nhiêu câu hỏi bắt buộc (cần từ khóa chuẩn + AI);
 *  3. claims   — khẳng định có nguồn: tỉ lệ câu sự thật có dấu trích dẫn (tất định) và tỉ lệ cặp (câu, mục trích) được AI kiểm là hỗ trợ;
 *  4. human    — phán quyết người: để null ở đây, điền từ tệp nhãn (agent:eval-labels).
 * Mục cần AI mà chưa có AI trả `status: not_run` kèm lý do; KHÔNG điền 0 hay giá trị giả (AGENTS.md §1.2).
 */
final class ArticleMetrics
{
    public function __construct(
        private readonly ?StructuredAiInterface $judgeAi = null,
        private readonly ?ContentQualityChecker $checker = null,
        /** Bộ ngủ tiêm được để test không chờ thật. */
        private readonly ?\Closure $sleep = null,
    ) {
    }

    /**
     * @return array<string, mixed>
     */
    public function measure(ArticleVersion $version, ?Article $article = null, ?GoldenQuery $query = null): array
    {
        $article ??= $version->article;
        $html = (string) $version->html_content;

        return [
            'version_id' => $version->id,
            'article_id' => $version->article_id,
            'keyword' => $article?->primary_keyword,
            'golden_id' => $query?->id,
            'words' => VietnameseText::wordCountHtml($html),
            'has_sources' => ! empty($version->sources_cited),
            'model' => $version->ai_model,
            'hygiene' => $this->hygiene($version, $article),
            'intent' => $this->intent($html, $query),
            'claims' => $this->claims($html),
            'human' => null,
        ];
    }

    /** @return array{score:int, counts:array<string,int>, blocking:list<string>} */
    private function hygiene(ArticleVersion $version, ?Article $article): array
    {
        $report = ($this->checker ?? new ContentQualityChecker)->check($version, $article);

        return ['score' => RunScorer::score($report), 'counts' => $report->counts(), 'blocking' => $report->blockingCodes()];
    }

    /** @return array<string, mixed> */
    private function intent(string $html, ?GoldenQuery $query): array
    {
        if ($query === null) {
            return ['status' => 'not_run', 'coverage' => null, 'reason' => 'Bài không khớp từ khóa chuẩn nào nên không có câu hỏi bắt buộc để đối chiếu.'];
        }
        if ($this->judgeAi === null) {
            return ['status' => 'not_run', 'coverage' => null, 'reason' => 'Chưa có AI cho bước giám khảo.'];
        }

        return (new IntentCoverageJudge($this->aiJson()))->judge($html, $query);
    }

    /** @return array<string, mixed> */
    private function claims(string $html): array
    {
        $f = (new FactSentenceDetector)->analyze($html);
        $out = [
            'fact_sentences' => $f['facts'],
            'cited_fact_sentences' => $f['cited'],
            'cited_ratio' => $f['ratio'],
            'uncited_examples' => array_slice(array_map(fn ($s) => mb_substr($s, 0, 160), $f['uncited']), 0, 5),
            'judge' => ['status' => 'not_run', 'reason' => 'Chưa có AI cho bước giám khảo.', 'checked' => 0, 'supported' => 0, 'partial' => 0, 'unsupported' => 0, 'supported_ratio' => null, 'verdicts' => []],
        ];
        if ($this->judgeAi === null) {
            return $out;
        }
        $r = (new CitationSupportJudge($this->aiJson()))->judge($html);
        if ($r['error'] !== null) {
            $out['judge']['reason'] = $r['error'];

            return $out;
        }
        $out['judge'] = [
            'status' => 'done', 'reason' => $r['checked'] === 0 ? 'Bài không có cặp (câu, mục trích) để kiểm.' : null,
            'checked' => $r['checked'], 'supported' => $r['supported'], 'partial' => $r['partial'], 'unsupported' => $r['unsupported'],
            'supported_ratio' => $r['checked'] === 0 ? null : round($r['supported'] / $r['checked'], 4), 'verdicts' => $r['verdicts'],
        ];

        return $out;
    }

    private function aiJson(): AiJson
    {
        return new AiJson($this->judgeAi, $this->sleep);
    }
}
