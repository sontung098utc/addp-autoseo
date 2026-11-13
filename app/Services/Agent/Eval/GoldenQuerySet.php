<?php

namespace App\Services\Agent\Eval;

use InvalidArgumentException;

/** Nạp bộ từ khóa chuẩn từ một tệp JSON. Tệp sai định dạng làm cả bộ thất bại, không bỏ sót âm thầm (cùng tinh thần GoldenSet). */
final class GoldenQuerySet
{
    public const DEFAULT_PATH = 'resources/eval/golden-queries.json';

    /** @return list<GoldenQuery> */
    public static function load(?string $path = null): array
    {
        $path ??= base_path(self::DEFAULT_PATH);
        $name = basename($path);
        if (! is_file($path)) {
            throw new InvalidArgumentException("{$name}: không tìm thấy tệp.");
        }
        $data = json_decode((string) file_get_contents($path), true);
        if (! is_array($data) || ! isset($data['queries']) || ! is_array($data['queries'])) {
            throw new InvalidArgumentException("{$name}: JSON hỏng hoặc thiếu `queries`.");
        }
        $out = [];
        $seen = [];
        foreach ($data['queries'] as $i => $q) {
            $where = "{$name} mục #".($i + 1);
            foreach (['id', 'keyword', 'intent', 'risk', 'domain', 'must_answer'] as $k) {
                if (! isset($q[$k])) {
                    throw new InvalidArgumentException("{$where}: thiếu `{$k}`.");
                }
            }
            if (isset($seen[$q['id']])) {
                throw new InvalidArgumentException("Trùng id từ khóa chuẩn: {$q['id']}");
            }
            $seen[$q['id']] = true;
            if (! in_array($q['risk'], GoldenQuery::RISKS, true)) {
                throw new InvalidArgumentException("{$where}: risk không hợp lệ ({$q['risk']}).");
            }
            if (! in_array($q['intent'], GoldenQuery::INTENTS, true)) {
                throw new InvalidArgumentException("{$where}: intent không hợp lệ ({$q['intent']}).");
            }
            if (! is_array($q['must_answer']) || $q['must_answer'] === []) {
                throw new InvalidArgumentException("{$where}: `must_answer` phải có ít nhất một câu hỏi.");
            }
            $qs = [];
            $qids = [];
            foreach ($q['must_answer'] as $m) {
                if (! isset($m['id'], $m['q'], $m['kind']) || ! in_array($m['kind'], GoldenQuery::KINDS, true) || trim((string) $m['q']) === '') {
                    throw new InvalidArgumentException("{$where}: câu hỏi thiếu id/q hoặc kind không hợp lệ.");
                }
                if (isset($qids[$m['id']])) {
                    throw new InvalidArgumentException("{$where}: trùng id câu hỏi {$m['id']}.");
                }
                $qids[$m['id']] = true;
                $qs[] = ['id' => (string) $m['id'], 'q' => (string) $m['q'], 'kind' => (string) $m['kind'], 'needs_source' => (bool) ($m['needs_source'] ?? false)];
            }
            $out[] = new GoldenQuery((string) $q['id'], (string) $q['keyword'], (string) $q['intent'], (string) $q['risk'], (string) $q['domain'], $qs, (string) ($q['note'] ?? ''));
        }

        return $out;
    }

    /**
     * @param  list<GoldenQuery>  $queries
     */
    public static function findByKeyword(array $queries, string $keyword): ?GoldenQuery
    {
        $key = GoldenQuery::normalizeKeyword($keyword);
        foreach ($queries as $q) {
            if (GoldenQuery::normalizeKeyword($q->keyword) === $key) {
                return $q;
            }
        }

        return null;
    }
}
