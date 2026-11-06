<?php

namespace App\Filament\Support\Keywords;

use InvalidArgumentException;

/**
 * Luật nghiệp vụ của màn Từ khóa (PHP thuần, có unit test). Mọi thay đổi cụm giữ bất biến: mỗi từ khóa thuộc
 * tối đa MỘT cụm; `keywords.list[].cluster` và `keywords.clusters[].keywords` luôn khớp nhau.
 */
final class KeywordService
{
    public function __construct(private readonly KeywordRepository $repo) {}

    public static function make(): self
    {
        return new self(new DemoKeywordRepository);
    }

    public function repo(): KeywordRepository
    {
        return $this->repo;
    }

    /** @return list<string> */
    public function existingKeywords(): array
    {
        return array_column($this->repo->all(), 'keyword');
    }

    /** Lưu các dòng hợp lệ đã xem trước. @param list<array<string,mixed>> $rows @return int số đã lưu */
    public function addRows(array $rows, string $source = 'csv'): int
    {
        $list = $this->repo->all();
        $seen = [];
        foreach ($list as $r) {
            $seen[CsvKeywordParser::key($r['keyword'])] = true;
        }
        $next = (int) max(0, ...array_column($list, 'id')) + 1;
        $added = 0;
        foreach ($rows as $r) {
            $k = CsvKeywordParser::key($r['keyword']);
            if (isset($seen[$k])) {
                continue;
            }
            $seen[$k] = true;
            $list[] = ['id' => $next++, 'keyword' => CsvKeywordParser::normalizeKeyword($r['keyword']), 'volume' => $r['volume'] ?? null, 'difficulty' => null, 'intent' => $r['intent'] ?? KeywordIntent::DEFAULT, 'source' => $source, 'cluster' => null];
            $added++;
        }
        $this->repo->saveList($list);

        return $added;
    }

    /** Thêm tay một từ khóa; ném InvalidArgumentException với lý do rõ ràng. */
    public function addManual(string $keyword, string $volume, string $intent): void
    {
        $kw = CsvKeywordParser::normalizeKeyword($keyword);
        if ($kw === '' || mb_strlen($kw) > CsvKeywordParser::MAX_KEYWORD_LENGTH) {
            throw new InvalidArgumentException('Từ khóa không được để trống và tối đa 255 ký tự.');
        }
        $vol = null;
        if (trim($volume) !== '') {
            $vol = CsvKeywordParser::parseVolume(trim($volume));
            if ($vol === null) {
                throw new InvalidArgumentException('Volume phải là số nguyên không âm.');
            }
        }
        $in = KeywordIntent::normalize($intent);
        if ($in === null) {
            throw new InvalidArgumentException('Ý định tìm kiếm không hợp lệ.');
        }
        if ($this->addRows([['keyword' => $kw, 'volume' => $vol, 'intent' => $in]], 'tay') === 0) {
            throw new InvalidArgumentException('Từ khóa "'.$kw.'" đã có trong kho (không phân biệt hoa/thường).');
        }
    }

    /** Xóa từ khóa khỏi kho + khỏi cụm. Không đụng tới bài đã tạo. */
    public function delete(int $id): void
    {
        $list = $this->repo->all();
        $kw = null;
        foreach ($list as $r) {
            if ($r['id'] === $id) {
                $kw = $r['keyword'];
            }
        }
        if ($kw === null) {
            return;
        }
        $this->repo->saveList(array_values(array_filter($list, fn ($r) => $r['id'] !== $id)));
        $this->saveClustersSynced(array_map(fn ($c) => ['keywords' => array_values(array_filter($c['keywords'], fn ($k) => $k !== $kw))] + $c, $this->repo->clusters()));
    }

    public function renameCluster(string $old, string $new): void
    {
        $new = CsvKeywordParser::normalizeKeyword($new);
        if ($new === '' || mb_strlen($new) > 120) {
            throw new InvalidArgumentException('Tên cụm không được trống, tối đa 120 ký tự.');
        }
        $clusters = $this->repo->clusters();
        if (mb_strtolower($old) !== mb_strtolower($new) && collect($clusters)->contains(fn ($c) => mb_strtolower($c['name']) === mb_strtolower($new))) {
            throw new InvalidArgumentException('Đã có cụm tên "'.$new.'". Muốn gộp hãy dùng chức năng Gộp.');
        }
        $this->saveClustersSynced(array_map(fn ($c) => $c['name'] === $old ? ['name' => $new] + $c : $c, $clusters));
    }

    // ----- Đề xuất gom cụm (minh họa) -----

    /** @return int số đề xuất tạo ra (chỉ gom các từ khóa CHƯA có cụm) */
    public function propose(): int
    {
        $free = array_values(array_map(fn ($r) => $r['keyword'], array_filter($this->repo->all(), fn ($r) => $r['cluster'] === null)));
        $groups = (new MockClusterer)->cluster($free);
        $byKw = [];
        foreach ($this->repo->all() as $r) {
            $byKw[$r['keyword']] = $r['intent'];
        }
        $props = [];
        foreach ($groups as $i => $g) {
            $props[] = ['id' => $i + 1, 'name' => $g['name'], 'intent' => self::mainIntent($g['keywords'], $byKw), 'keywords' => $g['keywords']];
        }
        $this->repo->saveProposals($props);

        return count($props);
    }

