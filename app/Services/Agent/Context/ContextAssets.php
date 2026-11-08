<?php

namespace App\Services\Agent\Context;

use App\Models\Article;
use App\Models\ArticleVersion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * AG-S25: cầu nối giữa AgentRunOrchestrator và ảnh/liên kết nội bộ (orchestrator chỉ gọi vài hàm).
 * Truy xuất MỘT lần mỗi lượt chạy (vòng sửa dùng lại). Lỗi ở đây không làm hỏng lượt chạy: coi như không có ứng viên.
 */
final class ContextAssets
{
    private ?array $images = null;

    private ?array $links = null;

    public function __construct(private ImageSelector $selector = new ImageSelector(), private InternalLinkSuggester $suggester = new InternalLinkSuggester())
    {
    }

    /** @param list<mixed> $outline */
    public function load(Article $article, array $outline): void
    {
        if ($this->images !== null) {
            return;
        }
        [$this->images, $this->links] = [[], []];
        try {
            $this->images = $this->selector->select($article, $outline);
            $this->links = $this->suggester->suggest($article, $outline);
        } catch (\Throwable $e) {
            Log::warning('Ảnh/liên kết nội bộ: truy xuất lỗi, bỏ qua.', ['error' => get_class($e)]);
        }
    }

    /** @return array<string,mixed> options cho adapter AI (chỉ khóa có dữ liệu) */
    public function options(): array
    {
        return ($this->images ? ['images' => $this->images] : []) + ($this->links ? ['internal_links' => $this->links] : []);
    }

    /**
     * Dữ liệu ghi vào bước 'write' và đoạn ghi chú ngắn. $html là HTML đã làm sạch (để đếm ảnh/liên kết thực sự được chèn).
     *
     * @return array{0:array<string,mixed>,1:string}
     */
    public function stepInfo(string $html = ''): array
    {
        $images = $this->images ?? [];
        $links = $this->links ?? [];
        $usedImg = $this->insertedImages($html, $images);
        $usedLinks = $this->insertedLinks($html, $links);
        $data = [
            'images' => ['selected' => array_map(fn ($i) => ['id' => $i['id'], 'diem' => $i['diem'], 'ly_do' => $i['ly_do'], 'alt' => $i['alt_goi_y'], 'url' => $i['url']], $images), 'inserted_ids' => $usedImg, 'warning' => $images === [] ? 'Không có ảnh phù hợp trong thư viện' : null],
            'internal_links' => ['suggested' => array_map(fn ($l) => ['url' => $l['url'], 'anchor' => $l['anchor_goi_y'], 'diem' => $l['diem'], 'ly_do' => $l['ly_do']], $links), 'inserted' => $usedLinks],
        ];
        $note = ($images === [] ? ' Không có ảnh phù hợp trong thư viện (chỉ nhắc, không chặn).' : ' Gợi ý '.count($images).' ảnh từ thư viện (đã chèn '.count($usedImg).').')
            .($links === [] ? '' : ' Gợi ý '.count($links).' liên kết nội bộ (đã chèn '.count($usedLinks).').');

        return [$data, $note];
    }

    /** Sau khi lưu bản bài: gắn ảnh điểm cao nhất làm ảnh đại diện nếu bản chưa có. Trả id ảnh đã gắn hoặc null. */
    public function attachFeatured(ArticleVersion $version): ?int
    {
        $best = ($this->images ?? [])[0] ?? null;
        if (! $best || ! $version->exists) {
            return null;
        }
        try {
            if ($version->mediaAssets()->wherePivot('is_featured', true)->exists()) {
                return null;
            }

            return DB::transaction(function () use ($version, $best) {
                $version->mediaAssets()->syncWithoutDetaching([$best['id'] => ['is_featured' => true, 'position' => 0, 'custom_alt' => $best['alt_goi_y']]]);

                return (int) $best['id'];
            });
        } catch (\Throwable $e) {
            Log::warning('Không gắn được ảnh đại diện.', ['error' => get_class($e)]);

            return null;
        }
    }

    /** @return list<int> id ảnh trong danh sách mà HTML thực sự dùng (so theo src) */
    private function insertedImages(string $html, array $images): array
    {
        if ($html === '' || $images === []) {
            return [];
        }
        $srcs = [];
        foreach (self::attrs($html, 'img', 'src') as $s) {
            $srcs[] = $s;
        }

        return array_values(array_map(fn ($i) => $i['id'], array_filter($images, fn ($i) => in_array($i['url'], $srcs, true))));
    }

    /** @return list<string> URL gợi ý mà HTML thực sự có <a href> */
    private function insertedLinks(string $html, array $links): array
    {
        if ($html === '' || $links === []) {
            return [];
        }
        $keys = array_map(fn ($h) => self::linkKey($h), self::attrs($html, 'a', 'href'));

        return array_values(array_map(fn ($l) => $l['url'], array_filter($links, fn ($l) => in_array(self::linkKey($l['url']), $keys, true))));
    }

    private static function linkKey(string $u): string
    {
        $h = InternalLinkSuggester::host($u);

        return $h === null ? $u : InternalLinkSuggester::key((string) InternalLinkSuggester::normalizeOwn($u, $h));
    }

    /** @return list<string> */
    public static function attrs(string $html, string $tag, string $attr): array
    {
        if (preg_match_all('#<'.$tag.'\b[^>]*?\b'.$attr.'\s*=\s*(?:"([^"]*)"|\'([^\']*)\')#i', $html, $m, PREG_SET_ORDER) === false) {
            return [];
        }

        return array_map(fn ($x) => html_entity_decode($x[1] !== '' ? $x[1] : ($x[2] ?? ''), ENT_QUOTES | ENT_HTML5), $m);
    }
}
