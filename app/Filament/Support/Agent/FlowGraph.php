<?php

namespace App\Filament\Support\Agent;

use Illuminate\Support\Collection;

/**
 * Sơ đồ luồng của MỘT phiên viết bài theo mô hình ĐIỀU PHỐI Ở GIỮA: mọi việc đều đi qua người điều phối, các bộ phận không nối thẳng với nhau.
 * Mỗi lần điều phối GIAO việc cho một bộ phận là một đường (tím, đi ra), mỗi lần bộ phận BÁO KẾT QUẢ là một đường (xanh/cam, đi vào); tất cả đánh số theo thứ tự thật của phiên.
 * Chỉ bộ phận đã được giao việc mới có thẻ; quay lại cùng bộ phận (sửa, bổ sung nguồn, chấm lại) là thêm một cặp đường giao/báo chứ không thêm thẻ.
 * Tạo ảnh chạy song song nên lượt giao và báo của nó xen giữa lượt của Viết. Quyết định của điều phối ghi ở cột điều phối, kèm số của lượt giao việc mà nó dẫn tới.
 */
final class FlowGraph
{
    /** Chặng → bộ phận. Các chặng liền nhau cùng bộ phận gộp thành một lượt làm việc. */
    private const GROUP_OF = [
        'guard' => 'prep', 'risk' => 'prep', 'sources' => 'sources',
        'synthesize' => 'strategy', 'brief' => 'strategy', 'image_design' => 'strategy', 'research' => 'strategy',
        'write' => 'write', 'repair' => 'write', 'edit' => 'review', 'grade' => 'review',
        'assemble' => 'finish', 'learn' => 'finish', 'handoff' => 'finish', 'checkpoint' => 'checkpoint',
    ];

    /** Thứ tự thẻ từ trên xuống. */
    private const CARD_ORDER = ['prep', 'sources', 'strategy', 'write', 'images', 'review', 'finish', 'checkpoint'];

    private const GROUP_TITLE = [
        'prep' => ['Kiểm tra đầu vào', 'Hệ thống'], 'sources' => ['Kho và nguồn', 'Điều phối kho'], 'strategy' => ['Chiến lược nội dung', 'Trưởng phòng chiến lược'],
        'write' => ['Viết và sửa', 'Người viết'], 'review' => ['Biên tập và chấm', 'Biên tập viên'], 'finish' => ['Hoàn tất', 'Hệ thống · Bạn'], 'checkpoint' => ['Chờ bạn', 'Bạn'],
        'images' => ['Tạo ảnh', 'Họa viên ảnh'],
    ];

    /** Việc điều phối giao khi không có quyết định nào đi kèm. */
    private const GIVE = [
        'prep' => 'kiểm tra đầu vào', 'sources' => 'lấy nguồn và kiến thức', 'strategy' => 'lập chiến lược nội dung', 'write' => 'viết bài', 'images' => 'tạo ảnh (song song)',
        'review' => 'biên tập và chấm', 'finish' => 'lắp ráp và bàn giao', 'checkpoint' => 'chờ người xác nhận',
    ];

