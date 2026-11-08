<?php

namespace App\Filament\Support\Keywords;

use App\Filament\Pages\ContentPlanPage;
use App\Filament\Resources\ArticleVersionResource;
use App\Filament\Support\StatusPresenter;
use App\Models\Article;
use App\Models\Keyword;
use App\Models\Website;

/**
 * Nối từ khóa -> bài viết (hai chiều với articles.keyword_id). Trạng thái luôn tính từ bài hiện có, nên không
 * lệch dù keywords.status chưa đồng bộ. Khớp theo keyword_id, nếu không có thì theo khóa chuẩn hóa của primary_keyword.
 */
final class KeywordArticleLinks
{
    /**
     * @param  list<string>  $keywords
     * @return array<string, array{label:string, tone:string, status:?string, article_id:?int, href:?string}> theo keyword_key
     */
    public static function for(?Website $site, array $keywords): array
    {
        $out = [];
        foreach ($keywords as $k) {
            $out[Keyword::keyFor($k)] = self::none();
        }
        if (! $site || $keywords === []) {
            return $out;
        }
        $byKeywordId = Keyword::where('website_id', $site->id)->pluck('keyword_key', 'id')->all();
        foreach (Article::with('latestVersion')->where('website_id', $site->id)->orderBy('id')->get() as $a) {
            $key = ($a->keyword_id && isset($byKeywordId[$a->keyword_id])) ? $byKeywordId[$a->keyword_id] : Keyword::keyFor((string) $a->primary_keyword);
            if (! isset($out[$key]) || $out[$key]['article_id'] !== null) {
                continue; // chỉ lấy bài đầu tiên của mỗi từ khóa
            }
            $out[$key] = self::describe($a);
        }

        return $out;
    }

    /** @return array{label:string, tone:string, status:?string, article_id:?int, href:?string} */
    public static function describe(Article $a): array
    {
        $status = (string) $a->workflow_status;
        $label = match ($status) {
            'drafting' => 'Đang viết',
            default => StatusPresenter::label($status),
        };
        $tone = match (StatusPresenter::color($status)) {
            'success' => 'ok', 'warning' => 'warn', 'danger' => 'danger', 'info' => 'info', default => 'muted',
        };
        $href = $a->latestVersion ? ArticleVersionResource::getUrl('view', ['record' => $a->latestVersion->id]) : ContentPlanPage::getUrl();

        return ['label' => $label, 'tone' => $tone, 'status' => $status, 'article_id' => $a->id, 'href' => $href];
    }

    /** @return array{label:string, tone:string, status:?string, article_id:?int, href:?string} */
    public static function none(): array
    {
        return ['label' => 'Chưa có', 'tone' => 'muted', 'status' => null, 'article_id' => null, 'href' => ContentPlanPage::getUrl()];
    }
}
