<?php

namespace App\Services\Content\Quality\Rules;

use App\Models\Brain;
use App\Services\Brain\BrainContext;
use App\Services\Content\Quality\QualityContext;
use App\Services\Content\Quality\QualityRule;
use App\Services\Content\Quality\RuleResult;

/**
 * MED01: rào chắn an toàn tối thiểu cho nội dung y tế. CHỈ chạy khi bài dùng Trí tuệ có hạng rủi ro cao hoặc mức "Bắt buộc nguồn". Tất định, không AI.
 *  - Phải hướng người đọc tới bác sĩ/cơ sở y tế: có đoạn nói khi nào nên gặp bác sĩ hoặc đi khám.
 *  - Phải có câu cho biết đây là thông tin tham khảo, không thay tư vấn của bác sĩ.
 * Chỉ là P1 (không chặn): nhận bằng cụm từ nên có thể bỏ sót cách diễn đạt khác; không kiểm nội dung dấu hiệu cần đi khám có đúng hay không (việc của chuyên gia).
 */
class MedicalSafetyRule implements QualityRule
{
    private const SEE_DOCTOR = '/(khi nào (nên |cần )?(gặp|đến|đi|tới|liên hệ)[^.]{0,30}(bác sĩ|khám|cơ sở y tế|y tế)|(nên|cần|hãy) (gặp|đến|đi|tới|liên hệ|hỏi|trao đổi)[^.]{0,25}(bác sĩ|cơ sở y tế|chuyên gia y tế)|đi khám|đến khám)/iu';

    private const DISCLAIMER = '/(không (thay thế|thay cho|nhằm thay)[^.]{0,40}(tư vấn|lời khuyên|chẩn đoán|điều trị|bác sĩ)|thông tin (chỉ )?mang tính (tham khảo|giáo dục)|chỉ (nhằm|để) (tham khảo|cung cấp thông tin)|tham khảo ý kiến (của )?(bác sĩ|chuyên gia))/iu';

    public function code(): string
    {
        return 'MED01';
    }

    public function evaluate(QualityContext $ctx): array
    {
        $article = $ctx->article;
        if (! $article) {
            return [];
        }
        $ids = array_values(array_unique(array_merge(BrainContext::forArticle($article)->ids(), array_map('intval', $ctx->brainIds ?? []))));
        if ($ids === [] || ! Brain::query()->whereIn('id', $ids)->where('status', 'active')->get()->contains(fn (Brain $b) => $b->reliance() === 'must_cite' || $b->riskClass() === 'high')) {
            return [];
        }
        $text = $ctx->text();
        $doctor = preg_match(self::SEE_DOCTOR, $text) === 1;
        $disclaimer = preg_match(self::DISCLAIMER, $text) === 1;

        return [
            new RuleResult('MED01', RuleResult::P1, $doctor, 'Hướng người đọc tới bác sĩ', $doctor
                ? 'Bài có đoạn hướng người đọc tới bác sĩ hoặc cơ sở y tế.'
                : 'Bài y tế cần có đoạn "khi nào nên gặp bác sĩ / đi khám" (dấu hiệu lấy từ nguồn đã duyệt). Chưa thấy cụm như vậy.', $doctor ? '1' : '0', '≥1'),
            new RuleResult('MED01', RuleResult::P1, $disclaimer, 'Lưu ý thông tin tham khảo', $disclaimer
                ? 'Bài có câu cho biết đây là thông tin tham khảo, không thay tư vấn của bác sĩ.'
                : 'Bài y tế cần một câu nêu rõ đây là thông tin tham khảo, không thay thế tư vấn, chẩn đoán hay điều trị của bác sĩ.', $disclaimer ? '1' : '0', '≥1'),
        ];
    }
}
