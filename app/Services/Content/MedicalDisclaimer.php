<?php

namespace App\Services\Content;

use App\Services\Content\Quality\Rules\MedicalSafetyRule;

/**
 * Bài sức khỏe: nếu AI quên câu "thông tin tham khảo, không thay thế tư vấn của bác sĩ" (luật MED01) thì hệ thống tự thêm một khung lưu ý cố định ở cuối bài.
 * Văn bản do hệ thống đặt, không lấy từ nội dung ngoài. Chỉ thêm khi chưa có câu tương đương, nên không lặp. Không tự thêm đoạn "khi nào nên gặp bác sĩ" vì dấu hiệu đi khám phải lấy từ nguồn đã duyệt.
 */
final class MedicalDisclaimer
{
    public const TEXT = 'Thông tin trong bài chỉ mang tính tham khảo, không thay thế tư vấn, chẩn đoán hay điều trị của bác sĩ. Hãy trao đổi với bác sĩ hoặc cơ sở y tế trước khi thay đổi cách điều trị.';

    public static function ensure(string $html): array
    {
        if (trim($html) === '') {
            return [$html, false];
        }
        $plain = html_entity_decode(strip_tags($html), ENT_QUOTES | ENT_HTML5, 'UTF-8');
        if (preg_match((new \ReflectionClassConstant(MedicalSafetyRule::class, 'DISCLAIMER'))->getValue(), $plain) === 1) {
            return [$html, false];
        }

        return [rtrim($html)."\n".'<aside class="autoseo-callout autoseo-callout--note"><strong>Lưu ý</strong><p>'.e(self::TEXT).'</p></aside>', true];
    }
}
