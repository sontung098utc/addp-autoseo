<?php

namespace App\Observers;

use App\Models\Article;
use App\Models\Keyword;

/**
 * Giữ keywords.status khớp bài: bài bắt đầu viết (rời trạng thái planned) thì từ khóa thành has_article.
 * Khi tạo bài mà chưa có keyword_id (tạo tay, chiến dịch giao từ danh sách từ khóa) thì tìm-hoặc-tạo bản ghi trong kho từ khóa của website
 * và nối vào bài, để mắt xích "Từ khóa" của chuỗi không bị đứt. Không đổi lượt tìm/cụm của từ khóa đã có.
 */
final class ArticleKeywordObserver
{
    public function creating(Article $article): void
    {
        if ($article->keyword_id || ! $article->website_id) {
            return;
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $article->primary_keyword));
        if ($text === '' || mb_strlen($text) > 255) {
            return;
        }
        $keyword = Keyword::firstOrCreate(
            ['website_id' => $article->website_id, 'keyword_key' => Keyword::keyFor($text)],
            ['keyword' => $text, 'source' => 'tay', 'status' => 'new', 'created_by' => $article->created_by],
        );
        $article->keyword_id = $keyword->id;
        $article->cluster_id ??= $keyword->cluster_id;
        $article->origin ??= 'manual';
    }

    public function saved(Article $article): void
    {
        if (! $article->keyword_id) {
            return;
        }
        $status = $article->workflow_status === 'planned' ? 'planned' : 'has_article';
        Keyword::whereKey($article->keyword_id)->where('status', '!=', $status)->update(['status' => $status]);
    }
}
