<?php

namespace App\Services\Images;

use App\Models\Article;
use App\Models\BrandProfile;
use Illuminate\Support\Str;

/**
 * Họa viên LẬP KẾ HOẠCH ẢNH ngay sau dàn ý (luật, 0 token): bài cần ảnh nào, đặt ở đâu (sau mục H2 nào), vì sao, mô tả ảnh (prompt) và alt.
 * Kế hoạch không phụ thuộc nội dung bài viết nên tạo ảnh chạy song song với bước viết; sau khi bài xong mới lắp ráp theo kế hoạch.
 * Mô tả ảnh dùng ImagePromptBuilder (luật an toàn cố định) cộng gợi ý cảnh lấy từ mục đích và ý chính của mục trong dàn ý.
 */
final class ImagePlanner
{
    private const SKIP_HEADINGS = '/(cau hoi thuong gap|faq|ket luan|tong ket)/';

    /**
     * @param  list<mixed>  $outline  dàn ý do AI trả (mục dạng mảng {level, heading, purpose, key_points...} hoặc chuỗi)
     * @param  array{max_inline?:int, library_cover?:bool, health?:bool, brand?:?BrandProfile}  $options
     * @return array{status:string, skip_code:?string, reason:?string, slots:list<array<string,mixed>>, library_cover:bool}
     */
    public static function plan(Article $article, array $outline, array $options = []): array
    {
        $brand = array_key_exists('brand', $options) ? $options['brand'] : BrandProfile::query()->where('website_id', $article->website_id)->first();
        $health = (bool) ($options['health'] ?? false);
        $libraryCover = (bool) ($options['library_cover'] ?? false);
        $keyword = (string) $article->primary_keyword;
        $out = ['status' => 'ok', 'skip_code' => null, 'reason' => null, 'slots' => [], 'library_cover' => $libraryCover];

        $sections = self::sections($outline);
        $hard = (int) config('agent.images.hard_cap', 4);
        $limit = max(0, min((int) ($options['max_inline'] ?? config('agent.images.max_inline', 2)), $hard - ($libraryCover ? 0 : 1), 3));
        $n = min($limit, count($sections));

        if (! $libraryCover) {
            $h1 = collect($outline)->first(fn ($o) => is_array($o) && strtoupper((string) ($o['level'] ?? '')) === 'H1');
            $out['slots'][] = [
                'id' => 'cover', 'role' => 'featured', 'section_order' => null, 'section_heading' => null, 'aspect' => '16:9',
                'why' => 'Ảnh bìa của bài: làm ảnh đại diện trên website, không nằm trong nội dung.',
                'prompt' => ImagePromptBuilder::build('featured', $keyword, null, $brand, $health, is_array($h1) ? self::hint($h1) : null),
                'alt' => 'Hình minh họa chủ đề '.ImagePromptBuilder::clean($keyword, $brand),
            ];
        }
        // Rải đều các mục dùng được thay vì lấy dồn ở đầu bài.
        for ($i = 0; $i < $n; $i++) {
            $s = $sections[(int) floor($i * count($sections) / $n)];
            $out['slots'][] = [
                'id' => 's'.($i + 1), 'role' => 'inline', 'section_order' => $s['order'], 'section_heading' => $s['heading'], 'aspect' => '4:3',
                'why' => 'Minh họa mục "'.$s['heading'].'"'.($s['purpose'] !== '' ? ': '.Str::limit($s['purpose'], 140, '…') : '').'. Đặt ngay sau đoạn mở đầu của mục.',
                'prompt' => ImagePromptBuilder::build('inline', $keyword, $s['heading'], $brand, $health, $s['hint']),
                'alt' => 'Hình minh họa mục '.ImagePromptBuilder::clean($s['heading'], null),
            ];
        }
        if ($out['slots'] === []) {
            $out['status'] = 'skipped';
            $out['skip_code'] = 'nothing_to_do';
            $out['reason'] = $libraryCover ? 'Đã có ảnh bìa từ thư viện và dàn ý không có mục nào cần minh họa.' : 'Không có mục nào cần ảnh.';
        }

        return $out;
    }

    /** @return list<array{order:int, heading:string, purpose:string, hint:?string}> mục H2 dùng được, theo thứ tự H2 trong dàn ý */
    private static function sections(array $outline): array
    {
        $usable = [];
        $order = 0;
        foreach ($outline as $o) {
            $level = is_array($o) ? strtoupper((string) ($o['level'] ?? 'H2')) : 'H2';
            $heading = trim((string) (is_array($o) ? ($o['heading'] ?? $o['title'] ?? '') : $o));
            if ($level !== 'H2' || $heading === '') {
                continue;
            }
            $order++;
            if (preg_match(self::SKIP_HEADINGS, Str::lower(Str::ascii($heading)))) {
                continue;
            }
            $usable[] = ['order' => $order, 'heading' => $heading, 'purpose' => is_array($o) ? trim((string) ($o['purpose'] ?? '')) : '', 'hint' => is_array($o) ? self::hint($o) : null];
        }

        return $usable;
    }

    /** Gợi ý cảnh: mục đích + ý chính đầu tiên của mục trong dàn ý. */
    private static function hint(array $o): ?string
    {
        $kp = $o['key_points'] ?? [];
        $first = is_array($kp) ? (string) ($kp[0] ?? '') : '';
        $t = trim(((string) ($o['purpose'] ?? '')).' '.$first);

        return $t === '' ? null : $t;
    }
}
