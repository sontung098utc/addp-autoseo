<?php

namespace App\Services\Content\Quality\Rules;

use App\Models\Brain;
use App\Models\KnowledgeItem;
use App\Services\Brain\BrainContext;
use App\Services\Content\CitationSupportJudge;
use App\Services\Content\Quality\QualityContext;
use App\Services\Content\Quality\QualityRule;
use App\Services\Content\Quality\RuleResult;

/**
 * CITE02 (P1, tất định): số liệu có đơn vị trong đoạn CÓ trích dẫn phải xuất hiện trong chính các mục được trích. CHỈ chạy khi bài dùng Trí tuệ "Bắt buộc nguồn".
 * Bổ sung cho CITE01 (có dấu) và lượt AI kiểm nghĩa (CitationSupportJudge, cũng ghi mã CITE02). Không chặn nhưng đưa vào vòng sửa (QualityReport::supportGaps).
 * Chỉ nhận số có đơn vị %, mg/dL, mmol/L, mmol, mg, g/dL, kg, mmHg: số không đơn vị (năm, tuổi, số thứ tự) quá hay xuất hiện ngẫu nhiên để kiểm bằng chữ.
 */
class CitationFigureRule implements QualityRule
{
    public const MAX_DETAILS = 8;

    public function code(): string
    {
        return 'CITE02';
    }

    /** @return list<string> */
    private static function figures(string $text): array
    {
        preg_match_all('/(?<![\d.,])\d+(?:[.,]\d+)?\s?(?:%|mg\/dL|mmol\/L|mmol|mg|g\/dL|kg|mmHg)/u', $text, $m);

        return array_values(array_unique($m[0]));
    }

    private static function norm(string $s): string
    {
        return str_replace([' ', '.'], ['', ','], mb_strtolower($s));
    }

    public function evaluate(QualityContext $ctx): array
    {
        $article = $ctx->article;
        if (! $article) {
            return [];
        }
        $ids = array_values(array_unique(array_merge(BrainContext::forArticle($article)->ids(), array_map('intval', $ctx->brainIds ?? []))));
        if ($ids === [] || ! Brain::query()->whereIn('id', $ids)->where('status', 'active')->get()->contains(fn (Brain $b) => $b->reliance() === 'must_cite')) {
            return [];
        }
        $pairs = CitationSupportJudge::pairs($ctx->html());
        $all = array_values(array_unique(array_merge([], ...array_column($pairs, 'ids'))));
        $texts = $all === [] ? [] : KnowledgeItem::query()->whereIn('id', $all)->where('status', 'approved')->whereIn('brain_id', $ids)->get()
            ->mapWithKeys(fn ($i) => [$i->id => self::norm($i->title.' '.$i->body)])->all();

        $issues = [];
        foreach ($pairs as $p) {
            $blob = implode(' ', array_map(fn ($id) => $texts[$id] ?? '', $p['ids']));
            foreach (self::figures($p['text']) as $f) {
                if (! str_contains($blob, self::norm($f))) {
                    $issues[] = '«'.QualityContext::excerpt($p['text'], 90).'» nêu «'.$f.'» nhưng mục được trích (KB#'.implode(', KB#', $p['ids']).') không có số liệu đó.';
                }
            }
        }
        $n = count($issues);

        return [new RuleResult('CITE02', RuleResult::P1, $n === 0, 'Số liệu khớp mục được trích', $n === 0
            ? 'Mọi số liệu có đơn vị trong đoạn có trích dẫn đều có trong mục được trích.'
            : $n.' số liệu không có trong mục được trích: '.implode(' ', array_slice($issues, 0, 2)).' Dùng đúng số của mục kho hoặc bỏ số đó.',
            $n.' số lệch', '0 số lệch', $issues[0] ?? null, array_slice($issues, 0, self::MAX_DETAILS))];
    }
}
