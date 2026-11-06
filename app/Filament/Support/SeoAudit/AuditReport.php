<?php

namespace App\Filament\Support\SeoAudit;

/** Gom phát hiện thành nhóm lỗi theo loại, gán mức theo IssueCatalog và so xu hướng với lần quét trước. */
final class AuditReport
{
    /**
     * @param  list<array{type: string, url: string, measured: string, evidence: string}>  $findings
     * @param  array<string, int>|null  $previousCounts  số URL mỗi loại của lần quét trước (null = chưa có lần trước)
     * @return list<array{type: string, label: string, sub: string, severity: string, urls: int, occurrences: int, delta: ?int, items: list<array{url: string, measured: string, evidence: string}>}>
     */
    public static function groups(array $findings, ?array $previousCounts = null): array
    {
        $by = [];
        foreach ($findings as $f) {
            if (! IssueCatalog::exists($f['type'])) {
                continue;
            }
            $by[$f['type']][] = ['url' => $f['url'], 'measured' => $f['measured'], 'evidence' => $f['evidence']];
        }
        $groups = [];
        foreach ($by as $type => $items) {
            $urls = count(array_unique(array_column($items, 'url')));
            $groups[] = [
                'type' => $type,
                'label' => IssueCatalog::label($type),
                'sub' => IssueCatalog::sub($type),
                'severity' => IssueCatalog::severity($type),
                'urls' => $urls,
                'occurrences' => count($items),
                'delta' => $previousCounts === null ? null : $urls - ($previousCounts[$type] ?? 0),
                'items' => $items,
            ];
        }
        usort($groups, fn ($a, $b) => [IssueCatalog::rank($a['severity']), -$a['urls'], $a['type']] <=> [IssueCatalog::rank($b['severity']), -$b['urls'], $b['type']]);

        return $groups;
    }

    /** @param list<array{type: string, urls: int}> $groups @return array<string, int> */
    public static function counts(array $groups): array
    {
        $c = [];
        foreach ($groups as $g) {
            $c[$g['type']] = $g['urls'];
        }

        return $c;
    }

    /**
     * @param  list<array{type: string, url: string}>  $findings
     * @param  list<array{severity: string, urls: int}>  $groups
     * @return array{total: int, urls_with_issue: int, groups: int, by_severity: array<string, int>}
     */
    public static function summary(array $findings, array $groups): array
    {
        $sev = ['P0' => 0, 'P1' => 0, 'P2' => 0];
        foreach ($groups as $g) {
            $sev[$g['severity']] = ($sev[$g['severity']] ?? 0) + 1;
        }

        return [
            'total' => count($findings),
            'urls_with_issue' => count(array_unique(array_column($findings, 'url'))),
            'groups' => count($groups),
            'by_severity' => $sev,
        ];
    }
}
