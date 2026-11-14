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
    public const NODE_W = 200;

    public const NODE_H = 88;

    private const COLW = 300;

    private const ROWH = 116;

    private const LEFT = 44;

    private const TOP = 16;

    /** Vị trí [cột, hàng]: trục chính chạy DỌC ở cột giữa; nhánh ảnh bên phải, "Sửa" bên trái ô Chấm. */
    private const POS = [
        'guard' => [1, 0], 'risk' => [1, 1], 'sources' => [1, 2], 'synthesize' => [1, 3], 'brief' => [1, 4], 'image_design' => [1, 5],
        'research' => [1, 6], 'write' => [1, 7], 'images' => [2, 7], 'edit' => [1, 8], 'grade' => [1, 9], 'repair' => [0, 9],
        'assemble' => [1, 10], 'learn' => [1, 11], 'handoff' => [1, 12],
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
            $pos['checkpoint'] = [2, 1];
            $edgeSpec[] = ['risk', 'checkpoint', 'chờ bạn', 'main'];
        }
        $ran = fn (string $c) => (int) ($by[$c]['count'] ?? 0) > 0;
        $moreSearch = $steps->contains(fn ($s) => $s->stage === 'decision' && (bool) (((array) $s->data)['search_more'] ?? false));

        $boxes = [];
        foreach ($pos as $code => [$col, $lane]) {
            $boxes[$code] = ['x' => self::LEFT + $col * self::COLW, 'y' => self::TOP + $lane * self::ROWH, 'node' => $by[$code]];
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
        foreach ($nodes as $n) { // theo thứ tự chặng của luồng (không theo vị trí vẽ) để thứ tự trong trang đúng với thứ tự chạy
            if (isset($boxes[$n['code']])) {
                $nodesOut[$n['code']] = ['x' => $boxes[$n['code']]['x'], 'y' => $boxes[$n['code']]['y']] + $n;
            }
        }
        return [
            'width' => self::LEFT + 2 * self::COLW + self::NODE_W + 16,
            'height' => self::TOP + 12 * self::ROWH + self::NODE_H + 14,
            'lanes' => [],
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
        $acx = $A['x'] + $w / 2;
        $bcx = $B['x'] + $w / 2;
        $amy = $A['y'] + $h / 2;
        $bmy = $B['y'] + $h / 2;

        if ($kind === 'loop') { // Chấm ↔ Sửa: hai đường ngang song song giữa hai ô, nhãn ở trên/dưới
            $up = $a === 'grade';
            $y = $amy + ($up ? -11 : 11);
            $x1 = $up ? $A['x'] : $A['x'] + $w;
            $x2 = $up ? $B['x'] + $w : $B['x'];

            return ['d' => "M{$x1} {$y}H{$x2}", 'lx' => (int) (($x1 + $x2) / 2), 'ly' => (int) ($up ? $y - 15 : $y + 15)];
        }
        if ($kind === 'back') { // từ ô Chấm đi lên khe giữa hai hàng, sang lề trái, lên ngang Kho và nguồn rồi vào từ bên trái
            $gapY = $A['y'] - (self::ROWH - $h) / 2;
            $x0 = self::LEFT - 26;

            return ['d' => 'M'.($acx - 40)." {$A['y']}V{$gapY}H{$x0}V{$bmy}H{$B['x']}", 'lx' => (int) ($x0 + 52), 'ly' => (int) $bmy - 14];
        }
        if ($kind === 'fork') { // nhánh song song: từ mép phải ô nguồn sang rồi xuống đỉnh ô đích
            $x1 = $A['x'] + $w;

            return ['d' => "M{$x1} {$amy}H{$bcx}V{$B['y']}", 'lx' => (int) (($x1 + $bcx) / 2) + 8, 'ly' => (int) $amy - 14];
        }
        if ($kind === 'join') { // nhánh ảnh nhập lại: từ đáy ô nguồn xuống ngang Lắp ráp rồi sang mép phải
            return ['d' => "M{$acx} ".($A['y'] + $h)."V{$bmy}H".($B['x'] + $w), 'lx' => (int) $acx, 'ly' => (int) $bmy - 14];
        }
        if (abs($A['y'] - $B['y']) < 1) { // cùng hàng (Xét rủi ro → Chờ bạn)
            $x1 = $A['x'] + $w;

            return ['d' => "M{$x1} {$amy}H{$B['x']}", 'lx' => (int) (($x1 + $B['x']) / 2), 'ly' => (int) $amy - 14];
        }
        $y1 = $A['y'] + $h; // trục dọc: từ đáy ô trên xuống đỉnh ô dưới
        $y2 = $B['y'];

        return ['d' => "M{$acx} {$y1}V{$y2}", 'lx' => (int) $acx + 28, 'ly' => (int) (($y1 + $y2) / 2)];
    }
}
