<?php

namespace App\Filament\Support\Gsc;

/** "AI đề xuất (minh họa)": hướng cập nhật sinh từ MẪU VĂN BẢN theo loại cơ hội, nguồn là chính số liệu GSC của mục. Chưa gọi LLM. */
final class AiSuggestion
{
    /**
     * @param  list<string>  $rules  mã luật của mục đang chọn
     * @return list<string>
     */
    public static function for(string $key, array $rules): array
    {
        $out = [];
        foreach ($rules as $r) {
            $out[] = match ($r) {
                OpportunityFinder::RULE_PAGE1 => "Đưa \"{$key}\" vào tiêu đề và H2 đầu tiên, bổ sung phần trả lời trực tiếp ở đầu bài và 2–3 liên kết nội bộ trỏ về bài.",
                OpportunityFinder::RULE_LOW_CTR => "Viết lại tiêu đề và meta description cho \"{$key}\": nêu lợi ích cụ thể, có con số hoặc năm; giữ nguyên nội dung chính.",
                OpportunityFinder::RULE_DROP => "Rà lại bài cho \"{$key}\": cập nhật số liệu cũ, bổ sung mục còn thiếu so với các kết quả đang đứng trên, kiểm tra thay đổi gần đây trên trang.",
                default => '',
            };
        }

        return $out !== [] ? $out : ["Mục \"{$key}\" chưa thuộc luật cơ hội nào; có thể tạo đề xuất rà soát chung nếu cần."];
    }
}
