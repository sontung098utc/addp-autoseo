<?php

namespace App\Services\Content\Quality\Rules;

use App\Models\Brain;
use App\Models\KnowledgeItem;
use App\Services\Brain\BrainContext;
use App\Services\Content\Quality\QualityContext;
use App\Services\Content\Quality\QualityRule;
use App\Services\Content\Quality\RuleResult;

/**
 * Trích dẫn TỪNG KHẲNG ĐỊNH (Kho trí tuệ chuyên ngành). CHỈ chạy khi bài dùng Trí tuệ ở mức "Bắt buộc nguồn". Tất định, không dùng AI.
 *  - CITE01 (P0, nhãn "Trích dẫn trỏ đúng mục kho đã duyệt"): dấu [KB#id] trong bài phải trỏ tới mục kiến thức ĐÃ DUYỆT thuộc Trí tuệ của bài. Trỏ tới mục không tồn tại, chưa duyệt hoặc thuộc nơi khác
 *    là trích dẫn giả hoặc sai nguồn: chặn.
 *  - CITE01 (P1, nhãn "Mỗi khẳng định có dấu trích dẫn"): mỗi đoạn/mục danh sách có câu giống khẳng định kiểm chứng được (số liệu có đơn vị, "khuyến cáo", "điều trị"...) phải mang ít nhất một dấu
 *    [KB#id]. Thiếu: cờ P1 kèm đoạn mẫu, bài chỉ ở bản nháp chờ người có chuyên môn duyệt.
 * Bổ sung cho EVID01 (kiểm cả bài theo Căn cứ chuẩn mực): hai luật này nối từng đoạn với từng mục kho. Nhận khẳng định bằng từ khóa và số liệu nên có thể
 * bỏ sót hoặc báo nhầm; không xác minh nội dung câu có đúng với mục được trích hay không (việc của người duyệt).
 */
class CitationRule implements QualityRule
{
    /** Số đoạn thiếu trích dẫn đưa vào chi tiết cho vòng sửa. */
    public const MAX_DETAILS = 8;

    private const MARK = '/\[KB#(\d+(?:\s*[,;]\s*(?:KB)?#?\d+)*)\]/u';

    public function code(): string
    {
        return 'CITE01';
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

        $cited = [];
        $uncited = [];
        $claimBlocks = 0;
        foreach ($ctx->xpath()->query('//p|//li|//td|//blockquote') ?: [] as $node) {
            $text = trim(preg_replace('/\s+/u', ' ', $node->textContent) ?? '');
            if ($text === '') {
                continue;
            }
            $marks = [];
            if (preg_match_all(self::MARK, $text, $m)) {
                foreach ($m[1] as $grp) {
                    foreach (preg_split('/[^\d]+/', $grp, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $n) {
                        $marks[] = (int) $n;
                    }
                }
            }
            $cited = array_merge($cited, $marks);
            $plain = preg_replace(self::MARK, ' ', $text) ?? $text;
            $isClaim = false;
            foreach (preg_split('/(?<=[.!?])\s+/u', $plain) ?: [] as $sentence) {
                $isClaim = $isClaim || EvidenceRule::looksLikeClaim($sentence);
            }
            if ($isClaim) {
                $claimBlocks++;
                if ($marks === []) {
                    $uncited[] = QualityContext::excerpt($plain, 110);
                }
            }
        }

        $cited = array_values(array_unique($cited));
        $valid = $cited === [] ? [] : KnowledgeItem::query()->whereIn('id', $cited)->where('status', 'approved')->whereIn('brain_id', $ids)->pluck('id')->map(fn ($i) => (int) $i)->all();
        $bad = array_values(array_diff($cited, $valid));

        return [
            new RuleResult('CITE01', RuleResult::P0, $bad === [], 'Trích dẫn trỏ đúng mục kho đã duyệt', $bad === []
                ? 'Mọi dấu [KB#id] trong bài trỏ tới mục kiến thức đã duyệt của Trí tuệ ('.count($cited).' mục được trích).'
                : 'Bài trích '.count($bad).' mục không tồn tại, chưa duyệt hoặc không thuộc Trí tuệ của bài: KB#'.implode(', KB#', array_slice($bad, 0, 6)).'. Bỏ hoặc thay bằng mục kho đã duyệt.',
                count($bad).' mục sai', '0 mục sai'),
            new RuleResult('CITE01', RuleResult::P1, $uncited === [], 'Mỗi khẳng định có dấu trích dẫn', $uncited === []
                ? ($claimBlocks === 0 ? 'Không phát hiện đoạn khẳng định cần trích dẫn (kiểm sơ bộ theo từ khóa và số liệu, không thay chuyên gia).' : 'Cả '.$claimBlocks.' đoạn có khẳng định đều mang dấu trích dẫn [KB#id].')
                : 'Trí tuệ bắt buộc nguồn: '.count($uncited).'/'.$claimBlocks.' đoạn có khẳng định chưa có dấu [KB#id]. Thêm trích dẫn từ kho đã duyệt hoặc bỏ khẳng định. Ví dụ: "'.implode('"; "', array_slice($uncited, 0, 2)).'"',
                count($uncited).'/'.$claimBlocks.' đoạn thiếu', '0 đoạn thiếu', $uncited[0] ?? null, array_slice($uncited, 0, self::MAX_DETAILS)),
        ];
    }
}
