<?php

namespace App\Services\Content\Quality\Rules;

use App\Models\Brain;
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
        if ($ids === [] || ! Brain::query()->whereIn('id', $ids)->where('status', 'active')->get()->contains(fn (Brain $b) => $b->reliance() === 'must_cite')) {
            return [];
        }
        $registry = BrainSourceRegistry::forBrains($ids);
        $cited = FactRule::sources($ctx->version->sources_cited);
        $blocked = [];
        $unknown = [];
        $strong = [];   // bậc 1–2, theo id nguồn
        $medium = [];   // bậc 2–3, theo id nguồn
        foreach ($cited as $c) {
            $hit = $registry->classify($c);
            if ($hit === null) {
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
