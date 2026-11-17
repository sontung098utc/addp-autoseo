<?php

namespace App\Services\Search;

use App\Contracts\WebSearchProvider;

/**
 * Tìm web bằng NHIỀU nguồn cùng lúc: dịch vụ tìm kiếm (Tavily) VÀ chính AI tự tìm (cầu nối Claude/Codex hoặc Gemini). Gộp kết quả, bỏ trùng địa chỉ,
 * xen kẽ để cả hai cùng góp mặt trong số kết quả cuối. Một bên lỗi thì bên kia vẫn dùng được; chỉ khi TẤT CẢ lỗi mới ném lỗi (ưu tiên lỗi hết hạn mức).
 */
final class CombinedSearchProvider implements WebSearchProvider
{
    /** @param  list<WebSearchProvider>  $members */
    public function __construct(private array $members) {}

    /** Thành viên là cầu nối Claude (để tìm trước nhiều truy vấn song song), nếu có. */
    public function bridge(): ?ClaudeBridgeSearchProvider
    {
        foreach ($this->members as $m) {
            if ($m instanceof ClaudeBridgeSearchProvider) {
                return $m;
            }
        }

        return null;
    }

    public function search(string $query, array $options = []): array
    {
        $lists = [];
        $names = [];
        $cost = 0.0;
        $errors = [];
        foreach ($this->members as $m) {
            try {
                $r = $m->search($query, $options);
                $lists[] = $r['results'];
                $names[] = (string) $r['provider'];
                $cost += (float) $r['cost_vnd'];
            } catch (WebSearchUnavailable $e) {
                $errors[] = $e;
            }
        }
        if ($lists === []) {
            usort($errors, fn ($a, $b) => ($b->getCode() === 429) <=> ($a->getCode() === 429));
            throw $errors[0] ?? new WebSearchUnavailable('Không có nguồn tìm web nào dùng được.');
        }
        $max = max(1, min(10, (int) ($options['max_results'] ?? config('search.max_results', 10))));
        $seen = [];
        $out = [];
        for ($i = 0; count($out) < $max; $i++) {
            $any = false;
            foreach ($lists as $list) {
                if (! isset($list[$i])) {
                    continue;
                }
                $any = true;
                $k = $this->norm($list[$i]['url']);
                if (! isset($seen[$k]) && count($out) < $max) {
                    $seen[$k] = true;
                    $out[] = $list[$i];
                }
            }
            if (! $any) {
                break;
            }
        }

        return ['results' => $out, 'cost_vnd' => $cost, 'provider' => implode('+', $names), 'failed' => array_map(fn ($e) => $e->getMessage(), $errors)];
    }

    /** Hai địa chỉ chỉ khác dấu / cuối, #mục, tham số theo dõi, www thì coi là một. */
    private function norm(string $url): string
    {
        $p = parse_url($url);
        $q = [];
        parse_str((string) ($p['query'] ?? ''), $q);
        foreach (array_keys($q) as $k) {
            if (str_starts_with(strtolower((string) $k), 'utm_') || in_array(strtolower((string) $k), ['gclid', 'fbclid'], true)) {
                unset($q[$k]);
            }
        }

        return preg_replace('#^www\.#', '', strtolower((string) ($p['host'] ?? ''))).rtrim((string) ($p['path'] ?? ''), '/').($q ? '?'.http_build_query($q) : '');
    }
}
