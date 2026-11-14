<?php

namespace App\Filament\Support\Agent;

use Illuminate\Support\Collection;

/**
 * Sơ đồ luồng của phiên viết bài vẽ như MỘT ĐỒ THỊ (không phải dãy ô nối tiếp): mỗi vai trò một làn, bước đặt theo thứ tự thật,
 * có nhánh song song (Viết ∥ Tạo ảnh) rồi nhập lại ở Lắp ráp, vòng Chấm ↔ Sửa, đường quay lại Kho và nguồn khi điều phối cho tìm thêm tài liệu.
 * Trạng thái từng đường lấy từ dữ liệu thật của phiên: đường chỉ "đã đi" khi cả hai đầu đã chạy; đường quay lại chỉ sáng khi điều phối thật sự đã yêu cầu.
 */
final class FlowGraph
{
    public const NODE_W = 104;

    public const NODE_H = 58;

    private const STEP = 112;

    private const LANE_H = 80;

    private const LEFT = 104;

    private const TOP = 14;

    /** Làn (hàng) theo vai trò. */
    public const LANES = ['Hệ thống', 'Kho và điều phối', 'Chiến lược', 'Họa viên ảnh', 'Người viết', 'Biên tập và chấm', 'Bạn'];

    /** Vị trí [cột, làn] của từng chặng; cột có thể lẻ để nhánh lệch nhau. */
    private const POS = [
        'guard' => [0, 0], 'risk' => [1, 0], 'sources' => [1.95, 1], 'synthesize' => [2.9, 2], 'brief' => [3.95, 2], 'image_design' => [4.9, 3],
        'research' => [5.85, 4], 'write' => [6.9, 4], 'images' => [6.9, 3], 'edit' => [7.85, 5], 'grade' => [8.9, 5], 'repair' => [8.9, 4],
        'assemble' => [9.95, 0], 'learn' => [11, 0], 'handoff' => [11, 6],
    ];

    /** Đường nối [từ, đến, nhãn, loại]. loại: main | fork | join | loop | back. */
    private const EDGES = [
        ['guard', 'risk', null, 'main'], ['risk', 'sources', null, 'main'], ['sources', 'synthesize', null, 'main'], ['synthesize', 'brief', null, 'main'],
        ['brief', 'image_design', null, 'main'], ['image_design', 'research', null, 'main'], ['research', 'write', null, 'main'],
        ['research', 'images', 'song song', 'fork'], ['write', 'edit', null, 'main'], ['edit', 'grade', null, 'main'],
        ['grade', 'repair', 'chưa đạt', 'loop'], ['repair', 'grade', 'chấm lại', 'loop'],
        ['grade', 'assemble', 'đạt', 'main'], ['images', 'assemble', 'nhập lại', 'join'],
        ['grade', 'sources', 'cần thêm nguồn', 'back'],
        ['assemble', 'learn', null, 'main'], ['learn', 'handoff', null, 'main'],
    ];

    /**
     * @param  list<array<string,mixed>>  $nodes  RunFlow::build()['nodes']
     * @param  Collection<int, mixed>  $steps
     * @return array{width:int,height:int,lanes:list<array{label:string,y:int}>,nodes:array<string,array<string,mixed>>,edges:list<array<string,mixed>>}|null
     */
    public static function build(array $nodes, Collection $steps): ?array
    {
        $by = collect($nodes)->keyBy('code');
        foreach (array_keys(self::POS) as $code) {
            if (! $by->has($code)) {
                return null; // loại việc khác (không phải viết bài): dùng lưới cũ
            }
        }
        $pos = self::POS;
        $edgeSpec = self::EDGES;
        if ($by->has('checkpoint')) { // điểm dừng chờ người (không phải phiên nào cũng có): nằm ở làn "Bạn", nối từ Xét rủi ro
            $pos['checkpoint'] = [2, 6];
            $edgeSpec[] = ['risk', 'checkpoint', 'chờ bạn', 'main'];
        }
        $ran = fn (string $c) => (int) ($by[$c]['count'] ?? 0) > 0;
        $moreSearch = $steps->contains(fn ($s) => $s->stage === 'decision' && (bool) (((array) $s->data)['search_more'] ?? false));

        $boxes = [];
        foreach ($pos as $code => [$col, $lane]) {
            $boxes[$code] = ['x' => (int) round(self::LEFT + $col * self::STEP), 'y' => self::TOP + $lane * self::LANE_H + (int) ((self::LANE_H - self::NODE_H) / 2) - 4, 'node' => $by[$code]];
        }

        $edges = [];
        foreach ($edgeSpec as [$a, $b, $label, $kind]) {
            $both = $ran($a) && $ran($b);
            $state = match (true) {
                $kind === 'back' => $moreSearch ? 'done' : 'idle',
                $kind === 'loop' => $ran('repair') ? 'loop' : 'idle',
                $both && ($by[$b]['state'] ?? '') === 'running' => 'live',
                $both => 'done',
                default => 'idle',
            };
            if ($kind === 'loop' && $label === 'chưa đạt' && $ran('repair')) {
                $label = '↻ '.$by['repair']['count'].' vòng';
            }
            $edges[] = ['from' => $a, 'to' => $b, 'label' => $label, 'kind' => $kind, 'state' => $state] + self::route($boxes, $a, $b, $kind);
        }

        $nodesOut = [];
        foreach ($boxes as $code => $b) {
            $nodesOut[$code] = ['x' => $b['x'], 'y' => $b['y']] + $b['node'];
        }
        $lanes = [];
        foreach (self::LANES as $i => $label) {
            $lanes[] = ['label' => $label, 'y' => self::TOP + $i * self::LANE_H - 4, 'h' => self::LANE_H];
        }

        return [
            'width' => (int) (self::LEFT + 11 * self::STEP + self::NODE_W + 16),
            'height' => (int) (self::TOP + count(self::LANES) * self::LANE_H + 26),
            'lanes' => $lanes,
            'nodes' => $nodesOut,
            'edges' => $edges,
        ];
    }

