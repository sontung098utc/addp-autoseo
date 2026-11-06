<?php

namespace App\Services\Content\Quality\Rules;

use App\Models\ArticleVersion;
use App\Services\Content\Quality\QualityContext;
use App\Services\Content\Quality\QualityRule;
use App\Services\Content\Quality\QualityThresholds as T;
use App\Services\Content\Quality\RuleResult;

/** Trùng lặp nội bộ: shingle 5 từ + Jaccard với phiên bản mới nhất của các bài KHÁC cùng website (tối đa 200 bản gần nhất). */
class DuplicateRule implements QualityRule
{
    public function code(): string
    {
        return 'DUP01';
    }

    public function evaluate(QualityContext $ctx): array
    {
        $article = $ctx->article;
        if (! $article) {
            return [new RuleResult('DUP01', RuleResult::P1, true, 'Trùng lặp nội bộ', 'Không xác định được bài/website nên bỏ qua so sánh trùng lặp.')];
        }

        $mine = self::shingles($ctx->text());
        $best = [];
        if ($mine) {
            $others = ArticleVersion::query()->select('article_versions.*')
                ->join('articles', 'articles.id', '=', 'article_versions.article_id')
                ->where('articles.website_id', $article->website_id)
                ->where('article_versions.article_id', '!=', $article->id)
                ->orderByDesc('article_versions.id')->limit(T::DUP_MAX_COMPARED)->get();
            $seen = [];
            foreach ($others as $o) {
                if (isset($seen[$o->article_id])) { // chỉ lấy bản mới nhất của mỗi bài
                    continue;
                }
                $seen[$o->article_id] = true;
                $theirs = self::shingles((new QualityContext($o, null))->text());
                if (! $theirs) {
                    continue;
                }
                $inter = count(array_intersect_key($mine, $theirs));
                $union = count($mine) + count($theirs) - $inter;
                $j = $union > 0 ? $inter / $union : 0.0;
                if ($j >= T::DUP_WARNING) {
                    $best[] = ['j' => $j, 'o' => $o];
                }
            }
            usort($best, fn ($a, $b) => $b['j'] <=> $a['j']);
        }

        $max = $best[0]['j'] ?? 0.0;
        $pct = fn (float $j) => round($j * 100) . '%';
        $sev = $max >= T::DUP_BLOCKING ? RuleResult::P0 : RuleResult::P1;
        $ok = $max < T::DUP_WARNING;
        $list = implode('; ', array_map(fn ($b) => "Bài #{$b['o']->article_id} v{$b['o']->version} «" . QualityContext::excerpt((string) $b['o']->title, 60) . "» trùng {$pct($b['j'])}", array_slice($best, 0, 3)));

        return [new RuleResult('DUP01', $ok ? RuleResult::P1 : $sev, $ok, 'Trùng lặp nội bộ', $ok
            ? 'Không thấy bài nào cùng website trùng nội dung đáng kể.'
            : 'Nội dung trùng ' . $pct($max) . ' với bài khác cùng website' . ($sev === RuleResult::P0 ? ' (từ ' . $pct(T::DUP_BLOCKING) . ' là chặn duyệt)' : '') . '. Viết lại các đoạn trùng hoặc gộp/phân biệt chủ đề hai bài để tránh tự cạnh tranh từ khóa.',
            $pct($max), '< ' . $pct(T::DUP_WARNING) . ' (P1) / < ' . $pct(T::DUP_BLOCKING) . ' (P0)', $ok ? null : $list)];
    }

    /** @return array<string,true> tập shingle 5 từ (khóa = shingle) */
    public static function shingles(string $text): array
    {
        preg_match_all('/[\p{L}\p{N}]+/u', mb_strtolower($text), $m);
        $w = $m[0];
        $set = [];
        for ($i = 0, $n = count($w) - T::SHINGLE_SIZE + 1; $i < $n; $i++) {
            $set[implode(' ', array_slice($w, $i, T::SHINGLE_SIZE))] = true;
        }

        return $set;
    }
}
