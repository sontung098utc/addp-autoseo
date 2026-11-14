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

    private const LEFT = 84;

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

        $ran = fn (string $g) => $groups[$g]['ran'];
        $moreSearch = $steps->contains(fn ($s) => $s->stage === 'decision' && (bool) (((array) $s->data)['search_more'] ?? false));
        $repairs = (int) ($by['repair']['count'] ?? 0);
        $spec = [
            ['prep', 'sources', null, 'main'], ['sources', 'strategy', null, 'main'], ['strategy', 'write', null, 'main'], ['strategy', 'images', 'song song', 'fork'],
            ['write', 'review', null, 'main'], ['review', 'write', $repairs > 0 ? "chưa đạt · ↻ {$repairs} vòng sửa" : 'chưa đạt thì sửa lại', 'loop'],
            ['review', 'finish', 'đạt', 'main'], ['images', 'finish', 'nhập lại', 'join'], ['review', 'sources', 'cần thêm nguồn', 'back'],
        ];
        if ($by->has('checkpoint')) {
            $spec[] = ['prep', 'checkpoint', 'chờ bạn', 'side'];
        }
        $edges = [];
        foreach ($spec as [$a, $b, $label, $kind]) {
            $state = match (true) {
                $kind === 'back' => $moreSearch ? 'done' : 'idle',
                $kind === 'loop' => $repairs > 0 ? 'loop' : 'idle',
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

        if ($kind === 'loop') { // Biên tập và chấm → Viết và sửa: đi vòng bên phải, vào mép phải khối Viết
            $x = $A['x'] + $A['w'] + 38;
            $ya = $A['y'] + $A['h'] / 2;
            $yb = $B['y'] + $B['h'] / 2;

            return ['d' => 'M'.($A['x'] + $A['w'])." {$ya}H{$x}V{$yb}H".($B['x'] + $B['w']), 'lx' => (int) $x, 'ly' => (int) (($ya + $yb) / 2)];
        }
        if ($kind === 'back') { // lên khe giữa hai khối, sang lề trái, lên ngang Kho và nguồn rồi vào từ bên trái
            $x0 = self::LEFT - 26;
            $gapY = $A['y'] - self::GAP / 2;
            $yb = $B['y'] + $B['h'] / 2;

            return ['d' => 'M'.($A['x'] + 40)." {$A['y']}V{$gapY}H{$x0}V{$yb}H{$B['x']}", 'lx' => (int) $x0 + 4, 'ly' => (int) (($gapY + $yb) / 2)];
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
