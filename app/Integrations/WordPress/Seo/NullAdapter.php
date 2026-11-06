<?php

namespace App\Integrations\WordPress\Seo;

use App\Models\ArticleVersion;

class NullAdapter implements SeoMetaAdapter
{
    public function metaFor(ArticleVersion $version): array
    {
        return [];
    }

    public function isPersisted(array $remoteMeta, ArticleVersion $version): ?bool
    {
        return null;
    }
}
