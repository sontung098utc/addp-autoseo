<?php

namespace App\Services\Audit;

use App\Filament\Support\SeoAudit\IssueCatalog;
use App\Models\Article;
use App\Models\ArticleVersion;
use App\Models\AuditSuggestion;

/** AG-S26: dữ kiện "làm mới bài" truyền cho bước viết (options['refresh']). Chỉ bài kind=refresh, origin=audit. */
final class RefreshOptions
{
    /** @return array{refresh?: array{url: string, issue_codes: list<string>, existing_title: string}} */
    public static function for(Article $article): array
    {
        if ($article->kind !== 'refresh' || $article->origin !== 'audit') {
            return [];
        }
        $s = AuditSuggestion::query()->where('article_task_id', $article->id)->first();
        if (! $s) {
            return [];
        }
        $title = ArticleVersion::query()->where('article_id', $s->article_id)->orderByDesc('version')->value('title');

        return ['refresh' => [
            'url' => $s->url,
            'issue_codes' => array_values(array_filter((array) $s->issue_codes, fn ($t) => IssueCatalog::exists((string) $t))),
            'existing_title' => (string) $title,
        ]];
    }
}