    /**
     * @param  array<string, mixed>  $flow  kết quả RunFlow::build()
     * @param  Collection<int, mixed>  $steps  các bước của phiên
     * @return array{cards: list<array<string,mixed>>, events: list<array<string,mixed>>, decisions: list<array<string,mixed>>, next: bool}|null  null nếu không phải phiên viết bài
     */
    public static function trace(array $flow, Collection $steps, bool $live, ?string $current): ?array
    {
        $by = collect($flow['nodes'])->keyBy('code');
        if (! $by->has('write') || ! $by->has('images') || ! $by->has('grade')) {
            return null;
        }
        $durations = $flow['durations'] ?? [];

        // 1. Các lượt làm việc: bước liền nhau cùng bộ phận là một lượt; có quyết định xen giữa thì tách lượt mới (điều phối vừa quyết định lại).
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
                $hops[] = ['group' => $group, 'steps' => [], 'decisions' => $pending, 'first' => $s->seq, 'last' => $s->seq, 'running' => false];
                $pending = [];
                $last = array_key_last($hops);
            }
            $hops[$last]['steps'][$code][] = $s;
            $hops[$last]['last'] = $s->seq;
        }
        $trailing = $pending;

        $runningCode = $live ? $current : null;
        $runGroup = $runningCode !== null ? (self::GROUP_OF[$runningCode] ?? ($runningCode === 'images' ? 'images' : null)) : null;
        if ($runGroup !== null && $runGroup !== 'images') {
            $lastHop = $hops === [] ? null : array_key_last($hops);
            if ($lastHop === null || $hops[$lastHop]['group'] !== $runGroup || $trailing !== []) {
                $hops[] = ['group' => $runGroup, 'steps' => [], 'decisions' => $trailing, 'first' => PHP_INT_MAX, 'last' => PHP_INT_MAX, 'running' => true];
                $trailing = [];
                $lastHop = array_key_last($hops);
            }
            $hops[$lastHop]['steps'][$runningCode] ??= [];
            $hops[$lastHop]['running'] = true;
        }
        if ($imageSteps !== []) { // Tạo ảnh chạy song song: một lượt riêng, xen giữa lượt Viết theo thời gian thật
            $hops[] = ['group' => 'images', 'steps' => ['images' => $imageSteps], 'decisions' => [], 'first' => $imageSteps[0]->seq, 'last' => end($imageSteps)->seq, 'running' => false];
        }

        // 2. Thẻ (mỗi bộ phận một thẻ) gom mọi lượt.
        $cards = [];
        foreach ($hops as $h) {
            $g = $h['group'];
            $cards[$g] ??= ['group' => $g, 'steps' => [], 'visits' => 0, 'running' => false];
            $cards[$g]['visits']++;
            $cards[$g]['running'] = $cards[$g]['running'] || $h['running'];
            foreach ($h['steps'] as $code => $list) {
                $cards[$g]['steps'][$code] = array_merge($cards[$g]['steps'][$code] ?? [], $list);
            }
        }

        // 3. Đường đi. Mặc định các bước NỐI THẲNG nhau (mũi tên giữa hai thẻ). Chỉ khi người điều phối ra quyết định giữa hai lượt thì mới đi qua điều phối:
        //    bộ phận trước BÁO kết quả cho điều phối, điều phối GIAO việc cho bộ phận sau. Mọi đường đánh số theo thứ tự thật của phiên.
        $info = [];
        foreach ($hops as $i => $h) {
            $subs = [];
            foreach ($h['steps'] as $code => $list) {
                $subs[] = self::sub($by[$code], $list, $durations, $h['running'] && $runningCode === $code);
            }
            $state = $h['running'] ? 'running' : self::groupState($subs);
            $info[$i] = ['state' => $state, 'report' => self::reportLabel($h['group'], $h['steps'], $state)];
        }
        $main = array_values(array_filter(array_keys($hops), fn ($i) => $hops[$i]['group'] !== 'images'));
        $hasImages = collect($hops)->contains('group', 'images');
        $events = [];
        $giveNo = [];
        $n = 0;
        $parDone = false;
        $joinDone = false;
        for ($k = 1; $k < count($main); $k++) {
            $ia = $main[$k - 1];
            $ib = $main[$k];
            $a = $hops[$ia];
            $b = $hops[$ib];
            if ($b['decisions'] !== []) { // có quyết định của điều phối giữa hai lượt: đi qua điều phối
                $events[] = ['n' => ++$n, 'card' => $a['group'], 'dir' => 'report', 'label' => $info[$ia]['report'], 'state' => $info[$ia]['state'], 'hop' => $ia];
                $events[] = ['n' => ++$n, 'card' => $b['group'], 'dir' => 'give', 'label' => self::short($b['decisions'][0]['title'], 28), 'state' => 'give', 'hop' => $ib];
                $giveNo[$ib] = $n;
            } else {
                $events[] = ['n' => ++$n, 'from' => $a['group'], 'to' => $b['group'], 'dir' => 'direct', 'label' => null, 'state' => $info[$ib]['state'], 'hop' => $ib];
            }
            if ($hasImages && ! $parDone && $b['group'] === 'write') { // Tạo ảnh bắt đầu cùng lúc với Viết
                $events[] = ['n' => ++$n, 'from' => 'write', 'to' => 'images', 'dir' => 'par', 'label' => 'chạy cùng lúc', 'state' => 'done', 'hop' => $ib];
                $parDone = true;
            }
            if ($hasImages && ! $joinDone && $b['group'] === 'finish') { // ảnh nhập vào bài khi lắp ráp
                $events[] = ['n' => ++$n, 'from' => 'images', 'to' => 'finish', 'dir' => 'join', 'label' => 'ảnh nhập vào bài', 'state' => 'done', 'hop' => $ib];
                $joinDone = true;
            }
        }

        // 4. Quyết định của điều phối, kèm số của lượt giao việc mà nó dẫn tới.
        $decisions = [];
        foreach ($hops as $i => $h) {
            foreach ($h['decisions'] as $d) {
                $decisions[] = ['n' => $giveNo[$i] ?? null, 'title' => self::short($d['title'], 90), 'full' => $d['title'].' — '.$d['detail'], 'routes' => $d['routes']];
            }
        }
        foreach ($trailing as $d) {
            $decisions[] = ['n' => null, 'title' => self::short($d['title'], 90), 'full' => $d['title'].' — '.$d['detail'], 'routes' => $d['routes']];
        }
        usort($decisions, fn ($a, $b) => ($a['n'] ?? PHP_INT_MAX) <=> ($b['n'] ?? PHP_INT_MAX));

        // 5. Thẻ ra dữ liệu hiển thị, đúng thứ tự trên xuống; mỗi thẻ đủ cao cho các đường đi qua nó.
        $slots = [];
        foreach ($events as $e) {
            if (isset($e['card'])) {
                $slots[$e['card']] = ($slots[$e['card']] ?? 0) + 1;
            }
        }
        $out = [];
        foreach (self::CARD_ORDER as $g) {
            if (! isset($cards[$g])) {
                continue;
            }
            $c = $cards[$g];
            $subs = [];
            foreach ($c['steps'] as $code => $list) {
                $subs[] = self::sub($by[$code], $list, $durations, $c['running'] && $runningCode === $code);
            }
            [$title, $role] = self::GROUP_TITLE[$g];
            if ($g === 'write' && ! isset($c['steps']['repair'])) {
                $title = 'Viết';
            }
            $out[] = ['group' => $g, 'title' => $title, 'role' => $role, 'subs' => $subs, 'state' => self::groupState($subs), 'visits' => $c['visits'],
                'minh' => 56 + ($slots[$g] ?? 0) * 22, 'parallel' => $g === 'images' ? (string) ($by['images']['parallel'] ?? 'Viết') : null];
        }

        $anyRunning = collect($hops)->contains('running', true);

        return ['cards' => $out, 'events' => $events, 'decisions' => $decisions, 'next' => $live && ! $anyRunning && $hops !== []];
    }

    /** Kết quả bộ phận báo lại, một cụm ngắn. @param array<string, list<mixed>> $steps */
    private static function reportLabel(string $group, array $steps, string $state): string
    {
        $word = match ($state) {
            'failed' => 'lỗi', 'blocked' => 'bị chặn', 'warn' => 'cần chú ý', 'waiting' => 'chờ bạn', default => 'xong',
        };
        if (isset($steps['grade'])) {
            $score = collect($steps['grade'])->map(fn ($x) => ((array) $x->data)['score'] ?? null)->filter(fn ($v) => $v !== null)->last();
            if ($score !== null) {
                return "điểm {$score}/100";
            }
        }
        if (isset($steps['repair'])) {
            return $word.' · đã sửa';
        }

        return $word;
    }

    private static function short(string $s, int $max = 60): string
    {
        $s = trim((string) preg_replace('/^Điều phối( kho)?:\s*/u', '', $s));

        return mb_strlen($s) > $max ? mb_substr($s, 0, $max - 1).'…' : $s;
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

        return ['title' => trim((string) $s->title), 'detail' => mb_substr((string) $s->detail, 0, 260), 'routes' => $routes, 'code' => $d['decision'] ?? null];
    }
}
