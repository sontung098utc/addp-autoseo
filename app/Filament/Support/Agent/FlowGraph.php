<?php

namespace App\Filament\Support\Agent;

use Illuminate\Support\Collection;

/**
 * Sơ đồ luồng của MỘT phiên viết bài, dựng từ ĐƯỜNG ĐI THẬT chứ không từ khuôn dựng sẵn: chỉ những chặng đã chạy (và chặng đang chạy) mới xuất hiện, theo đúng thứ tự đã diễn ra.
 * Giữa hai chặng là quyết định của người điều phối (nếu có): chấm xong chưa đạt → yêu cầu sửa, thiếu nguồn → bổ sung nguồn, kho thiếu → nạp thêm... Phiên nào đi đường nào thì sơ đồ ra đường đó;
 * vòng lặp xuất hiện thành các chặng lặp lại, chặng không chạy thì không có. Tạo ảnh chạy song song với Viết nên nằm cạnh khối Viết.
 */
final class FlowGraph
{
    /** Chặng → khối vai trò. Các chặng liền nhau thuộc cùng khối gộp thành một khối. */
    private const GROUP_OF = [
        'guard' => 'prep', 'risk' => 'prep', 'sources' => 'sources',
        'synthesize' => 'strategy', 'brief' => 'strategy', 'image_design' => 'strategy', 'research' => 'strategy',
        'write' => 'write', 'repair' => 'write', 'edit' => 'review', 'grade' => 'review',
        'assemble' => 'finish', 'learn' => 'finish', 'handoff' => 'finish', 'checkpoint' => 'checkpoint',
    ];

    private const GROUP_TITLE = [
        'prep' => ['Kiểm tra đầu vào', 'Hệ thống'], 'sources' => ['Kho và nguồn', 'Điều phối kho'], 'strategy' => ['Chiến lược nội dung', 'Trưởng phòng chiến lược'],
        'write' => ['Viết', 'Người viết'], 'review' => ['Biên tập và chấm', 'Biên tập viên'], 'finish' => ['Hoàn tất', 'Hệ thống · Bạn'], 'checkpoint' => ['Chờ bạn', 'Bạn'],
        'images' => ['Tạo ảnh', 'Họa viên ảnh'],
    ];

