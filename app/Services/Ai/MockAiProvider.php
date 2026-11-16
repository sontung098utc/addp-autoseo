<?php

namespace App\Services\Ai;

use App\Contracts\AiProviderInterface;
use App\Contracts\PartialRefreshInterface;
use App\Models\Article;
use App\Models\ArticleVersion;
use Illuminate\Support\Str;

class MockAiProvider implements AiProviderInterface, PartialRefreshInterface
{
    protected string $promptVersion = 'v1.0-seo-vn-mock';

    public function generateOutline(Article $article, array $options = []): array
    {
        $keyword = $article->primary_keyword;
        $titleKeyword = Str::title($keyword);

        $outline = [
            "1. Tổng quan về {$titleKeyword}: Khái niệm và tầm quan trọng",
            "2. Tại sao doanh nghiệp cần áp dụng {$keyword} ngay hôm nay",
            "3. Hướng dẫn các bước triển khai {$keyword} hiệu quả và tiết kiệm",
            "4. Những sai lầm phổ biến khi tối ưu {$keyword} và cách phòng tránh",
            "5. Đánh giá xu hướng và giải pháp đo lường thành công",
            "6. Câu hỏi thường gặp (FAQ) và lời khuyên từ chuyên gia",
        ];

        return [
            'outline' => $outline,
            'tokens_used' => 380,
            'model' => 'mock-gemini-pro',
            'prompt_version' => $this->promptVersion,
        ];
    }

    public function generateArticle(Article $article, array $outline, array $options = []): array
    {
        $keyword = $article->primary_keyword;
        $titleKeyword = Str::title($keyword);

        $html = "<h2>1. Tổng quan về {$titleKeyword}</h2>\n";
        $html .= "<p>Trong kỷ nguyên số hóa hiện nay, việc tối ưu <strong>{$keyword}</strong> đóng vai trò sống còn đối với sự phát triển lưu lượng truy cập tự nhiên (organic traffic) của doanh nghiệp. Nội dung chất lượng cao không chỉ nâng cao vị thế thương hiệu mà còn giữ chân người dùng lâu hơn trên website.</p>\n\n";

        $html .= "<h2>2. Tại sao doanh nghiệp cần chú trọng {$keyword}</h2>\n";
        $html .= "<p>Phân tích dữ liệu tìm kiếm cho thấy người dùng ngày càng có xu hướng tìm kiếm các giải pháp cụ thể và thực tế. Áp dụng <em>{$keyword}</em> đúng phương pháp sẽ mang lại 3 lợi thế vượt trội:</p>\n";
        $html .= "<ul>\n";
        $html .= "  <li>Tiết kiệm hơn 40% chi phí chuyển đổi khách hàng so với quảng cáo trả phí.</li>\n";
        $html .= "  <li>Xây dựng niềm tin bền vững thông qua thông tin minh bạch, có dẫn chứng rõ ràng.</li>\n";
        $html .= "  <li>Tự động hóa lịch biên tập giúp duy trì nhịp xuất bản ổn định hàng tuần.</li>\n";
        $html .= "</ul>\n\n";

        $html .= "<h2>3. Quy trình triển khai từng bước chuẩn xác</h2>\n";
        $html .= "<p>Để đạt hiệu quả tối ưu, đội ngũ biên tập cần phối hợp chặt chẽ giữa nghiên cứu từ khóa ngữ nghĩa và xác thực thông tin từ tài liệu chính thống của công ty.</p>\n";
        $html .= "<blockquote>\"Chất lượng nội dung và sự hữu ích cho độc giả luôn là kim chỉ nam hàng đầu trong mọi chiến lược SEO.\"</blockquote>\n\n";

        $html .= "<h2>4. Câu hỏi thường gặp (FAQ)</h2>\n";
        $html .= "<p>Dưới đây là các thắc mắc phổ biến nhất khi tìm hiểu về <strong>{$keyword}</strong>:</p>\n";

        $faqSchema = [
            [
                'question' => "{$titleKeyword} mang lại lợi ích gì cho doanh nghiệp?",
                'answer' => "Giúp gia tăng hiện diện thương hiệu, cải thiện thứ hạng tìm kiếm bền vững và tối ưu hóa chi phí thu hút khách hàng tiềm năng.",
            ],
            [
                'question' => "Bao lâu thì thấy được kết quả khi tối ưu {$keyword}?",
                'answer' => "Thông thường từ 4 đến 12 tuần tùy thuộc vào mức độ cạnh tranh của từ khóa và chất lượng tối ưu kỹ thuật on-page.",
            ],
        ];

        $tokensPrompt = 1200;
        $tokensCompletion = 1600;
        $totalTokens = $tokensPrompt + $tokensCompletion;
        $costVnd = $this->calculateCostVnd($tokensPrompt, $tokensCompletion, 'mock-gemini-pro');

        return [
            'title' => "Cẩm Nang Toàn Diện Về {$titleKeyword} Dành Cho Doanh Nghiệp 2026",
            'slug' => Str::slug($keyword . '-toan-dien-2026'),
            'html_content' => $html,
            'seo_title' => "Cẩm Nang Về {$titleKeyword} | Chuẩn SEO 2026",
            'meta_description' => "Khám phá hướng dẫn toàn diện về {$keyword}, các bước triển khai chi tiết và giải pháp tối ưu chuyển đổi từ chuyên gia hàng đầu.",
            'focus_keyword' => $keyword,
            'faq_schema' => $faqSchema,
            'sources_cited' => [
                'Tài liệu sản phẩm & dịch vụ nội bộ Công ty 2026',
                'Google Search Essentials & Helpful Content Guidelines',
            ],
            'tokens_used' => $totalTokens,
            'cost_vnd' => $costVnd,
            'model' => 'mock-gemini-pro',
            'prompt_version' => $this->promptVersion,
        ];
    }