    /** Đường gấp khúc vuông góc giữa hai ô + vị trí đặt nhãn. @return array{d:string,lx:int,ly:int} */
    private static function route(array $boxes, string $a, string $b, string $kind): array
    {
        $w = self::NODE_W;
        $h = self::NODE_H;
        $A = $boxes[$a];
        $B = $boxes[$b];
        $ay = $A['y'] + $h / 2;
        $by = $B['y'] + $h / 2;

        if ($kind === 'loop') { // Chấm ↔ Sửa: hai đường dọc song song giữa hai ô
            $up = $a === 'grade';
            $x = $A['x'] + $w / 2 + ($up ? -14 : 14);
            $y1 = $up ? $A['y'] : $A['y'] + $h;
            $y2 = $up ? $B['y'] + $h : $B['y'];

            return ['d' => "M{$x} {$y1}V{$y2}", 'lx' => (int) ($A['x'] + $w / 2 + ($up ? -34 : 34)), 'ly' => (int) (($y1 + $y2) / 2) + 4];
        }
        if ($kind === 'back') { // từ đáy ô Chấm xuống dưới hàng, chạy ngược về cột của Kho và nguồn rồi đi lên
            $bottom = self::TOP + count(self::LANES) * self::LANE_H - 6;
            $x1 = $A['x'] + $w / 2;
            $x2 = $B['x'] + $w / 2;

            return ['d' => 'M'.$x1.' '.($A['y'] + $h)."V{$bottom}H{$x2}V".($B['y'] + $h), 'lx' => (int) (($x1 + $x2) / 2), 'ly' => $bottom - 6];
        }
        $x1 = $A['x'] + $w;
        $x2 = $B['x'];
        if ($kind === 'join') { // nhánh ảnh nhập lại: đi ngang rồi lên, vào mép trái ô Lắp ráp ở cao độ riêng để không chồng đường "đạt"
            $gx = $x2 - 22;

            return ['d' => "M{$x1} {$ay}H{$gx}V".($by + 8).'H'.$x2, 'lx' => (int) ($gx - 6), 'ly' => (int) (($ay + $by) / 2)];
        }
        if (abs($ay - $by) < 1) {
            return ['d' => "M{$x1} {$ay}H{$x2}", 'lx' => (int) (($x1 + $x2) / 2), 'ly' => (int) $ay - 6];
        }
        $gx = $x1 + max(8, (int) (($x2 - $x1) / 2));
        if ($x2 <= $x1) { // đích nằm sát/ngay dưới: đi từ đáy ô nguồn xuống đỉnh ô đích
            $sx = $A['x'] + $w / 2;
            $tx = $B['x'] + $w / 2;
            $y1 = $by > $ay ? $A['y'] + $h : $A['y'];
            $y2 = $by > $ay ? $B['y'] : $B['y'] + $h;

            return ['d' => "M{$sx} {$y1}V".(($y1 + $y2) / 2)."H{$tx}V{$y2}", 'lx' => (int) $tx, 'ly' => (int) (($y1 + $y2) / 2) - 4];
        }
        $dy = $kind === 'main' && $b === 'assemble' ? -8 : 0;

        return ['d' => "M{$x1} {$ay}H{$gx}V".($by + $dy)."H{$x2}", 'lx' => (int) $gx + 4, 'ly' => (int) (($ay + $by) / 2)];
    }
}