    /**
     * @param  array<string, mixed>  $flow  kết quả RunFlow::build()
     * @param  Collection<int, mixed>  $steps  các bước của phiên
     * @return array{cards: list<array<string,mixed>>, back: list<array<string,mixed>>, trailing: list<array<string,mixed>>, next: bool}|null  null nếu không phải phiên viết bài
     */
    public static function trace(array $flow, Collection $steps, bool $live, ?string $current): ?array
    {
        $by = collect($flow['nodes'])->keyBy('code');
        if (! $by->has('write') || ! $by->has('images') || ! $by->has('grade')) {
            return null;
        }
        $durations = $flow['durations'] ?? [];

        $hops = [];
        $imageSteps = [];
        $pending = [];
        foreach ($steps->sortBy('seq') as $s) {
            if (in_array($s->stage, ['retry', 'failover'], true)) {
                continue;
            }
            if ($s->stage === 'decision') {
                $pending[] = self::decision($s);

                continue;
            }
            $code = RunFlow::segmentOf($s);
            if ($code === 'images') {
                $imageSteps[] = $s;

                continue;
            }
            $group = self::GROUP_OF[$code] ?? null;
            if ($group === null) {
                continue;
            }
            $last = $hops === [] ? null : array_key_last($hops);
            if ($last === null || $hops[$last]['group'] !== $group || $pending !== []) {
                $hops[] = ['group' => $group, 'steps' => [], 'decisions' => $pending];
                $pending = [];
                $last = array_key_last($hops);
            }
            $hops[$last]['steps'][$code][] = $s;
        }
        $trailing = $pending;

        $runningCode = $live ? $current : null;
        if ($runningCode !== null && isset(self::GROUP_OF[$runningCode])) {
            $g = self::GROUP_OF[$runningCode];
            $lastHop = $hops === [] ? null : array_key_last($hops);
            if ($lastHop === null || $hops[$lastHop]['group'] !== $g || $trailing !== []) {
                $hops[] = ['group' => $g, 'steps' => [], 'decisions' => $trailing];
                $trailing = [];
                $lastHop = array_key_last($hops);
            }
            $hops[$lastHop]['steps'][$runningCode] ??= [];
        }

        // Gộp theo khối: quay lại một khối đã có (vòng chấm ↔ sửa, bổ sung nguồn) KHÔNG tạo thẻ mới mà tăng số lần vào khối và vẽ thành mũi tên quay lại.
        $cards = [];
        $seq = [];
        $lastIndex = $hops === [] ? null : array_key_last($hops);
        foreach ($hops as $i => $h) {
            $g = $h['group'];
            $same = $seq !== [] && end($seq) === $g;
            if (! $same) {
                $seq[] = $g;
            }
            $cards[$g] ??= ['group' => $g, 'steps' => [], 'decisions' => [], 'visits' => 0];
            $cards[$g]['visits'] += $same ? 0 : 1;
            $cards[$g]['decisions'] = array_merge($cards[$g]['decisions'], $h['decisions']);
            foreach ($h['steps'] as $code => $list) {
                $cards[$g]['steps'][$code] = array_merge($cards[$g]['steps'][$code] ?? [], $list);
            }
        }
        $order = array_keys($cards);
        $pos = array_flip($order);
        $pairs = [];
        for ($i = 1; $i < count($seq); $i++) {
            $k = $seq[$i - 1].'>'.$seq[$i];
            $pairs[$k] = ($pairs[$k] ?? 0) + 1;
        }

        $anyRunning = false;
        $out = [];
        foreach ($cards as $g => $c) {
            $subs = [];
            foreach ($c['steps'] as $code => $list) {
                $running = $runningCode === $code && isset($hops[$lastIndex]['steps'][$code]) && $hops[$lastIndex]['group'] === $g;
                $subs[] = self::sub($by[$code], $list, $durations, $running);
                $anyRunning = $anyRunning || $running;
            }
            [$title, $role] = self::GROUP_TITLE[$g];
            if ($g === 'write' && isset($c['steps']['repair'])) {
                $title = 'Viết và sửa';
            }
            $out[] = ['group' => $g, 'title' => $title, 'role' => $role, 'subs' => $subs, 'state' => self::groupState($subs), 'visits' => $c['visits'], 'decisions' => $c['decisions'], 'side' => null,
                'in' => null];
        }
        // Đường vào mỗi thẻ (mũi tên dọc giữa hai thẻ liền nhau theo thứ tự xuất hiện): nhãn và số lần đi.
        foreach ($out as $i => &$card) {
            if ($i === 0) {
                continue;
            }
            $prevG = $out[$i - 1]['group'];
            $n = $pairs[$prevG.'>'.$card['group']] ?? 0;
            $label = $card['group'] === 'finish' ? 'đạt' : null;
            if ($n > 1) {
                $label = trim(($label ? $label.' · ' : '').'đã đi '.$n.' lần');
            }
            $card['in'] = ['label' => $label, 'count' => $n];
        }
        unset($card);

        // Mũi tên quay lại (từ khối sau về khối trước): vòng sửa, bổ sung nguồn...
        $back = [];
        foreach ($pairs as $k => $n) {
            [$from, $to] = explode('>', $k);
            if (($pos[$to] ?? 0) < ($pos[$from] ?? 0)) {
                $label = match (true) {
                    $from === 'review' && $to === 'write' => "chưa đạt → yêu cầu sửa · ↻ {$n} vòng",
                    $to === 'sources' => "thiếu nguồn → bổ sung · {$n} lần",
                    default => "quay lại · {$n} lần",
                };
                $back[] = ['from' => $from, 'to' => $to, 'label' => $label, 'count' => $n, 'kind' => $to === 'sources' ? 'back' : 'loop', 'lane' => count($back)];
            }
        }

        // Tạo ảnh song song: nằm cạnh khối Viết.
        if ($imageSteps !== []) {
            $sub = self::sub($by['images'], $imageSteps, $durations, false);
            $side = ['group' => 'images', 'title' => 'Tạo ảnh', 'role' => 'Họa viên ảnh', 'parallel' => (string) ($by['images']['parallel'] ?? 'Viết'), 'state' => self::groupState([$sub]), 'subs' => [$sub]];
            $wi = $pos['write'] ?? null;
            if ($wi !== null) {
                $out[$wi]['side'] = $side;
            } else {
                $out[] = ['group' => 'images', 'title' => 'Tạo ảnh', 'role' => 'Họa viên ảnh', 'subs' => [$sub], 'state' => $side['state'], 'visits' => 1, 'decisions' => [], 'side' => null, 'in' => ['label' => null, 'count' => 1]];
            }
        }

        return ['cards' => $out, 'back' => $back, 'trailing' => $trailing, 'next' => $live && ! $anyRunning && $out !== []];
    }

