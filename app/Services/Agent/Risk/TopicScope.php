<?php

namespace App\Services\Agent\Risk;

use App\Models\BrandProfile;
use App\Services\Brain\BrainContext;
use App\Services\Brand\BrandProfileData;
use App\Services\Knowledge\Build\RiskTiering;
use App\Services\Knowledge\Discover\SourceDiscoveryService;

/**
 * Bài có NẰM NGOÀI lĩnh vực của website/Trí tuệ không? Dùng để không áp "bối cảnh ngành" (hạng rủi ro y tế của Trí tuệ, phong cách ảnh y tế,
 * lĩnh vực thương hiệu trong prompt ảnh) lên một bài chẳng liên quan (vd. "cách viết thư xin học bổng" trên website dược).
 *
 * Nguyên tắc AN TOÀN: chỉ trả true khi CHẮC CHẮN lệch. Mọi nghi ngờ đều trả false (giữ nghiêm như cũ), nên không bao giờ hạ mức an toàn của bài y tế thật:
 *  - hồ sơ doanh nghiệp chưa có lĩnh vực => false (không đủ bằng chứng);
 *  - từ khóa dính luật rủi ro (sức khỏe, tài chính, pháp lý...), cụm sản phẩm sức khỏe/dược hoặc từ vựng y tế rộng => false;
 *  - từ khóa có chung từ với lĩnh vực của hồ sơ hoặc với tên/mô tả của bất kỳ Trí tuệ nào => false.
 */
final class TopicScope
{
    /** Từ vựng y tế rộng (ngoài bộ từ của RiskClassifier) để không bỏ sót thuật ngữ y tế khi quyết định bài ngoài lĩnh vực. */
    private const MEDICAL_HINTS = '/(?<![\p{L}\p{N}])(bệnh|benh|thuốc|thuoc|đau|dau\s+(?:đầu|bụng|lưng)|y tế|y te|y khoa|sức khỏe|suc khoe|khám|kham\s+benh|bác sĩ|bac si|dược|duoc|vitamin|dinh dưỡng|dinh duong|ăn kiêng|an kieng|triệu chứng|chẩn đoán|xét nghiệm|huyết|tim|gan|thận|phổi|dạ dày|da liễu|viêm|nhiễm|virus|vi khuẩn|vắc.?xin|vaccine|thai|sinh sản|béo phì|tiểu đường|ung thư|dị ứng|mất ngủ|stress|trầm cảm|lo âu|cholesterol|insulin|sơ cứu|cấp cứu|phẫu thuật|điều trị|chữa|thực đơn|chế độ ăn|thực phẩm|người cao tuổi|người lớn tuổi|người già|trẻ em|trẻ nhỏ|bà bầu|cơ thể|tập luyện|thể dục|giảm cân|mẹ và bé|răng|mắt|da|tóc|cân nặng)(?![\p{L}\p{N}])/iu';

    public static function clearlyOffField(string $keyword, ?BrandProfile $brand, ?BrainContext $ctx = null): bool
    {
        $keyword = trim($keyword);
        $hasField = trim((string) $brand?->business_field) !== '';
        $brains = $ctx->brains ?? [];
        // Cần bằng chứng dương về lĩnh vực của website (hồ sơ có lĩnh vực) và từ khóa không chung từ với nó; tên Trí tuệ một mình không đủ để kết luận lệch.
        if ($keyword === '' || ! $hasField || ! BrandProfileData::looksOffTopic($brand, $keyword)) {
            return false;
        }
        $a = (new RiskClassifier)->assess($keyword);
        if ($a->level !== RiskAssessment::LOW || $a->reasons !== [] || $a->sensitiveClaims !== []) {
            return false;
        }
        $t = mb_strtolower($keyword);
        if (preg_match(self::MEDICAL_HINTS, $t) || self::hasHealthProduct($t) || SourceDiscoveryService::fieldFor($keyword) !== null) {
            return false;
        }
        // Trí tuệ đã gắn cũng phải không chạm chủ đề (tên, mô tả, mã lĩnh vực): bài chung từ với một Trí tuệ thì coi là thuộc phạm vi của nó.
        $kw = self::tokens($keyword);
        foreach ($brains as $b) {
            $bw = self::tokens($b->name.' '.$b->description.' '.str_replace('-', ' ', (string) $b->domain_key));
            if (self::overlap($kw, $bw)) {
                return false;
            }
        }

        return true;
    }

    private static function hasHealthProduct(string $t): bool
    {
        foreach (RiskTiering::HEALTH_PRODUCT_PHRASES as $p) {
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($p, '/').'(?![\p{L}\p{N}])/u', $t)) {
                return true;
            }
        }

        return false;
    }

    /** @return list<string> */
    private static function tokens(string $s): array
    {
        return array_values(array_filter(preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($s)) ?: [], fn ($w) => mb_strlen($w) >= 3 && ! in_array($w, ['của', 'cho', 'và', 'các', 'những', 'trí', 'tuệ', 'công', 'ty', 'với', 'trong'], true)));
    }

    /** @param list<string> $a @param list<string> $b */
    private static function overlap(array $a, array $b): bool
    {
        foreach ($a as $x) {
            foreach ($b as $y) {
                if ($x === $y || str_contains($x, $y) || str_contains($y, $x)) {
                    return true;
                }
            }
        }

        return false;
    }
}
