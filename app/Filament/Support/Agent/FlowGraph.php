<?php

namespace App\Filament\Support\Agent;

use Illuminate\Support\Collection;

/**
 * Sơ đồ luồng của phiên viết bài, vẽ theo VIỆC CỦA TỪNG VAI TRÒ chứ không theo từng bước kỹ thuật:
 * - Chiến lược nội dung gồm Tổng hợp nguồn, Dàn ý, Yêu cầu và thiết kế ảnh, Nghiên cứu;
 * - Viết và sửa là một khối (sửa lại là phần việc của người viết), vòng lặp chạy giữa "Biên tập và chấm" và khối này;
 * - Tạo ảnh chạy song song với Viết rồi nhập lại ở Hoàn tất; đường "cần thêm nguồn" quay về Kho và nguồn.
 * Mỗi bước thật vẫn là một dòng bấm được trong khối (mở ngăn chi tiết riêng). Trạng thái đường nối lấy từ dữ liệu thật của phiên.
 */
final class FlowGraph
{
    public const W = 320;

    private const IMG_W = 230;

    private const HUBX = 16;

    private const HUB_W = 260;

    private const LEFT = 430; // lề trái của trục chính (bên trái là khối Điều phối)

    private const TOP = 30;

    private const GAP = 52;

    public const HEAD = 36;

    public const ROW = 30;

    private const PAD = 10;

    /** id => [tiêu đề, vai trò, các chặng]. Thứ tự là thứ tự dọc của trục chính. */
    private const GROUPS = [
        'prep' => ['Kiểm tra đầu vào', 'Hệ thống', ['guard', 'risk']],
        'sources' => ['Kho và nguồn', 'Điều phối kho', ['sources']],
        'strategy' => ['Chiến lược nội dung', 'Trưởng phòng chiến lược', ['synthesize', 'brief', 'image_design', 'research']],
        'write' => ['Viết và sửa', 'Người viết', ['write', 'repair']],
        'review' => ['Biên tập và chấm', 'Biên tập viên', ['edit', 'grade']],
        'finish' => ['Hoàn tất', 'Hệ thống · Bạn', ['assemble', 'learn', 'handoff']],
    ];

    /**
     * @param  list<array<string,mixed>>  $nodes  RunFlow::build()['nodes']
     * @param  Collection<int, mixed>  $steps
     * @return array{width:int,height:int,groups:list<array<string,mixed>>,edges:list<array<string,mixed>>}|null
     */
    public static function build(array $nodes, Collection $steps): ?array
    {
        $by = collect($nodes)->keyBy('code');
        foreach (self::GROUPS as [, , $codes]) {
            foreach ($codes as $c) {
                if (! $by->has($c)) {
                    return null; // loại việc khác (không phải viết bài): dùng lưới cũ
                }
            }
        }
        if (! $by->has('images')) {
            return null;
        }
        $groups = [];
        $y = self::TOP;
        foreach (self::GROUPS as $id => [$title, $role, $codes]) {
            $groups[$id] = self::group($id, $title, $role, array_map(fn ($c) => $by[$c], $codes), self::LEFT, $y, self::W);
            $y += $groups[$id]['h'] + self::GAP;
        }
        $mainBottom = $y - self::GAP;
        $groups['images'] = self::group('images', 'Tạo ảnh', 'Họa viên ảnh', [$by['images']], self::LEFT + self::W + 120, $groups['write']['y'], self::IMG_W);
        if ($by->has('checkpoint')) { // điểm dừng chờ người (không phải phiên nào cũng có): bên phải khối đầu vào
            $groups['checkpoint'] = self::group('checkpoint', 'Chờ bạn', 'Bạn', [$by['checkpoint']], self::LEFT + self::W + 120, $groups['prep']['y'], self::IMG_W);
        }

        // ĐIỀU PHỐI: trung tâm nhận lỗi từ chấm rồi gọi người làm tiếp (sửa, tìm thêm nguồn). Liệt kê quyết định THẬT của phiên này.
        $decisions = $steps->where('stage', 'decision')->sortBy('seq')->values();
        $items = $decisions->slice(-5)->map(fn ($d) => trim(preg_replace('/^Điều phối( kho)?:\s*/u', '', (string) $d->title)))->values()->all();
        $top = $groups['write']['y'];
        $groups['coord'] = ['id' => 'coord', 'title' => 'Điều phối', 'role' => 'Quyết định của phiên', 'x' => self::HUBX, 'y' => $top, 'w' => self::HUB_W,
            'h' => max($groups['review']['y'] + $groups['review']['h'] - $top, self::HEAD + max(1, count($items)) * self::ROW + self::PAD), 'state' => $decisions->isEmpty() ? 'idle' : 'done',
            'ran' => $decisions->isNotEmpty(), 'subs' => [], 'items' => $items === [] ? ['Chưa có quyết định nào trong phiên này'] : $items, 'parallel' => null, 'total' => $decisions->count()];

        $ran = fn (string $g) => $groups[$g]['ran'];
        $moreSearch = $steps->contains(fn ($s) => $s->stage === 'decision' && (bool) (((array) $s->data)['search_more'] ?? false));
        $repairs = (int) ($by['repair']['count'] ?? 0);
        $spec = [
            ['prep', 'sources', null, 'main'], ['sources', 'strategy', null, 'main'], ['strategy', 'write', null, 'main'], ['strategy', 'images', 'song song', 'fork'],
            ['write', 'review', null, 'main'], ['review', 'coord', $repairs > 0 || $moreSearch ? 'báo lỗi chấm' : 'nếu chưa đạt: báo lỗi', 'loop'],
            ['coord', 'write', $repairs > 0 ? "yêu cầu sửa · ↻ {$repairs} vòng" : 'yêu cầu sửa', 'loop'], ['coord', 'sources', 'bổ sung nguồn', 'back'],
            ['review', 'finish', 'đạt', 'main'], ['images', 'finish', 'nhập lại', 'join'],
        ];
        if ($by->has('checkpoint')) {
            $spec[] = ['prep', 'checkpoint', 'chờ bạn', 'side'];
        }
        $edges = [];
        foreach ($spec as [$a, $b, $label, $kind]) {
            $state = match (true) {
                $kind === 'back' => ($moreSearch || $decisions->contains(fn ($d) => (((array) $d->data)['decision'] ?? '') === 'kb_coordination')) ? 'done' : 'idle',
                $kind === 'loop' => ($repairs > 0 || $moreSearch) ? 'loop' : 'idle',
                $ran($a) && $ran($b) && $groups[$b]['state'] === 'running' => 'live',
                $ran($a) && $ran($b) => 'done',
                default => 'idle',
            };
            $edges[] = ['from' => $a, 'to' => $b, 'label' => $label, 'kind' => $kind, 'state' => $state] + self::route($groups[$a], $groups[$b], $kind);
        }

        return [
            'width' => self::LEFT + self::W + 120 + self::IMG_W + 16,
            'height' => $mainBottom + 24,
            'groups' => array_values($groups),
            'edges' => $edges,
        ];
    }