    /** @param list<mixed> $list @param array<int|string,float|int|null> $durations @return array<string,mixed> */
    private static function sub(array $node, array $list, array $durations, bool $running): array
    {
        $last = $list === [] ? null : end($list);
        $status = collect($last ? [$last] : [])->pluck('status'); // trạng thái là của LẦN CUỐI (các lần trước đã được sửa ở vòng sau)
        $state = match (true) {
            $running => 'running',
            $node['state'] === 'waiting' => 'waiting',
            $status->contains('failed') => 'failed',
            $status->contains('blocked') => 'blocked',
            $status->contains('warn') => 'warn',
            $last !== null && $status->every(fn ($x) => $x === 'skipped') => 'skipped',
            default => 'done',
        };
        $sec = $list === [] ? null : round((float) collect($list)->sum(fn ($x) => $durations[$x->id] ?? 0), 1);
        $summary = null;
        if ($last !== null) {
            $scores = collect($list)->map(fn ($x) => ((array) $x->data)['score'] ?? null)->filter(fn ($v) => $v !== null)->values();
            $summary = $scores->isNotEmpty() ? 'Điểm '.$scores->implode(' → ').'/100' : trim((string) $last->title);
            $d = trim(preg_replace('/\s+/u', ' ', strip_tags((string) $last->detail)) ?? '');
            if ($scores->isEmpty() && $d !== '') {
                $summary .= ' — '.mb_substr($d, 0, 110).(mb_strlen($d) > 110 ? '…' : '');
            } elseif ($scores->isNotEmpty() && $d !== '') {
                $summary .= '. '.mb_substr(preg_replace('/^Điểm \d+\/100 \([^)]*\)\.\s*/u', '', $d) ?? '', 0, 100);
            }
        }

        return ['code' => $node['code'], 'title' => $node['title'], 'kind' => $node['kind'], 'state' => $state, 'count' => count($list), 'seconds' => $sec, 'summary' => $summary];
    }

    /** @param list<array<string,mixed>> $subs */
    private static function groupState(array $subs): string
    {
        $states = array_column($subs, 'state');
        foreach (['running', 'waiting', 'failed', 'blocked', 'stale', 'warn'] as $s) {
            if (in_array($s, $states, true)) {
                return $s;
            }
        }

        return 'done';
    }

    /** @return array{title:string,detail:string,routes:list<string>,code:?string} */
    private static function decision($s): array
    {
        $d = (array) ($s->data ?? []);
        $routes = array_map(fn ($r) => match ($r) {
            'sources' => 'Kho và nguồn', 'writer' => 'Người viết', default => (string) $r,
        }, (array) ($d['routes'] ?? []));

        return ['title' => trim((string) preg_replace('/^Điều phối( kho)?:\s*/u', '', (string) $s->title)), 'detail' => mb_substr((string) $s->detail, 0, 260), 'routes' => $routes, 'code' => $d['decision'] ?? null];
    }
}
