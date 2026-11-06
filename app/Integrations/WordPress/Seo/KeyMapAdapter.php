<?php

namespace App\Integrations\WordPress\Seo;

use App\Models\ArticleVersion;

abstract class KeyMapAdapter implements SeoMetaAdapter
{
    /** @return array{title:string,description:string,focus:string} */
    abstract protected function keys(): array;

    public function metaFor(ArticleVersion $version): array
    {
        $k = $this->keys();
        $meta = [];
        foreach ([
            $k['title'] => $version->seo_title,
            $k['description'] => $version->meta_description,
            $k['focus'] => $version->focus_keyword,
        ] as $key => $value) {
            if ($value !== null && $value !== '') {
                $meta[$key] = (string) $value;
            }
        }

        return $meta;
    }

    public function isPersisted(array $remoteMeta, ArticleVersion $version): ?bool
    {
        $expected = $this->metaFor($version);
        if ($expected === []) {
            return null;
        }
        foreach ($expected as $key => $value) {
            if (($remoteMeta[$key] ?? null) !== $value) {
                return false;
            }
        }

        return true;
    }
}
