<?php

namespace App\Services\Knowledge\Build;

use App\Models\Brain;
use App\Models\KnowledgeItem;
use App\Services\Agent\Risk\RiskAssessment;
use App\Services\Agent\Risk\RiskClassifier;

/**
 * G2: xếp một mục kiến thức đề xuất vào tầng A/B/C (THIET_KE_KHO_TRI_TUE_TU_DONG.md §3.1) bằng LUẬT CỐ ĐỊNH trong mã.
 * Không do AI quyết, không cấu hình được, không đọc "độ tự tin" của AI.
 *
 * C (luôn chờ người duyệt): loại price|policy|myth|forbidden; có số kèm đơn vị tiền/phần trăm/liều lượng; Trí tuệ có risk_class=high
 *   hoặc ai_reliance=must_cite; chủ đề y tế/pháp lý/tài chính theo RiskClassifier (hạng cao); sản phẩm sức khỏe (thực phẩm chức năng, thuốc, thiết bị y tế).
 * A (tự nhận): thông tin của chính doanh nghiệp từ chính website của họ; loại fact|process|faq|messaging|product; không thuộc C;
 *   RiskClassifier hạng thấp và không có claim nhạy cảm; có đoạn nguyên văn từ trang.
 * B: còn lại.
 */
final class RiskTiering
{
    public const A = 'A';

    public const B = 'B';

    public const C = 'C';

    public const C_TYPES = ['price', 'policy', 'myth', 'forbidden'];

    public const A_TYPES = ['fact', 'process', 'faq', 'messaging', 'product'];

    /** Cụm chỉ sản phẩm/công dụng sức khỏe: RiskClassifier chưa phủ hết (thiếu "thực phẩm chức năng"...), nên luật này nằm riêng ở đây. */
    public const HEALTH_PRODUCT_PHRASES = [
        'thực phẩm chức năng', 'thực phẩm bảo vệ sức khỏe', 'thực phẩm bổ sung', 'thiết bị y tế', 'dụng cụ y tế', 'dược phẩm', 'dược mỹ phẩm',
        'thuốc', 'vitamin', 'khoáng chất', 'hỗ trợ điều trị', 'điều trị', 'chữa bệnh', 'phòng bệnh', 'tăng cường miễn dịch', 'giảm cân', 'tăng cân',
        'tiểu đường', 'huyết áp', 'tim mạch', 'xương khớp', 'ung thư', 'bổ gan', 'bổ thận', 'dinh dưỡng y học', 'sữa công thức',
        'dietary supplement', 'supplement', 'medical device',
    ];

    /** Số kèm đơn vị tiền, phần trăm hoặc liều lượng. */
    private const NUMBER_UNIT = '/(?:(?<![\p{L}\p{N}])\d[\d.,]*\s*(?:%|‰|đ(?![\p{L}])|đồng|vnđ|vnd|usd|eur|\$|₫|triệu|nghìn|ngàn|tỷ|tỉ|k(?![\p{L}])|mg|mcg|µg|μg|ml|iu|ui|g(?![\p{L}])|kg(?![\p{L}])|viên|gói|ống|liều|lần\s*\/\s*(?:ngày|tuần)|lần\s+mỗi\s+ngày|lần\s+một\s+ngày|phần\s*trăm))|(?:(?:\$|₫|usd|vnđ|vnd)\s*\d)/iu';

    public function __construct(private ?RiskClassifier $classifier = null)
    {
        $this->classifier ??= new RiskClassifier;
    }

    /**
     * @param  KnowledgeItem|array<string,mixed>  $item  cần: type, title, body; tùy chọn entity, value, value_unit, source_span
     * @return array{tier: string, reasons: list<string>}
     */
    public function tier(KnowledgeItem|array $item, ?Brain $brain = null, bool $ownSite = true): array
    {
        $g = fn (string $k) => is_array($item) ? ($item[$k] ?? null) : $item->{$k};
        $type = (string) $g('type');
        $text = trim(implode(' ', array_filter([(string) $g('title'), (string) $g('body'), (string) $g('entity'), (string) $g('value'), (string) $g('value_unit')])));

        $c = [];
        if (in_array($type, self::C_TYPES, true)) {
            $c[] = 'Loại mục "'.$type.'" luôn do người duyệt.';
        }
        if (preg_match(self::NUMBER_UNIT, $text)) {
            $c[] = 'Có số kèm đơn vị tiền, phần trăm hoặc liều lượng.';
        }
        if ($brain !== null && $brain->riskClass() === 'high') {
            $c[] = 'Trí tuệ "'.$brain->name.'" có mức rủi ro cao.';
        }
        if ($brain !== null && $brain->reliance() === 'must_cite') {
            $c[] = 'Trí tuệ "'.$brain->name.'" bắt buộc có nguồn.';
        }
        $assess = $this->classifier->assess((string) $g('title'), (string) $g('body'));
        if ($assess->level === RiskAssessment::HIGH) {
            $c[] = 'Chủ đề rủi ro cao (y tế, pháp lý, tài chính): '.($assess->reasons[0] ?? 'theo luật phân hạng').'.';
        }
        if ($type === 'product' && $hit = $this->healthPhrase($text)) {
            $c[] = 'Sản phẩm thuộc lĩnh vực sức khỏe (cụm "'.$hit.'"): công dụng là nội dung quảng cáo có ràng buộc pháp lý.';
        }
        if ($c !== []) {
            return ['tier' => self::C, 'reasons' => $c];
        }

        $b = [];
        if (! $ownSite) {
            $b[] = 'Không phải thông tin của chính website.';
        }
        if (! in_array($type, self::A_TYPES, true)) {
            $b[] = 'Loại mục "'.$type.'" không thuộc nhóm tự nhận.';
        }
        if ($assess->level !== RiskAssessment::LOW || $assess->sensitiveClaims !== []) {
            $b[] = 'Có số liệu, so sánh hoặc cam kết cần người xem.';
        }
        if (trim((string) $g('source_span')) === '') {
            $b[] = 'Không có đoạn nguyên văn từ trang làm căn cứ.';
        }

        return $b === [] ? ['tier' => self::A, 'reasons' => ['Thông tin thường của doanh nghiệp, lấy từ chính website của họ.']] : ['tier' => self::B, 'reasons' => $b];
    }

    private function healthPhrase(string $text): ?string
    {
        $t = mb_strtolower($text);
        foreach (self::HEALTH_PRODUCT_PHRASES as $p) {
            if (preg_match('/(?<![\p{L}\p{N}])'.preg_quote($p, '/').'(?![\p{L}\p{N}])/u', $t)) {
                return $p;
            }
        }

        return null;
    }
}
