<?php

namespace App\Services\Content\Quality\Rules;

use App\Services\Content\Quality\QualityContext;
use App\Services\Content\Quality\QualityRule;
use App\Services\Content\Quality\RuleResult;

/**
 * PROC01 (P1): thân bài lộ QUÁ TRÌNH làm bài thay vì nói thẳng nội dung: "chúng tôi đối chiếu...", "một nguồn nêu...", "các nguồn đồng thuận...",
 * "bài này không đưa ... vì chưa có căn cứ". Người đọc cần kiến thức, không cần biết hệ thống tìm nguồn thế nào. Không chặn bàn giao nhưng được đưa vào vòng sửa.
 * Khối Nguồn tham khảo cuối bài không bị xét.
 */
class ProcessLeakRule implements QualityRule
{
    private const PATTERNS = [
        '/chúng tôi\s+(?:đã\s+)?(?:đối chiếu|tra cứu|tổng hợp|xem xét|tìm hiểu|đọc|rà soát)/iu',
        '/(?:tài liệu|nguồn|nghiên cứu)[^.\n]{0,40}\s(?:chúng tôi|bài này)\s+(?:đã\s+)?(?:đối chiếu|xem|đọc|tìm)/iu',
        '/bài (?:này|viết này)\s+không\s+(?:đưa|nêu|đặt|đề cập|liệt kê)[^.\n]{0,100}(?:vì|do)\s+(?:chưa|không)\s+(?:có|đủ)/iu',
        '/(?:một|nhiều|các|những|vài)\s+(?:nguồn|tài liệu)(?:\s+y khoa|\s+uy tín|\s+tham khảo)?\s+(?:mới\s+)?(?:đều\s+)?(?:nêu|cho (?:thấy|biết|rằng)|đồng thuận|khác nhau|không thống nhất|gợi ý|khuyên|cho phép)/iu',
        '/(?:các|những) nguồn\s+(?:y khoa\s+)?(?:hơi\s+)?(?:đồng thuận|khác nhau|không thống nhất)/iu',
        '/theo (?:các|những)\s+(?:nguồn|tài liệu)\s+(?:đã|chúng tôi)/iu',
    ];

    public function code(): string
    {
        return 'PROC01';
    }

    public function evaluate(QualityContext $ctx): array
    {
        $bad = [];
        foreach ($ctx->xpath()->query("//p[not(ancestor-or-self::*[contains(concat(' ', normalize-space(@class), ' '), ' autoseo-refs ')])] | //li[not(ancestor-or-self::*[contains(concat(' ', normalize-space(@class), ' '), ' autoseo-refs ')])]") ?: [] as $node) {
            $text = trim(preg_replace('/\s+/u', ' ', (string) $node->textContent) ?? '');
            foreach (preg_split('/(?<=[.!?])\s+/u', $text) ?: [] as $sentence) {
                foreach (self::PATTERNS as $re) {
                    if (preg_match($re, $sentence)) {
                        $bad[] = QualityContext::excerpt($sentence, 140);
                        break;
                    }
                }
            }
        }
        $n = count($bad);

        return [new RuleResult('PROC01', RuleResult::P1, $n === 0, 'Thân bài không lộ quá trình tìm/đối chiếu nguồn', $n === 0
            ? 'Không có câu nói về việc tìm hay đối chiếu nguồn.'
            : "{$n} câu nói về quá trình tìm/đối chiếu nguồn thay vì nói thẳng nội dung. Viết lại thành khẳng định trực tiếp (nguồn nằm ở cuối bài).",
            (string) $n, '0', $bad[0] ?? null, array_slice($bad, 0, 8))];
    }
}
