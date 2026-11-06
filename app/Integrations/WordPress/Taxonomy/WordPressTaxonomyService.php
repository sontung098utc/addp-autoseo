<?php

namespace App\Integrations\WordPress\Taxonomy;

use App\Integrations\WordPress\Exceptions\WordPressAmbiguousException;
use App\Integrations\WordPress\Exceptions\WordPressException;
use App\Integrations\WordPress\WordPressClient;
use App\Models\Website;

/** Ánh xạ tên category/tag -> id. Lỗi không bao giờ làm hỏng việc đăng: term bị bỏ qua và báo lại. */
class WordPressTaxonomyService
{
    public function __construct(protected WordPressClient $client)
    {
    }

    /**
     * @param 'categories'|'tags' $type
     * @return array{ids:int[], skipped:string[]}
     */
    public function resolve(Website $website, string $type, array $names, bool $allowCreate): array
    {
        $ids = [];
        $skipped = [];
        foreach (array_unique(array_filter(array_map('trim', array_map('strval', $names)))) as $name) {
            try {
                $id = $this->find($website, $type, $name);
                if ($id === null && $allowCreate) {
                    $id = $this->create($website, $type, $name);
                }
            } catch (WordPressException|WordPressAmbiguousException) {
                $id = null;
            }
            $id !== null ? $ids[] = $id : $skipped[] = $name;
        }

        return ['ids' => array_values(array_unique($ids)), 'skipped' => $skipped];
    }

    protected function find(Website $website, string $type, string $name): ?int
    {
        $res = $this->client->request($website, 'GET', "/wp/v2/{$type}", [
            'query' => ['search' => $name, 'per_page' => 100, '_fields' => 'id,name'],
        ]);
        foreach ($res['json'] as $term) {
            if (is_array($term) && isset($term['id'], $term['name'])
                && mb_strtolower(html_entity_decode((string) $term['name'])) === mb_strtolower($name)) {
                return (int) $term['id'];
            }
        }

        return null;
    }

    protected function create(Website $website, string $type, string $name): ?int
    {
        $res = $this->client->request($website, 'POST', "/wp/v2/{$type}", ['json' => ['name' => $name]], true);

        return isset($res['json']['id']) ? (int) $res['json']['id'] : null;
    }
}