    /** G5: tất định, không gọi AI. */
    public function regenerateMeta(Article $article, ArticleVersion $current, array $issues, array $options = []): array
    {
        $kw = $article->primary_keyword;

        return [
            'seo_title' => mb_substr('Hướng dẫn '.$kw.' chi tiết', 0, 60),
            'meta_description' => mb_substr("Tìm hiểu {$kw}: khái niệm, cách thực hiện, lưu ý quan trọng và lời khuyên thực tế từ chuyên gia, trình bày rõ ràng, dễ hiểu giúp bạn áp dụng ngay hôm nay vào công việc.", 0, 160),
            'tokens_used' => 150, 'cost_vnd' => 0.0, 'model' => 'mock-gemini-pro', 'prompt_version' => 'v1.0-refresh-meta',
        ];
    }

    /** G5: tất định; giữ đúng h2 của mục và thêm một đoạn giả lập. */
    public function repairSections(Article $article, ArticleVersion $current, array $sections, array $feedback, array $options = []): array
    {
        $out = [];
        foreach ($sections as $s) {
            $h = trim(strip_tags((string) ($s['heading'] ?? '')));
            $out[(int) $s['index']] = '<h2>'.e($h).'</h2><p>Nội dung mục đã được sửa (giả lập) cho từ khóa <strong>'.e($article->primary_keyword).'</strong>.</p>';
        }

        return ['sections' => $out, 'tokens_used' => 200 * max(1, count($out)), 'cost_vnd' => 0.0, 'model' => 'mock-gemini-pro', 'prompt_version' => 'v1.0-refresh-section'];
    }

    public function calculateCostVnd(int $promptTokens, int $completionTokens, string $model): float
    {
        // Giả định định mức chi phí chuẩn trong WBS: ~3.000 VND / bài
        $ratePer1kPromptVnd = 0.8;
        $ratePer1kCompletionVnd = 1.2;

        $cost = ($promptTokens / 1000 * $ratePer1kPromptVnd) + ($completionTokens / 1000 * $ratePer1kCompletionVnd);
        return round(max(2500.0, min(4500.0, $cost * 1000)), 2);
    }
}
