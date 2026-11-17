<?php

namespace App\Services\Content\Quality\Rules;

use App\Models\Brain;
use App\Models\KnowledgeItem;
use App\Services\Brain\BrainContext;
use App\Services\Brain\BrainSourceRegistry;
use App\Services\Content\Quality\QualityContext;
use App\Services\Content\Quality\QualityRule;
use App\Services\Content\Quality\RuleResult;

/**
 * AG-B3 EVID01 (Kho trí tuệ P2): bằng chứng theo Căn cứ chuẩn mực. CHỈ chạy khi bài dùng Trí tuệ ở mức "Bắt buộc nguồn"; không thì không trả mục nào.
 * Tất định, KHÔNG dùng AI, KHÔNG tìm web. Kiểm ở mức CẢ BÀI: chưa nối từng khẳng định với từng nguồn (cần chú thích từng câu hoặc AI, thuộc giai đoạn sau).
 *  - Nguồn trích (sources_cited) nằm trong Căn cứ chuẩn mực với bậc "Chặn" → chặn (P0), vì người dùng đã quyết không bao giờ dùng nguồn đó.
 *  - Bài có câu giống khẳng định kiểm chứng được (heuristic: số liệu có đơn vị, hoặc từ khóa như "nghiên cứu", "khuyến cáo", "điều trị"...) thì cần đủ bằng chứng:
 *    ≥1 nguồn bậc 1–2, hoặc ≥2 nguồn bậc 2–3 đã XÁC NHẬN trong sổ. Thiếu → cờ P1 (không chặn): bài vẫn chỉ ở bản nháp chờ người có chuyên môn duyệt.
 *  - Nguồn không có trong sổ (hoặc mới ở dạng gợi ý) KHÔNG được tính; được liệt kê để người duyệt thêm vào sổ nếu đồng ý.
 */
class EvidenceRule implements QualityRule
{
    /** Số liệu có đơn vị (y khoa/tài chính/thời gian). Heuristic tham khảo, không thay chuyên gia. */
    private const FIGURE = '/(?<![\d.,])\d+(?:[.,]\d+)*\s?(?:%|phần trăm|mg|mcg|µg|g|kg|ml|iu|mmhg|mmol|kcal|ngày|tuần|tháng|năm|lần|viên|giờ|phút|triệu|tỷ|nghìn|đồng|vnđ|usd)(?![\p{L}])/iu';

    private const CUES = ['nghiên cứu', 'khuyến cáo', 'chứng minh', 'điều trị', 'ngăn ngừa', 'giảm nguy cơ', 'tăng nguy cơ', 'tác dụng phụ', 'chống chỉ định', 'liều dùng', 'theo who', 'theo bộ y tế', 'theo hiệp hội'];

    public function code(): string
    {
        return 'EVID01';
    }

    public function evaluate(QualityContext $ctx): array
    {
        $article = $ctx->article;
        if (! $article) {
            return [];
        }
        $ids = array_values(array_unique(array_merge(BrainContext::forArticle($article)->ids(), array_map('intval', $ctx->brainIds ?? []))));
        $brains = \App\Support\RequestMemo::remember('evidence-brains:'.implode(',', $ids), fn () => $ids === [] ? collect() : Brain::query()->whereIn('id', $ids)->where('status', 'active')->get());
        if ($ids === [] || ! $brains->contains(fn (Brain $b) => $b->reliance() === 'must_cite')) {
            return [];
        }
        $registry = BrainSourceRegistry::forBrains($ids);
        // Nguồn gốc của các mục kho đã duyệt mà bài trích qua dấu [KB#id] cũng là bằng chứng (nguồn của mục đó được xếp bậc theo Sổ nguồn), không chỉ tên nguồn người viết tự nêu.
        $cited = array_merge(FactRule::sources($ctx->version->sources_cited), $this->kbSources((string) $ctx->version->html_content, $ids));
        $blocked = [];
        $unknown = [];
        $strong = [];   // bậc 1–2, theo id nguồn
        $medium = [];   // bậc 2–3, theo id nguồn
        foreach ($cited as $c) {
            $hit = $registry->classify($c);
            if ($hit === null) {
                // Chưa có trong Sổ nguồn của Trí tuệ nhưng là địa chỉ thuộc danh sách tin cậy cố định (cơ quan y tế, nhà nước, giáo dục, tạp chí...): tính như bằng chứng bậc 2, không bắt người dùng nhập tay từng nguồn.
                $host = BrainSourceRegistry::hostOf($c);
                if ($host !== null && str_contains($c, '.') && ! $this->inBook($ids, $host) && \App\Services\Knowledge\Discover\SourceTrust::assess($c)['level'] === 'trusted') {
                    $strong['host:'.$host] = true;
                    $medium['host:'.$host] = true;

                    continue;
                }
                $unknown[] = $c;

                continue;
            }
            $id = $hit['source']->id;
            if ($hit['tier'] === 'blocked') {
                $blocked[] = $hit['source']->name;
            } elseif (in_array($hit['tier'], ['1', '2'], true)) {
                $strong[$id] = true;
            }
            if (in_array($hit['tier'], ['2', '3'], true)) {
                $medium[$id] = true;
            }
        }
        $claims = $this->claimCount($ctx->text());
        $enough = $strong !== [] || count($medium) >= 2;

        $out = [new RuleResult('EVID01', RuleResult::P0, $blocked === [], 'Nguồn bị chặn', $blocked === []
            ? 'Không trích nguồn nào bị chặn trong Căn cứ chuẩn mực.'
            : 'Bài trích nguồn đã bị chặn trong Căn cứ chuẩn mực: '.implode(', ', array_slice(array_values(array_unique($blocked)), 0, 5)).'. Bỏ nguồn đó và nội dung dựa vào nó.',
            count($blocked).' nguồn', '0 nguồn')];

        $note = $unknown === [] ? '' : ' Nguồn chưa nằm trong Căn cứ chuẩn mực (không được tính): '.implode('; ', array_map(fn ($u) => mb_substr($u, 0, 80), array_slice($unknown, 0, 4))).'.';
        $out[] = new RuleResult('EVID01', RuleResult::P1, $claims === 0 || $enough, 'Bằng chứng theo Căn cứ chuẩn mực', match (true) {
            $claims === 0 => 'Không phát hiện câu khẳng định cần nguồn (kiểm sơ bộ theo từ khóa và số liệu, không thay chuyên gia).',
            $enough => 'Có đủ bằng chứng theo Căn cứ chuẩn mực cho '.$claims.' câu khẳng định (kiểm ở mức cả bài, chưa nối từng câu với từng nguồn).'.$note,
            default => "Trí tuệ bắt buộc nguồn: bài có {$claims} câu giống khẳng định cần kiểm chứng nhưng chưa đủ nguồn đã xác nhận (cần ≥1 nguồn bậc 1–2, hoặc ≥2 nguồn bậc 2–3). Hãy trích nguồn trong Căn cứ chuẩn mực hoặc bỏ khẳng định đó; bài giữ cho người có chuyên môn duyệt.".$note,
        }, $claims.' câu / '.count($strong + $medium).' nguồn xác nhận', '≥1 nguồn bậc 1–2 hoặc ≥2 nguồn bậc 2–3');

        return $out;
    }

