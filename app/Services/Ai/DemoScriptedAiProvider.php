<?php

namespace App\Services\Ai;

use App\Contracts\AiProviderInterface;
use App\Models\Article;
use Illuminate\Support\Str;

/**
 * AG-S01: AI GIẢ LẬP THEO KỊCH BẢN, chỉ để xem vòng "viết → chấm → sửa" hội tụ thế nào. KHÔNG phải AI thật.
 * Lần viết đầu cố ý ngắn và thiếu từ khóa; khi nhận danh sách lỗi chặn (options['feedback']) thì trả bản đầy đủ.
 * Phiên chạy dùng nó luôn được ghi nhãn "AI giả lập"; nội dung chỉ là chữ ghép để qua luật, không có giá trị biên tập.
 */
final class DemoScriptedAiProvider implements AiProviderInterface
{
    private const LEX = ['khách', 'hàng', 'dịch', 'vụ', 'giải', 'pháp', 'doanh', 'nghiệp', 'quy', 'trình', 'chất', 'lượng', 'kinh', 'nghiệm', 'thực', 'tế', 'hiệu', 'quả',
        'chi', 'phí', 'thời', 'gian', 'tư', 'vấn', 'triển', 'khai', 'kết', 'quả', 'đánh', 'giá', 'kế', 'hoạch', 'mục', 'tiêu', 'người', 'dùng', 'nội', 'dung'];

    public function generateOutline(Article $article, array $options = []): array
    {
        return ['outline' => ['Tổng quan', 'Điều cần biết', 'Cách thực hiện'], 'tokens_used' => 120, 'cost_vnd' => 0.0, 'model' => 'demo-kich-ban'];
    }

    public function generateArticle(Article $article, array $outline, array $options = []): array
    {
        $kw = $article->primary_keyword;
        $fixed = ! empty($options['feedback']);
        $html = $fixed ? $this->full($kw) : '<h2>Giới thiệu</h2><p>Bài viết nháp ngắn, chưa đủ ý.</p>';

        return [
            'title' => 'Tìm hiểu '.$kw, 'slug' => Str::slug($kw), 'html_content' => $html,
            'seo_title' => mb_substr($kw.': điều cần biết', 0, 60),
            'meta_description' => str_pad("Hướng dẫn {$kw}: những điều cần biết, cách thực hiện và lưu ý quan trọng, trình bày dễ hiểu cho người mới", 130, '.'),
            'focus_keyword' => $kw, 'faq_schema' => [], 'sources_cited' => [],
            'tokens_used' => $fixed ? 1800 : 300, 'cost_vnd' => $fixed ? 90.0 : 15.0, 'model' => 'demo-kich-ban', 'prompt_version' => 'demo',
        ];
    }

    public function calculateCostVnd(int $promptTokens, int $completionTokens, string $model): float
    {
        return 0.0;
    }

    private function full(string $kw): string
    {
        // Hạt giống theo từ khóa để hai bài khác nhau không trùng nội dung (luật DUP01 sẽ chặn nếu trùng).
        $s = (crc32($kw) % 9000) * 3;
        return "<h2>Tổng quan về {$kw}</h2><p>Bài viết này giải thích {$kw}. ".$this->words(290, 1 + $s).'</p>'
            .'<h2>Điều cần biết</h2><p>'.$this->words(300, 2 + $s).'</p>'
            .'<h2>Cách thực hiện</h2><p>'.$this->words(300, 3 + $s).'</p>';
    }

    private function words(int $n, int $seed): string
    {
        $x = $seed * 7919 + 13;
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $x = ($x * 1103515245 + 12345) & 0x7fffffff;
            $out[] = self::LEX[($x >> 8) % count(self::LEX)];
        }

        return implode(' ', $out);
    }
}
