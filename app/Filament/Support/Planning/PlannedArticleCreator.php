<?php

namespace App\Filament\Support\Planning;

use App\Models\Article;
use App\Models\User;
use App\Models\Website;
use InvalidArgumentException;

/**
 * Hành động THẬT dùng chung cho mọi màn lập kế hoạch (Kế hoạch nội dung, Đối thủ, ...): tạo bản ghi bài trạng
 * thái "Đã lên kế hoạch" cho một từ khóa. Luật: writer trở lên; website phải đang hoạt động; mỗi từ khóa chính
 * chỉ một bài trên mỗi website (so khớp không phân biệt hoa/thường, gộp khoảng trắng).
 */
final class PlannedArticleCreator
{
    public static function normalize(string $keyword): string
    {
        return mb_strtolower(trim((string) preg_replace('/\s+/u', ' ', $keyword)));
    }

    /** Bài đã dùng từ khóa này trên website (null nếu chưa). */
    public static function existing(Website|int $website, string $keyword): ?Article
    {
        $websiteId = $website instanceof Website ? $website->id : $website;
        $n = self::normalize($keyword);

        return Article::where('website_id', $websiteId)->get(['id', 'primary_keyword'])
            ->first(fn (Article $a) => self::normalize((string) $a->primary_keyword) === $n);
    }

    /** @param  list<string>  $secondary */
    public function create(User $actor, Website $website, string $keyword, ?string $cluster = null, string $intent = 'informational', array $secondary = []): Article
    {
        if (! $actor->is_active || ! $actor->isWriter()) {
            throw new InvalidArgumentException('Vai trò hiện tại không đủ quyền tạo bài.');
        }
        if ($website->status !== 'active') {
            throw new InvalidArgumentException('Website đích chưa ở trạng thái Hoạt động.');
        }
        $keyword = trim((string) preg_replace('/\s+/u', ' ', $keyword));
        if ($keyword === '' || mb_strlen($keyword) > 255) {
            throw new InvalidArgumentException('Từ khóa chính không hợp lệ.');
        }
        if (! in_array($intent, ['informational', 'commercial', 'transactional', 'navigational'], true)) {
            throw new InvalidArgumentException('Ý định tìm kiếm không hợp lệ.');
        }
        if ($dup = self::existing($website, $keyword)) {
            throw new DuplicateKeywordException($dup->id, $keyword);
        }

        return Article::create([
            'website_id' => $website->id,
            'created_by' => $actor->id,
            'primary_keyword' => $keyword,
            'secondary_keywords' => array_values(array_filter(array_map('trim', $secondary))),
            'search_intent' => $intent,
            'topic_cluster' => $cluster,
            'workflow_status' => 'planned',
        ]);
    }
}