    /** @param list<array<string,mixed>> $subs @return array<string,mixed> */
    private static function group(string $id, string $title, string $role, array $subs, int $x, int $y, int $w): array
    {
        $states = array_column($subs, 'state');
        $state = 'done';
        foreach (['running', 'waiting', 'failed', 'blocked', 'stale', 'warn'] as $s) {
            if (in_array($s, $states, true)) {
                $state = $s;
                break;
            }
        }
        $ran = array_sum(array_column($subs, 'count')) > 0;
        if (! $ran && $state === 'done') {
            $state = in_array('pending', $states, true) ? 'pending' : 'idle';
        }

        return ['id' => $id, 'title' => $title, 'role' => $role, 'x' => $x, 'y' => $y, 'w' => $w, 'h' => self::HEAD + count($subs) * self::ROW + self::PAD, 'state' => $state, 'ran' => $ran, 'subs' => $subs,
            'parallel' => collect($subs)->pluck('parallel')->filter()->first()];
    }

    /** Đường gấp khúc vuông góc giữa hai khối + vị trí nhãn. @return array{d:string,lx:int,ly:int} */
    private static function route(array $A, array $B, string $kind): array
    {
        $acx = $A['x'] + $A['w'] / 2;
        $bcx = $B['x'] + $B['w'] / 2;

        if ($kind === 'loop') { // Biên tập và chấm → Điều phối (báo lỗi) và Điều phối → Viết và sửa (yêu cầu sửa): hai đường ngang vào/ra mép trái trục chính
            if ($A['id'] === 'review') {
                $y = $A['y'] + $A['h'] - 22;

                return ['d' => "M{$A['x']} {$y}H".($B['x'] + $B['w']), 'lx' => (int) (($A['x'] + $B['x'] + $B['w']) / 2), 'ly' => (int) $y - 14];
            }
            $y = $B['y'] + 26;

            return ['d' => 'M'.($A['x'] + $A['w'])." {$y}H{$B['x']}", 'lx' => (int) (($A['x'] + $A['w'] + $B['x']) / 2), 'ly' => (int) $y - 14];
        }
        if ($kind === 'back') { // Điều phối → Kho và nguồn: lên từ đỉnh khối điều phối rồi sang mép trái khối Kho và nguồn
            $yb = $B['y'] + $B['h'] / 2;

            return ['d' => 'M'.($A['x'] + $A['w'] / 2)." {$A['y']}V{$yb}H{$B['x']}", 'lx' => (int) (($A['x'] + $A['w'] / 2 + $B['x']) / 2), 'ly' => (int) $yb - 14];
        }
        if ($kind === 'fork') { // song song: từ mép phải khối chiến lược sang rồi xuống đỉnh khối ảnh
            $ya = $A['y'] + $A['h'] - 18;

            return ['d' => 'M'.($A['x'] + $A['w'])." {$ya}H{$bcx}V{$B['y']}", 'lx' => (int) (($A['x'] + $A['w'] + $B['x']) / 2) + 10, 'ly' => (int) $ya - 14];
        }
        if ($kind === 'join') { // nhập lại: từ đáy khối ảnh xuống ngang Hoàn tất rồi sang mép phải
            $yb = $B['y'] + 28;

            return ['d' => "M{$acx} ".($A['y'] + $A['h'])."V{$yb}H".($B['x'] + $B['w']), 'lx' => (int) $acx, 'ly' => (int) $yb - 14];
        }
        if ($kind === 'side') { // ngang sang phải
            $y = $A['y'] + 20;

            return ['d' => 'M'.($A['x'] + $A['w'])." {$y}H{$B['x']}", 'lx' => (int) (($A['x'] + $A['w'] + $B['x']) / 2), 'ly' => (int) $y - 13];
        }
        $y1 = $A['y'] + $A['h'];

        return ['d' => "M{$acx} {$y1}V{$B['y']}", 'lx' => (int) $acx + 30, 'ly' => (int) (($y1 + $B['y']) / 2)];
    }
}
