<?php

namespace App\Filament\Support\Gsc;

use App\Filament\Resources\ArticleResource;
use App\Models\Publication;

/** Nối URL trang trong Search Console với bài đã đăng (publications.published_url). Không khớp -> null. */
final class PageArticleLinker
{
    /** @return array<string,array{title:string,url:string}> theo URL chuẩn hóa */
    public static function map(): array
    {
        $map = [];
        foreach (Publication::query()->with('articleVersion')->whereNotNull('published_url')->orderBy('id')->get() as $p) {
            $v = $p->articleVersion;
            if (! $v) {
                continue;
            }
            $map[self::normalize((string) $p->published_url)] ??= [
                'title' => (string) $v->title,
                'url' => ArticleResource::getUrl('view', ['record' => $v->article_id]),
            ];
        }

        return $map;
    }

    public static function normalize(string $url): string
    {
        $parts = parse_url(trim($url));
        if (! is_array($parts) || ! isset($parts['host'])) {
            return strtolower(rtrim($url, '/'));
        }

        return strtolower($parts['host']).rtrim($parts['path'] ?? '', '/');
    }

    /** @return array{title:string,url:string}|null */
    public static function find(?string $pageUrl, ?array $map = null): ?array
    {
        if ($pageUrl === null) {
            return null;
        }

        return ($map ?? self::map())[self::normalize($pageUrl)] ?? null;
    }
}