    /** @param list<int> $brainIds @return list<string> địa chỉ hoặc tên nguồn của các mục kho đã duyệt được trích */
    private function kbSources(string $html, array $brainIds): array
    {
        $ids = [];
        if (preg_match_all('/\[KB#(\d+(?:\s*[,;]\s*(?:KB)?#?\d+)*)\]|data-kb="([\d,\s]+)"/u', $html, $m)) {
            foreach (array_merge($m[1], $m[2]) as $grp) {
                foreach (preg_split('/[^\d]+/', (string) $grp, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $n) {
                    $ids[(int) $n] = true;
                }
            }
        }
        if ($ids === []) {
            return [];
        }
        $out = [];
        foreach (KnowledgeItem::query()->with('source')->whereIn('id', array_keys($ids))->where('status', 'approved')->whereIn('brain_id', $brainIds)->get() as $it) {
            if ($it->source) {
                $out[] = (string) ($it->source->url ?: $it->source->title);
            }
        }

        return array_values(array_unique(array_filter($out)));
    }

    /** Tên miền đã có trong Sổ nguồn của Trí tuệ (kể cả mới là gợi ý chưa xác nhận): chủ Trí tuệ đã xem xét nên KHÔNG tự nâng thành bằng chứng. @param list<int> $brainIds */
    private function inBook(array $brainIds, string $host): bool
    {
        return \App\Support\RequestMemo::remember('inbook:'.implode(',', $brainIds).':'.$host, fn () => \App\Models\BrainSource::query()->whereIn('brain_id', $brainIds)->where(fn ($q) => $q->where('domain', $host)->orWhere('domain', 'like', '%.'.$host))->exists());
    }

    /** Có số liệu kèm đơn vị (liều, ngưỡng, tỷ lệ, mốc thời gian...). */
    public static function hasFigure(string $text): bool
    {
        return preg_match(self::FIGURE, $text) === 1;
    }

    /** Câu có giống khẳng định kiểm chứng được không (số liệu có đơn vị hoặc từ khóa như "nghiên cứu", "khuyến cáo", "điều trị"...). */
    public static function looksLikeClaim(string $sentence): bool
    {
        if (preg_match(self::FIGURE, $sentence) === 1) {
            return true;
        }
        $l = mb_strtolower($sentence);
        foreach (self::CUES as $cue) {
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($cue, '/').'(?![\p{L}\p{N}])/u', $l) === 1) {
                return true;
            }
        }

        return false;
    }

    /** Số câu giống khẳng định kiểm chứng được. */
    private function claimCount(string $text): int
    {
        $n = 0;
        foreach (preg_split('/(?<=[.!?])\s+/u', $text) ?: [] as $s) {
            $n += self::looksLikeClaim($s) ? 1 : 0;
        }

        return $n;
    }
}
