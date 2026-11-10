<?php

namespace App\Services\Content\Quality\Rules;

use App\Filament\Support\Diff\ParagraphDiff;
use App\Models\ArticleVersion;
use App\Services\Content\Quality\QualityContext;
use App\Services\Content\Quality\QualityRule;
use App\Services\Content\Quality\RuleResult;

class FactRule implements QualityRule
{
    private const NUMBER_PATTERN = '/\d+(?:[.,]\d+)*\s?(?:%|triệu|tỷ|nghìn|ngàn)|\b(?:19|20)\d{2}\b|\b\d{1,2}\/\d{1,2}(?:\/\d{2,4})?\b/iu';

    public function code(): string
    {
        return 'FACT01';
    }

    public function evaluate(QualityContext $ctx): array
    {
        $v = $ctx->version;
        $sources = self::sources($v->sources_cited);
        preg_match_all(self::NUMBER_PATTERN, $ctx->text(), $m);
        $nums = array_values(array_unique($m[0]));

        $out = [new RuleResult('FACT01', RuleResult::P1, ! ($nums && ! $sources), 'Số liệu cần nguồn',
            ! ($nums && ! $sources)
                ? ($nums ? 'Bài có số liệu và đã nêu nguồn.' : 'Bài không có số liệu cụ thể cần dẫn nguồn.')
                : 'Bài có ' . count($nums) . ' số liệu cụ thể nhưng chưa nêu nguồn (sources_cited trống). Thêm nguồn cho số liệu.',
            count($nums) . ' số liệu / ' . count($sources) . ' nguồn', 'có nguồn khi có số liệu', $nums && ! $sources ? implode(', ', array_slice($nums, 0, 6)) : null)];

        $prev = $v->exists
            ? ArticleVersion::where('article_id', $v->article_id)->where('version', '<', $v->version)->where('review_status', 'approved')->orderByDesc('version')->first()
            : null;
        if ($prev) {
            $changes = [];
            foreach (ParagraphDiff::compare((string) $prev->html_content, $ctx->html()) as $d) {
                foreach ($d['numberChanges'] ?? [] as $c) {
                    if ($c['old'] !== $c['new']) {
                        $changes[] = ($c['old'] !== '' ? $c['old'] : '∅') . ' → ' . ($c['new'] !== '' ? $c['new'] : '∅');
                    }
                }
            }
            $prevSources = self::sources($prev->sources_cited);
            $sourcesChanged = $sources && $sources !== $prevSources;
            $bad = $changes && ! $sourcesChanged;
            $out[] = new RuleResult('FACT01', RuleResult::P0, ! $bad, "Thay đổi số liệu so với v{$prev->version} đã duyệt", ! $changes
                ? "Không đổi số liệu so với v{$prev->version} đã duyệt."
                : ($bad
                    ? 'Thay đổi số liệu so với bản đã duyệt phải có nguồn. Thêm hoặc cập nhật nguồn (sources_cited) cho các số liệu mới, hoặc hoàn lại số cũ.'
                    : 'Có đổi số liệu và nguồn đã được cập nhật so với bản đã duyệt.'),
                count($changes) . ' số đổi', 'số đổi ⇒ nguồn mới', $changes ? implode('; ', array_slice($changes, 0, 6)) : null);
        }

        return $out;
    }

    /** @return list<string> */
    public static function sources(mixed $raw): array
    {
        $flat = [];
        $raw = is_array($raw) ? $raw : [$raw];
        array_walk_recursive($raw, function ($x) use (&$flat) {
            if (is_scalar($x) && trim((string) $x) !== '') {
                $flat[] = trim((string) $x);
            }
        });
        sort($flat);

        return array_values(array_unique($flat));
    }
}
