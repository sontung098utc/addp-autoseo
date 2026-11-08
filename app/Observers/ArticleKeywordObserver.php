<?php

namespace App\Observers;

use App\Models\Article;
use App\Models\Keyword;

/** Giữ keywords.status khớp bài: bài bắt đầu viết (rời trạng thái planned) thì từ khóa thành has_article. */
final class ArticleKeywordObserver
{
    public function saved(Article $article): void
    {
        if (! $article->keyword_id) {
            return;
        }
        $status = $article->workflow_status === 'planned' ? 'planned' : 'has_article';
        Keyword::whereKey($article->keyword_id)->where('status', '!=', $status)->update(['status' => $status]);
    }
}
