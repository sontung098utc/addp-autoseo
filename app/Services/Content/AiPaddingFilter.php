<?php

namespace App\Services\Content;

class AiPaddingFilter
{
    /**
     * Danh sách các cụm từ đệm sáo rỗng thường gặp của LLM tiếng Việt (AI Padding / Boilerplate)
     */
    public const BANNED_PHRASES = [
        'trong thời đại số',
        'trong thời đại công nghệ số',
        'trong kỷ nguyên số',
        'trong kỷ nguyên 4.0',
        'không thể phủ nhận rằng',
        'không thể bàn cãi',
        'đóng vai trò vô cùng quan trọng',
        'đóng vai trò then chốt',
        'như chúng ta đã biết',
        'chắc hẳn ai trong chúng ta',
        'ắt hẳn ai cũng',
        'tóm lại,',
        'nhìn chung,',
        'tổng kết lại,',
        'trên đây là tất cả những',
        'hy vọng bài viết trên đã cung cấp',
    ];

    /**
     * Quét và phát hiện các cụm từ đệm trong nội dung bài viết
     *
     * @param string $text Nội dung HTML hoặc text thuần
     * @return array [ 'found_count' => int, 'matches' => array ]
     */
    public function detectPadding(string $text): array
    {
        $normalizedText = mb_strtolower(strip_tags($text), 'UTF-8');
        $matches = [];
        $totalFound = 0;

        foreach (self::BANNED_PHRASES as $phrase) {
            $count = substr_count($normalizedText, $phrase);
            if ($count > 0) {
                $matches[$phrase] = $count;
                $totalFound += $count;
            }
        }

        return [
            'found_count' => $totalFound,
            'matches' => $matches,
            'is_clean' => $totalFound === 0,
        ];
    }

    /**
     * Tính điểm Mật độ thông tin (Information Density Score: 0 - 100)
     * Dựa trên:
     * - Độ dài thực chất
     * - Tần suất chứa số liệu, phần trăm, thông số đo lường
     * - Cấu trúc thẻ danh sách phân tầng (ul/ol)
     * - Trừ điểm nếu xuất hiện các câu đệm AI
     */
    public function calculateInformationDensity(string $htmlContent): array
    {
        $plainText = strip_tags($htmlContent);
        $wordCount = count(preg_split('/\s+/u', trim($plainText)));

        // 1. Quét từ đệm
        $paddingDetection = $this->detectPadding($htmlContent);
        $paddingPenalty = min(40, $paddingDetection['found_count'] * 10);

        // 2. Quét số liệu và chỉ số định lượng (%, độ C, kg, mm, rpm, USD, VNĐ, số liệu thống kê)
        preg_match_all('/(\d+([.,]\d+)?\s*(%|kg|mm|cm|m|g|l|ml|rpm|kw|h|giờ|vnd|vnđ|\$|độ|c))/ui', $plainText, $metricsMatches);
        $metricCount = count($metricsMatches[0] ?? []);
        $metricBonus = min(25, $metricCount * 5);

        // 3. Quét cấu trúc phân tầng (danh sách li, tr bảng, blockquote)
        $listCount = substr_count($htmlContent, '</li>') + substr_count($htmlContent, '</tr>');
        $structureBonus = min(25, $listCount * 3);

        // 4. Điểm cơ sở theo độ sâu bài viết (độ dài lý tưởng 1200 - 2500 từ)
        $lengthScore = 0;
        if ($wordCount >= 1000) {
            $lengthScore = 50;
        } elseif ($wordCount >= 600) {
            $lengthScore = 35;
        } else {
            $lengthScore = 20;
        }

        $totalScore = max(0, min(100, $lengthScore + $metricBonus + $structureBonus - $paddingPenalty));

        return [
            'score' => $totalScore,
            'word_count' => $wordCount,
            'metric_count' => $metricCount,
            'structure_elements' => $listCount,
            'padding_count' => $paddingDetection['found_count'],
            'is_acceptable' => $totalScore >= 70 && $paddingDetection['found_count'] === 0,
        ];
    }

    /**
     * Tự động loại bỏ hoặc làm sạch các câu đệm mở đầu / kết luận sáo rỗng
     */
    public function cleanPadding(string $text): string
    {
        $cleaned = $text;
        foreach (self::BANNED_PHRASES as $phrase) {
            $cleaned = preg_replace('/\b' . preg_quote($phrase, '/') . '\b/ui', '', $cleaned);
        }

        // Dọn dẹp khoảng trắng kép
        return trim(preg_replace('/\s{2,}/u', ' ', $cleaned));
    }
}