    /** Nhận đề xuất thành cụm thật (trùng tên cụm đã có thì nhập vào cụm đó). */
    public function accept(int $proposalId): void
    {
        [$p, $rest] = $this->takeProposal($proposalId);
        $this->repo->saveProposals($rest);
        $clusters = $this->repo->clusters();
        $merged = false;
        foreach ($clusters as &$c) {
            if (mb_strtolower($c['name']) === mb_strtolower($p['name'])) {
                $c['keywords'] = array_values(array_unique(array_merge($c['keywords'], $p['keywords'])));
                $merged = true;
            }
        }
        unset($c);
        if (! $merged) {
            $clusters[] = ['name' => $p['name'], 'intent' => $p['intent'], 'keywords' => $p['keywords']];
        }
        $this->saveClustersSynced($clusters);
    }

    public function renameProposal(int $id, string $name): void
    {
        $name = CsvKeywordParser::normalizeKeyword($name);
        if ($name === '' || mb_strlen($name) > 120) {
            throw new InvalidArgumentException('Tên cụm không được trống, tối đa 120 ký tự.');
        }
        $this->repo->saveProposals(array_map(fn ($p) => $p['id'] === $id ? ['name' => $name] + $p : $p, $this->repo->proposals()));
    }

    public function discard(int $id): void
    {
        [, $rest] = $this->takeProposal($id);
        $this->repo->saveProposals($rest);
    }

    /** Gộp đề xuất $from vào $into (tên giữ của $into). */
    public function mergeProposals(int $into, int $from): void
    {
        if ($into === $from) {
            throw new InvalidArgumentException('Chọn hai đề xuất khác nhau để gộp.');
        }
        [$f, $rest] = $this->takeProposal($from);
        $this->repo->saveProposals($rest);
        $this->repo->saveProposals(array_map(fn ($p) => $p['id'] === $into ? ['keywords' => array_values(array_unique(array_merge($p['keywords'], $f['keywords'])))] + $p : $p, $this->repo->proposals()));
    }

    /** Tách $keywords ra khỏi đề xuất thành đề xuất mới tên $newName. @param list<string> $keywords */
    public function splitProposal(int $id, array $keywords, string $newName): void
    {
        $newName = CsvKeywordParser::normalizeKeyword($newName);
        if ($newName === '') {
            throw new InvalidArgumentException('Nhập tên cho cụm mới.');
        }
        $props = $this->repo->proposals();
        $nextId = (int) max(0, ...array_column($props, 'id')) + 1;
        $new = null;
        foreach ($props as &$p) {
            if ($p['id'] !== $id) {
                continue;
            }
            $moved = array_values(array_intersect($p['keywords'], $keywords));
            if ($moved === [] || count($moved) === count($p['keywords'])) {
                throw new InvalidArgumentException('Chọn ít nhất một từ khóa và để lại ít nhất một từ khóa ở cụm cũ.');
            }
            $p['keywords'] = array_values(array_diff($p['keywords'], $moved));
            $new = ['id' => $nextId, 'name' => $newName, 'intent' => $p['intent'], 'keywords' => $moved];
        }
        unset($p);
        if ($new === null) {
            throw new InvalidArgumentException('Không tìm thấy đề xuất.');
        }
        $props[] = $new;
        $this->repo->saveProposals($props);
    }

    // ----- nội bộ -----

    /** @return array{0: array<string,mixed>, 1: list<array<string,mixed>>} */
    private function takeProposal(int $id): array
    {
        $found = null;
        $rest = [];
        foreach ($this->repo->proposals() as $p) {
            $p['id'] === $id ? $found = $p : $rest[] = $p;
        }
        if ($found === null) {
            throw new InvalidArgumentException('Không tìm thấy đề xuất (có thể đã được xử lý).');
        }

        return [$found, $rest];
    }

    /** Lưu cụm, bỏ cụm rỗng, và đồng bộ lại `cluster` của từng từ khóa (mỗi từ khóa tối đa một cụm). */
    private function saveClustersSynced(array $clusters): void
    {
        $clusters = array_values(array_filter($clusters, fn ($c) => $c['keywords'] !== []));
        $owner = [];
        foreach ($clusters as $ci => $c) {
            foreach ($c['keywords'] as $k) {
                $owner[$k] ??= $ci;
            }
        }
        foreach ($clusters as $ci => &$c) {
            $c['keywords'] = array_values(array_filter($c['keywords'], fn ($k) => $owner[$k] === $ci));
        }
        unset($c);
        $clusters = array_values(array_filter($clusters, fn ($c) => $c['keywords'] !== []));
        $list = array_map(function ($r) use ($clusters) {
            $r['cluster'] = null;
            foreach ($clusters as $c) {
                if (in_array($r['keyword'], $c['keywords'], true)) {
                    $r['cluster'] = $c['name'];
                    break;
                }
            }

            return $r;
        }, $this->repo->all());
        $this->repo->saveClusters($clusters);
        $this->repo->saveList($list);
    }

    /** @param list<string> $keywords @param array<string,string> $intents */
    private static function mainIntent(array $keywords, array $intents): string
    {
        $count = [];
        foreach ($keywords as $k) {
            $i = $intents[$k] ?? KeywordIntent::DEFAULT;
            $count[$i] = ($count[$i] ?? 0) + 1;
        }
        arsort($count);

        return (string) array_key_first($count);
    }
}
